<?php

declare(strict_types=1);

namespace App\Modules\Bookings\Application;

use App\Modules\Bookings\Domain\Payment;
use App\Modules\Bookings\Infrastructure\CancellationRepository;
use App\Modules\Bookings\Infrastructure\PaymentRepository;
use App\Modules\Inventory\Application\InventoryReservationService;
use App\Shared\Audit\AuditTrail;
use App\Shared\Clock\ClockInterface;
use App\Shared\Database\TransactionManager;
use App\Shared\Money\Money;
use DateTimeImmutable;
use DateTimeZone;
use PDO;
use PDOException;
use RuntimeException;

final readonly class CancellationService
{
    public function __construct(
        private PDO $pdo,
        private CancellationRepository $cancellations,
        private PaymentRepository $payments,
        private CancellationFeeService $fees,
        private InventoryReservationService $inventory,
        private PaymentGateway $gateway,
        private TransactionManager $transactions,
        private AuditTrail $audit,
        private ClockInterface $clock,
    ) {
    }

    public function cancel(
        string $code,
        int $customerId,
        CancellationCommand $command,
        string $clientIp,
    ): CancellationReceipt {
        $code = $this->code($code);
        try {
            $state = $this->transactions->run(function () use ($code, $customerId, $command, $clientIp): array {
            $context = $this->cancellations->lockForCustomer($code, $customerId);
            if ($context === null) {
                throw new BookingException('BOOKING_NOT_FOUND', 'Không tìm thấy booking thuộc tài khoản hiện tại.');
            }
            $payment = $this->payments->lockByBookingId((int) $context['id']);
            if (!$payment instanceof Payment) {
                throw new BookingException('PAYMENT_NOT_FOUND', 'Booking thiếu payment record bắt buộc.');
            }
            $keyOwner = $this->cancellations->findByCancellationKey($command->idempotencyKey);
            if ($keyOwner !== null && $keyOwner['bookingId'] !== (int) $context['id']) {
                throw new BookingException('IDEMPOTENCY_CONFLICT', 'Khóa hủy đã được dùng cho thao tác khác.');
            }
            $fingerprint = $command->fingerprint((int) $context['id'], $customerId);
            if ($context['status'] === 'CANCELLED') {
                $this->assertReplay($context, $command, $fingerprint, $customerId);
                $expected = Money::vnd((string) ($context['calculation']['refundExpected'] ?? '0'));
                if ($payment->refundStatus === 'FAILED' && !$expected->isZero()) {
                    $this->payments->startRefund(
                        $payment->id,
                        $expected,
                        $this->refundKey($command->idempotencyKey),
                        'BOOKING_CANCELLATION',
                    );
                }
                return ['bookingId' => (int) $context['id'], 'expectedRefund' => $expected];
            }
            if ($context['status'] !== 'CONFIRMED') {
                throw new BookingException('BOOKING_NOT_CANCELLABLE', 'Booking hiện tại không thể hủy trực tiếp.');
            }
            $now = $this->clock->now();
            if ($now >= $this->cutoff($context)) {
                throw new BookingException('BOOKING_CUTOFF_PASSED', 'Đã đến hoặc qua thời điểm nhận phòng của cơ sở.');
            }
            $breakdown = $this->fees->calculate($context, $now);
            if (!$breakdown->fee->equals($command->expectedFee)) {
                throw new BookingException(
                    'CANCELLATION_QUOTE_CHANGED',
                    'Phí hủy đã thay đổi. Hãy xem lại báo giá mới trước khi xác nhận.',
                );
            }
            $this->assertPaymentState($context, $payment);
            $expectedRefund = $payment->paid->compareTo($breakdown->fee) > 0
                ? $payment->paid->subtract($breakdown->fee)
                : Money::vnd(0);
            $snapshot = [
                'version' => 1,
                'calculatedAt' => $now->format(DATE_ATOM),
                'timezone' => (string) $context['timezone'],
                'bookingTotal' => (string) $context['grandTotal'],
                'paidAmount' => $payment->paid->decimal(),
                'fee' => $breakdown->fee->decimal(),
                'refundExpected' => $expectedRefund->decimal(),
                'items' => $breakdown->items,
                'commandFingerprint' => $fingerprint,
            ];

            $released = $this->inventory->releaseActive((int) $context['id'], 'BOOKING_CANCELLED');
            $this->cancellations->markCancelled(
                (int) $context['id'],
                $command->idempotencyKey,
                $customerId,
                $command->reason,
                $breakdown->fee,
                $snapshot,
                $now,
            );
            if (!$expectedRefund->isZero()) {
                $this->payments->startRefund(
                    $payment->id,
                    $expectedRefund,
                    $this->refundKey($command->idempotencyKey),
                    'BOOKING_CANCELLATION',
                );
            }
            $this->audit->record(
                $customerId,
                'BOOKING_CANCELLED',
                'DatCho',
                (string) $context['id'],
                $command->idempotencyKey,
                ['status' => 'CONFIRMED', 'activeLedgerCount' => $released],
                ['status' => 'CANCELLED', ...$snapshot],
                $clientIp,
            );
            return ['bookingId' => (int) $context['id'], 'expectedRefund' => $expectedRefund];
            });
        } catch (PDOException $exception) {
            if ((string) ($exception->errorInfo[0] ?? $exception->getCode()) === '23000'
                && str_contains(strtolower($exception->getMessage()), 'huyidempotency')) {
                throw new BookingException('IDEMPOTENCY_CONFLICT', 'Khóa hủy đã được dùng cho thao tác khác.', $exception);
            }
            throw $exception;
        }

        $expectedRefund = $state['expectedRefund'];
        if (!$expectedRefund instanceof Money) {
            throw new RuntimeException('Trạng thái refund sau hủy không hợp lệ.');
        }
        if (!$expectedRefund->isZero()) {
            $this->processRefund(
                (int) $state['bookingId'],
                $expectedRefund,
                $command->idempotencyKey,
                $customerId,
                $clientIp,
            );
        }
        return $this->receipt((int) $state['bookingId']);
    }

    private function processRefund(
        int $bookingId,
        Money $expected,
        string $cancellationKey,
        int $actorId,
        string $clientIp,
    ): void {
        $payment = $this->payments->findByBookingId($bookingId);
        if (!$payment instanceof Payment || $payment->refundStatus === 'SUCCEEDED') {
            return;
        }
        if ($payment->refundStatus !== 'PENDING' || $payment->refundIdempotencyKey === null) {
            throw new BookingException('REFUND_STATE_INVALID', 'Refund không ở trạng thái có thể xử lý.');
        }
        if ($this->pdo->inTransaction()) {
            throw new RuntimeException('Refund gateway không được gọi khi PDO còn transaction.');
        }
        $result = $this->gateway->refund(new PaymentGatewayRequest(
            $bookingId,
            $expected,
            $payment->refundIdempotencyKey,
            $payment->providerReference,
        ));
        $this->transactions->run(function () use (
            $bookingId,
            $expected,
            $cancellationKey,
            $actorId,
            $clientIp,
            $result,
        ): void {
            $context = $this->cancellations->lockById($bookingId);
            $payment = $this->payments->lockByBookingId($bookingId);
            if ($context === null || !$payment instanceof Payment || $context['status'] !== 'CANCELLED') {
                throw new BookingException('REFUND_STATE_INVALID', 'Booking refund không còn hợp lệ.');
            }
            if ($payment->refundStatus !== 'PENDING') {
                return;
            }
            if ($result->status === PaymentGatewayResult::TIMEOUT) {
                $this->recordRefundAudit($actorId, $bookingId, $cancellationKey, 'TIMEOUT', $expected, $clientIp);
                return;
            }
            $amountMatches = $result->amount instanceof Money && $result->amount->equals($expected);
            if ($result->status === PaymentGatewayResult::SUCCESS && $amountMatches) {
                $this->payments->markRefundSucceeded(
                    $payment->id,
                    $expected,
                    (string) $result->providerReference,
                    $this->clock->now(),
                );
                $this->cancellations->markRefunded($bookingId, $expected);
                $this->recordRefundAudit($actorId, $bookingId, $cancellationKey, 'SUCCEEDED', $expected, $clientIp);
                return;
            }
            $this->payments->markRefundFailed($payment->id);
            $this->recordRefundAudit(
                $actorId,
                $bookingId,
                $cancellationKey,
                $result->status === PaymentGatewayResult::SUCCESS ? 'AMOUNT_MISMATCH' : 'FAILED',
                $expected,
                $clientIp,
            );
        });
    }

    private function recordRefundAudit(
        int $actorId,
        int $bookingId,
        string $cancellationKey,
        string $outcome,
        Money $expected,
        string $clientIp,
    ): void {
        $requestId = 'refund:' . $cancellationKey . ':' . strtolower($outcome);
        if ($this->audit->findByRequestId($requestId) !== null) {
            return;
        }
        $this->audit->record(
            $actorId,
            'BOOKING_REFUND_' . $outcome,
            'ThanhToan',
            (string) $bookingId,
            $requestId,
            null,
            ['expectedAmount' => $expected->decimal(), 'outcome' => $outcome],
            $clientIp,
        );
    }

    /** @param array<string, mixed> $context */
    private function assertReplay(
        array $context,
        CancellationCommand $command,
        string $fingerprint,
        int $customerId,
    ): void {
        if (($context['cancellationKey'] ?? null) !== $command->idempotencyKey
            || (int) ($context['cancelledBy'] ?? 0) !== $customerId
            || ($context['calculation']['commandFingerprint'] ?? null) !== $fingerprint) {
            throw new BookingException('IDEMPOTENCY_CONFLICT', 'Khóa hủy đã được dùng cho thao tác khác.');
        }
    }

    /** @param array<string, mixed> $context */
    private function assertPaymentState(array $context, Payment $payment): void
    {
        if ($context['paymentTiming'] === 'PAY_AT_PROPERTY'
            && ($payment->timing !== 'PAY_AT_PROPERTY' || $payment->status !== 'NOT_TRACKED')) {
            throw new BookingException('PAYMENT_STATE_INVALID', 'Payment tại cơ sở không nhất quán.');
        }
        if ($context['paymentTiming'] === 'PAY_ONLINE'
            && ($payment->timing !== 'PAY_ONLINE' || $payment->status !== 'PAID')) {
            throw new BookingException('PAYMENT_STATE_INVALID', 'Payment online chưa ở trạng thái đã trả.');
        }
    }

    /** @param array<string, mixed> $context */
    private function cutoff(array $context): DateTimeImmutable
    {
        return new DateTimeImmutable(
            (string) $context['checkIn'] . ' ' . (string) $context['checkInTime'],
            new DateTimeZone((string) $context['timezone']),
        );
    }

    private function receipt(int $bookingId): CancellationReceipt
    {
        $context = $this->transactions->run(fn (): ?array => $this->cancellations->lockById($bookingId));
        if ($context === null || $context['status'] !== 'CANCELLED') {
            throw new RuntimeException('Không thể đọc booking sau khi hủy.');
        }
        return new CancellationReceipt(
            (string) $context['code'],
            Money::vnd((string) $context['cancellationFee']),
            Money::vnd((string) ($context['calculation']['refundExpected'] ?? '0')),
            Money::vnd((string) $context['refundedAmount']),
            (string) $context['refundStatus'],
        );
    }

    private function refundKey(string $cancellationKey): string
    {
        $hex = hash('sha256', $cancellationKey . ':refund');
        return substr($hex, 0, 8) . '-' . substr($hex, 8, 4) . '-5' . substr($hex, 13, 3)
            . '-a' . substr($hex, 17, 3) . '-' . substr($hex, 20, 12);
    }

    private function code(string $code): string
    {
        $code = strtoupper(trim($code));
        if ($code === '' || strlen($code) > 24 || preg_match('/^[A-Z0-9-]+$/', $code) !== 1) {
            throw new BookingException('BOOKING_NOT_FOUND', 'Không tìm thấy booking thuộc tài khoản hiện tại.');
        }
        return $code;
    }
}

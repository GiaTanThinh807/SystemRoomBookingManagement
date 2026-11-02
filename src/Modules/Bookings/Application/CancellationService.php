<?php

declare(strict_types=1);

namespace App\Modules\Bookings\Application;

use App\Modules\Bookings\Domain\Payment;
use App\Modules\Bookings\Infrastructure\CancellationRepository;
use App\Modules\Bookings\Infrastructure\PaymentRepository;
use App\Shared\Clock\ClockInterface;
use App\Shared\Database\TransactionManager;
use App\Shared\Money\Money;
use DateTimeImmutable;
use DateTimeZone;
use PDOException;
use RuntimeException;

final readonly class CancellationService
{
    public function __construct(
        private CancellationRepository $cancellations,
        private PaymentRepository $payments,
        private CancellationFeeService $fees,
        private CancellationFinalizer $finalizer,
        private CancellationRefundService $refunds,
        private TransactionManager $transactions,
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
                            CancellationFinalizer::refundKey($command->idempotencyKey),
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
                $expectedRefund = $this->finalizer->finalize(
                    $context,
                    $payment,
                    $command->idempotencyKey,
                    $customerId,
                    $command->reason,
                    $breakdown->fee,
                    $breakdown->items,
                    $fingerprint,
                    'BOOKING_CANCELLED',
                    $clientIp,
                    $now,
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
            $this->refunds->process(
                (int) $state['bookingId'],
                $expectedRefund,
                $command->idempotencyKey,
                $customerId,
                $clientIp,
            );
        }
        return $this->receipt((int) $state['bookingId']);
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

    private function code(string $code): string
    {
        $code = strtoupper(trim($code));
        if ($code === '' || strlen($code) > 24 || preg_match('/^[A-Z0-9-]+$/', $code) !== 1) {
            throw new BookingException('BOOKING_NOT_FOUND', 'Không tìm thấy booking thuộc tài khoản hiện tại.');
        }
        return $code;
    }
}

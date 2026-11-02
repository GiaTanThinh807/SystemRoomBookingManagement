<?php

declare(strict_types=1);

namespace App\Modules\Bookings\Application;

use App\Modules\Bookings\Domain\Booking;
use App\Modules\Bookings\Domain\Payment;
use App\Modules\Bookings\Infrastructure\BookingRepository;
use App\Modules\Bookings\Infrastructure\PaymentRepository;
use App\Modules\Inventory\Application\InventoryReservationService;
use App\Shared\Audit\AuditTrail;
use App\Shared\Clock\ClockInterface;
use App\Shared\Database\TransactionManager;
use PDO;
use RuntimeException;

final readonly class OnlinePaymentService
{
    public function __construct(
        private PDO $pdo,
        private BookingRepository $bookings,
        private PaymentRepository $payments,
        private InventoryReservationService $inventory,
        private PaymentGateway $gateway,
        private TransactionManager $transactions,
        private AuditTrail $audit,
        private ClockInterface $clock,
    ) {
    }

    public function process(int $bookingId, string $requestId, string $clientIp, bool $retry = false): Booking
    {
        $payment = $this->payments->findByBookingId($bookingId);
        if (!$payment instanceof Payment) {
            throw new BookingException('PAYMENT_NOT_FOUND', 'Không tìm thấy payment online của booking.');
        }
        if ($payment->idempotencyKey === null) {
            throw new BookingException('PAYMENT_NOT_FOUND', 'Payment online thiếu idempotency key.');
        }
        if ($payment->status === 'PAID') {
            return $this->booking($bookingId);
        }
        if ($payment->status !== 'PROCESSING') {
            throw new BookingException('PAYMENT_FAILED', 'Thanh toán đã thất bại và booking không còn giữ phòng.');
        }
        if ($retry) {
            $this->transactions->run(function () use ($bookingId): void {
                $booking = $this->bookings->lockState($bookingId);
                $payment = $this->payments->lockByBookingId($bookingId);
                if (($booking['status'] ?? null) === 'PENDING_PAYMENT' && $payment?->status === 'PROCESSING') {
                    $this->payments->incrementAttempt($payment->id);
                }
            });
        }
        if ($this->pdo->inTransaction()) {
            throw new RuntimeException('Payment gateway không được gọi khi PDO còn transaction.');
        }
        $result = $this->gateway->charge(new PaymentGatewayRequest(
            $bookingId,
            $payment->total,
            $payment->idempotencyKey,
            $payment->providerReference,
        ));
        return $this->handleResult($bookingId, $result, $requestId, $clientIp);
    }

    public function handleResult(
        int $bookingId,
        PaymentGatewayResult $result,
        string $requestId,
        string $clientIp,
    ): Booking {
        if ($result->status === PaymentGatewayResult::TIMEOUT) {
            $this->transactions->run(function () use ($bookingId, $result): void {
                $booking = $this->bookings->lockState($bookingId);
                $payment = $this->payments->lockByBookingId($bookingId);
                if (($booking['status'] ?? null) === 'PENDING_PAYMENT' && $payment?->status === 'PROCESSING') {
                    $this->payments->markTimeout($payment->id, (string) $result->errorCode);
                }
            });
            return $this->booking($bookingId);
        }

        $latePayment = $this->transactions->run(function () use ($bookingId, $result, $requestId, $clientIp): ?Payment {
            $booking = $this->bookings->lockState($bookingId);
            $payment = $this->payments->lockByBookingId($bookingId);
            if ($booking === null || !$payment instanceof Payment) {
                throw new BookingException('PAYMENT_NOT_FOUND', 'Booking/payment không tồn tại.');
            }
            $now = $this->clock->now();
            if ($result->status === PaymentGatewayResult::SUCCESS) {
                if ($booking['status'] === 'CONFIRMED' && $payment->status === 'PAID') {
                    return null;
                }
                if ($booking['status'] === 'PENDING_PAYMENT' && $payment->status === 'PROCESSING'
                    && ($booking['paymentExpiresAt'] === null || new \DateTimeImmutable((string) $booking['paymentExpiresAt'], new \DateTimeZone('UTC')) > $now)) {
                    $this->payments->markPaid($payment->id, $payment->total, (string) $result->providerReference, $now);
                    $this->inventory->confirmActive($bookingId);
                    $this->bookings->markConfirmed($bookingId, $now);
                    $this->record($booking, 'PAYMENT_SUCCEEDED', $bookingId, $requestId, $clientIp, ['providerReference' => $result->providerReference]);
                    return null;
                }
                if ($booking['status'] === 'PENDING_PAYMENT' && $payment->status === 'PROCESSING') {
                    $this->failAndRelease($bookingId, $payment, 'PAYMENT_EXPIRED', $booking, $requestId, $clientIp);
                    $payment = $this->payments->lockByBookingId($bookingId);
                }
                if ($booking['status'] === 'PAYMENT_FAILED' || $payment?->status === 'FAILED') {
                    if ($payment->idempotencyKey === null) {
                        throw new BookingException('PAYMENT_NOT_FOUND', 'Payment online thiếu idempotency key.');
                    }
                    $refundKey = self::derivedUuid($payment->idempotencyKey, 'refund');
                    $captured = $this->payments->recordLateCapture($payment->id, $payment->total, (string) $result->providerReference, $refundKey);
                    if ($captured) {
                        $this->record($booking, 'PAYMENT_LATE_SUCCESS', $bookingId, $requestId, $clientIp, ['refundStatus' => 'PENDING']);
                    }
                    return $this->payments->lockByBookingId($bookingId);
                }
            }
            if ($result->status === PaymentGatewayResult::FAILED
                && $booking['status'] === 'PENDING_PAYMENT' && $payment->status === 'PROCESSING') {
                $this->failAndRelease($bookingId, $payment, (string) $result->errorCode, $booking, $requestId, $clientIp);
            }
            return null;
        });

        if ($latePayment instanceof Payment && $latePayment->refundStatus === 'PENDING') {
            if ($latePayment->idempotencyKey === null) {
                throw new BookingException('PAYMENT_NOT_FOUND', 'Payment online thiếu idempotency key.');
            }
            $refund = $this->gateway->refund(new PaymentGatewayRequest(
                $bookingId,
                $latePayment->paid,
                self::derivedUuid($latePayment->idempotencyKey, 'refund'),
                $latePayment->providerReference,
            ));
            $this->transactions->run(function () use ($bookingId, $refund): void {
                $this->bookings->lockState($bookingId);
                $payment = $this->payments->lockByBookingId($bookingId);
                if (!$payment instanceof Payment || $payment->refundStatus !== 'PENDING') {
                    return;
                }
                if ($refund->status === PaymentGatewayResult::SUCCESS) {
                    $this->payments->markRefundSucceeded($payment->id, $payment->paid, (string) $refund->providerReference, $this->clock->now());
                } elseif ($refund->status === PaymentGatewayResult::FAILED) {
                    $this->payments->markRefundFailed($payment->id);
                }
            });
        }
        return $this->booking($bookingId);
    }

    private function failAndRelease(int $bookingId, Payment $payment, string $error, array $booking, string $requestId, string $clientIp): void
    {
        $this->payments->markFailed($payment->id, $error, $this->clock->now());
        $this->bookings->markPaymentFailed($bookingId);
        $this->inventory->releaseActive($bookingId, 'PAYMENT_FAILED');
        $this->record($booking, 'PAYMENT_FAILED', $bookingId, $requestId, $clientIp, ['error' => $error]);
    }

    private function record(array $booking, string $action, int $bookingId, string $requestId, string $clientIp, array $after): void
    {
        $auditRequestId = substr(trim($requestId), 0, 52) . ':' . strtolower($action);
        $this->audit->record((int) ($booking['customerId'] ?? 0), $action, 'DatCho', (string) $bookingId, $auditRequestId, null, $after, $clientIp);
    }

    private function booking(int $bookingId): Booking
    {
        $booking = $this->bookings->findById($bookingId);
        if (!$booking instanceof Booking) {
            throw new RuntimeException('Không thể đọc booking sau xử lý payment.');
        }
        return $booking;
    }

    private static function derivedUuid(string $source, string $purpose): string
    {
        $hex = hash('sha256', $source . ':' . $purpose);
        return substr($hex, 0, 8) . '-' . substr($hex, 8, 4) . '-5' . substr($hex, 13, 3)
            . '-a' . substr($hex, 17, 3) . '-' . substr($hex, 20, 12);
    }
}

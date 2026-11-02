<?php

declare(strict_types=1);

namespace App\Modules\Bookings\Application;

use App\Modules\Bookings\Domain\Payment;
use App\Modules\Bookings\Infrastructure\CancellationRepository;
use App\Modules\Bookings\Infrastructure\PaymentRepository;
use App\Shared\Audit\AuditTrail;
use App\Shared\Clock\ClockInterface;
use App\Shared\Database\TransactionManager;
use App\Shared\Money\Money;
use PDO;
use RuntimeException;

final readonly class CancellationRefundService
{
    public function __construct(
        private PDO $pdo,
        private CancellationRepository $cancellations,
        private PaymentRepository $payments,
        private PaymentGateway $gateway,
        private TransactionManager $transactions,
        private AuditTrail $audit,
        private ClockInterface $clock,
    ) {
    }

    public function process(
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
                $this->record($actorId, $bookingId, $cancellationKey, 'TIMEOUT', $expected, $clientIp);
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
                $this->record($actorId, $bookingId, $cancellationKey, 'SUCCEEDED', $expected, $clientIp);
                return;
            }
            $this->payments->markRefundFailed($payment->id);
            $this->record(
                $actorId,
                $bookingId,
                $cancellationKey,
                $result->status === PaymentGatewayResult::SUCCESS ? 'AMOUNT_MISMATCH' : 'FAILED',
                $expected,
                $clientIp,
            );
        });
    }

    private function record(
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
}

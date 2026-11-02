<?php

declare(strict_types=1);

namespace App\Modules\Bookings\Application;

use App\Modules\Bookings\Domain\Payment;
use App\Modules\Bookings\Infrastructure\CancellationRepository;
use App\Modules\Bookings\Infrastructure\PaymentRepository;
use App\Modules\Inventory\Application\InventoryReservationService;
use App\Shared\Audit\AuditTrail;
use App\Shared\Money\Money;
use DateTimeImmutable;

final readonly class CancellationFinalizer
{
    public function __construct(
        private CancellationRepository $cancellations,
        private PaymentRepository $payments,
        private InventoryReservationService $inventory,
        private AuditTrail $audit,
    ) {
    }

    /** @param array<string, mixed> $context @param list<array<string, mixed>> $items */
    public function finalize(
        array $context,
        Payment $payment,
        string $cancellationKey,
        int $actorId,
        string $reason,
        Money $fee,
        array $items,
        string $fingerprint,
        string $auditAction,
        string $clientIp,
        DateTimeImmutable $at,
    ): Money {
        $this->assertPaymentState($context, $payment);
        $expectedRefund = $payment->paid->compareTo($fee) > 0
            ? $payment->paid->subtract($fee)
            : Money::vnd(0);
        $snapshot = [
            'version' => 1,
            'calculatedAt' => $at->format(DATE_ATOM),
            'timezone' => (string) $context['timezone'],
            'bookingTotal' => (string) $context['grandTotal'],
            'paidAmount' => $payment->paid->decimal(),
            'fee' => $fee->decimal(),
            'refundExpected' => $expectedRefund->decimal(),
            'items' => $items,
            'commandFingerprint' => $fingerprint,
        ];
        $released = $this->inventory->releaseActive((int) $context['id'], 'BOOKING_CANCELLED');
        $this->cancellations->markCancelled(
            (int) $context['id'],
            $cancellationKey,
            $actorId,
            $reason,
            $fee,
            $snapshot,
            $at,
        );
        if (!$expectedRefund->isZero()) {
            $this->payments->startRefund(
                $payment->id,
                $expectedRefund,
                self::refundKey($cancellationKey),
                'BOOKING_CANCELLATION',
            );
        }
        $this->audit->record(
            $actorId,
            $auditAction,
            'DatCho',
            (string) $context['id'],
            $cancellationKey,
            ['status' => 'CONFIRMED', 'activeLedgerCount' => $released],
            ['status' => 'CANCELLED', ...$snapshot],
            $clientIp,
        );
        return $expectedRefund;
    }

    public static function refundKey(string $cancellationKey): string
    {
        $hex = hash('sha256', $cancellationKey . ':refund');
        return substr($hex, 0, 8) . '-' . substr($hex, 8, 4) . '-5' . substr($hex, 13, 3)
            . '-a' . substr($hex, 17, 3) . '-' . substr($hex, 20, 12);
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
}

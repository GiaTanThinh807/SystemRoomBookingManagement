<?php

declare(strict_types=1);

namespace App\Modules\Bookings\Application;

use App\Modules\Bookings\Domain\Payment;
use App\Modules\Bookings\Infrastructure\BookingRepository;
use App\Modules\Bookings\Infrastructure\PaymentRepository;
use App\Modules\Inventory\Application\InventoryReservationService;
use App\Shared\Audit\AuditTrail;
use App\Shared\Clock\ClockInterface;
use App\Shared\Database\TransactionManager;

final readonly class PaymentExpiryService
{
    public function __construct(
        private BookingRepository $bookings,
        private PaymentRepository $payments,
        private InventoryReservationService $inventory,
        private TransactionManager $transactions,
        private AuditTrail $audit,
        private ClockInterface $clock,
    ) {
    }

    public function expireDue(int $limit = 100): int
    {
        $runId = 'payment-expire-' . bin2hex(random_bytes(12));
        $ids = $this->inventory->findExpiredBookingIds($this->clock->now(), $limit);
        $expired = 0;
        foreach ($ids as $bookingId) {
            $changed = $this->transactions->run(function () use ($bookingId, $runId): bool {
                $booking = $this->bookings->lockState($bookingId);
                $payment = $this->payments->lockByBookingId($bookingId);
                if ($booking === null || !$payment instanceof Payment
                    || $booking['status'] !== 'PENDING_PAYMENT' || $payment->status !== 'PROCESSING') {
                    return false;
                }
                $this->payments->markFailed($payment->id, 'PAYMENT_EXPIRED', $this->clock->now());
                $this->bookings->markPaymentFailed($bookingId);
                $this->inventory->releaseActive($bookingId, 'PAYMENT_EXPIRED');
                $this->audit->record(null, 'PAYMENT_EXPIRED', 'DatCho', (string) $bookingId, $runId . '-' . $bookingId, null, ['status' => 'PAYMENT_FAILED'], null);
                return true;
            });
            $expired += $changed ? 1 : 0;
        }
        return $expired;
    }
}

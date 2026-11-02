<?php

declare(strict_types=1);

namespace App\Modules\Bookings\Application;

use App\Modules\Bookings\Domain\Booking;
use App\Modules\Bookings\Infrastructure\BookingRepository;
use App\Modules\Bookings\Infrastructure\DateChangeRepository;
use App\Modules\Bookings\Infrastructure\PaymentRepository;
use App\Modules\Inventory\Application\InventoryDateChangeService;
use App\Shared\Audit\AuditTrail;
use App\Shared\Contract\StayPeriod;
use App\Shared\Database\TransactionManager;
use App\Shared\Json\CanonicalJson;
use DomainException;
use RuntimeException;

final readonly class DateChangeService
{
    public function __construct(
        private DateChangeRepository $dates,
        private BookingRepository $bookings,
        private PaymentRepository $payments,
        private DateChangeQuoteService $quotes,
        private InventoryDateChangeService $inventory,
        private TransactionManager $transactions,
        private AuditTrail $audit,
    ) {
    }

    public function change(
        string $code,
        int $customerId,
        DateChangeCommand $command,
        string $requestId,
        string $clientIp,
    ): Booking {
        $this->assertRequestId($requestId);
        $code = $this->code($code, $customerId);
        $requestFingerprint = CanonicalJson::hash([
            'code' => $code,
            'customerId' => $customerId,
            'stay' => $command->stay,
            'quoteFingerprint' => $command->quoteFingerprint,
        ]);

        $bookingId = $this->transactions->run(function () use (
            $code,
            $customerId,
            $command,
            $requestId,
            $requestFingerprint,
            $clientIp,
        ): int {
            $context = $this->dates->lockForCustomer($code, $customerId);
            if ($context === null) {
                throw new BookingException('BOOKING_NOT_FOUND', 'Không tìm thấy booking thuộc tài khoản hiện tại.');
            }
            $bookingId = (int) $context['id'];
            $payment = $this->payments->lockByBookingId($bookingId);
            if ($payment === null) {
                throw new RuntimeException('Booking không có payment để đổi ngày.');
            }

            $existing = $this->audit->findByRequestId($requestId);
            if ($existing !== null) {
                if ((int) ($existing['actorId'] ?? 0) !== $customerId
                    || ($existing['action'] ?? null) !== 'BOOKING_DATES_CHANGED'
                    || ($existing['entityId'] ?? null) !== (string) $bookingId
                    || ($existing['after']['requestFingerprint'] ?? null) !== $requestFingerprint) {
                    throw new BookingException('IDEMPOTENCY_CONFLICT', 'RequestID đã được dùng cho thao tác khác.');
                }
                return $bookingId;
            }

            $this->quotes->assertEligible($context);
            if ($payment->timing !== 'PAY_AT_PROPERTY' || $payment->status !== 'NOT_TRACKED'
                || !$payment->paid->isZero() || !$payment->refunded->isZero()) {
                throw new BookingException('DATE_CHANGE_NOT_ALLOWED', 'Payment hiện tại không cho phép đổi ngày.');
            }
            $quote = $this->quotes->calculate($context, $command->stay, true);
            if (!hash_equals($quote->fingerprint, $command->quoteFingerprint)) {
                throw new BookingException(
                    'DATE_CHANGE_QUOTE_STALE',
                    'Báo giá đã thay đổi. Vui lòng xem lại giá và xác nhận lại.',
                );
            }

            $oldStay = new StayPeriod((string) $context['checkIn'], (string) $context['checkOut']);
            try {
                $inventoryResult = $this->inventory->reschedule(
                    $bookingId,
                    $context['items'],
                    $oldStay,
                    $command->stay,
                );
            } catch (DomainException $exception) {
                throw new BookingException('INVENTORY_UNAVAILABLE', $exception->getMessage(), $exception);
            }

            $before = [
                'checkIn' => $oldStay->checkIn,
                'checkOut' => $oldStay->checkOut,
                'grandTotal' => (string) $context['grandTotal'],
                'paymentTotal' => $payment->total->decimal(),
            ];
            $this->dates->updateBooking($bookingId, $command->stay, $quote->newTotal);
            foreach ($quote->items as $item) {
                $this->dates->updateItemPricing((int) $item['id'], $item);
            }
            $this->payments->updatePayAtPropertyTotal($payment->id, $quote->newTotal);
            $after = [
                'checkIn' => $command->stay->checkIn,
                'checkOut' => $command->stay->checkOut,
                'grandTotal' => $quote->newTotal->decimal(),
                'paymentTotal' => $quote->newTotal->decimal(),
                'quoteFingerprint' => $quote->fingerprint,
                'requestFingerprint' => $requestFingerprint,
                'inventory' => $inventoryResult,
            ];
            $this->audit->record(
                $customerId,
                'BOOKING_DATES_CHANGED',
                'DatCho',
                (string) $bookingId,
                $requestId,
                $before,
                $after,
                $clientIp,
            );
            return $bookingId;
        });

        $booking = $this->bookings->findById($bookingId);
        if (!$booking instanceof Booking) {
            throw new RuntimeException('Không thể đọc booking sau khi đổi ngày.');
        }
        return $booking;
    }

    private function assertRequestId(string $requestId): void
    {
        if (preg_match('/^[A-Za-z0-9._-]{8,80}$/', $requestId) !== 1) {
            throw new BookingException('REQUEST_ID_INVALID', 'RequestID đổi ngày không hợp lệ.');
        }
    }

    private function code(string $code, int $customerId): string
    {
        $code = strtoupper(trim($code));
        if ($customerId < 1 || $code === '' || strlen($code) > 24 || preg_match('/^[A-Z0-9-]+$/', $code) !== 1) {
            throw new BookingException('BOOKING_NOT_FOUND', 'Không tìm thấy booking thuộc tài khoản hiện tại.');
        }
        return $code;
    }
}

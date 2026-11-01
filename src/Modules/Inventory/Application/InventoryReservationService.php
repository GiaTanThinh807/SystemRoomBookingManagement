<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Application;

use App\Shared\Contract\InventoryDemand;
use DateTimeImmutable;

interface InventoryReservationService
{
    /** @param list<InventoryDemand> $demands */
    public function lockAndAssertAvailable(array $demands): void;

    /** @param list<BookingInventoryItem> $items */
    public function holdForBooking(int $bookingId, array $items, ?DateTimeImmutable $expiresAt): void;

    /** Chuyển hold online đã thanh toán thành hold không còn thời hạn. */
    public function confirmActive(int $bookingId): int;

    /** @return int Số ledger vừa chuyển từ ACTIVE sang RELEASED. */
    public function releaseActive(int $bookingId, string $reason): int;

    /** @return list<int> */
    public function findExpiredBookingIds(DateTimeImmutable $at, int $limit = 100): array;
}

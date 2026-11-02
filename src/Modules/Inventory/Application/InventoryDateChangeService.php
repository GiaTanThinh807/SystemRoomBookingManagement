<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Application;

use App\Modules\Inventory\Infrastructure\InventoryRepository;
use App\Shared\Clock\ClockInterface;
use App\Shared\Contract\InventoryDemand;
use App\Shared\Contract\StayPeriod;
use DomainException;
use InvalidArgumentException;
use LogicException;
use PDO;

final readonly class InventoryDateChangeService
{
    public function __construct(
        private PDO $pdo,
        private InventoryRepository $inventory,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @param list<array<string, mixed>> $items
     * @return array{kept: int, released: int, activated: int}
     */
    public function reschedule(int $bookingId, array $items, StayPeriod $oldStay, StayPeriod $newStay): array
    {
        $this->assertTransaction();
        if ($bookingId < 1 || !array_is_list($items) || $items === []) {
            throw new InvalidArgumentException('Booking và hạng mục đổi ngày không hợp lệ.');
        }

        $holds = $this->inventory->lockAllHoldsForBooking($bookingId);
        $holdByItemDate = [];
        $activeKeys = [];
        foreach ($holds as $hold) {
            $key = self::itemDateKey((int) $hold['bookingItemId'], (string) $hold['stayDate']);
            $holdByItemDate[$key] = $hold;
            if ((string) $hold['status'] === 'ACTIVE') {
                $activeKeys[$key] = true;
            }
        }

        $expectedOldKeys = [];
        $oldCounts = [];
        $newCounts = [];
        foreach ($items as $item) {
            $itemId = (int) ($item['id'] ?? 0);
            $roomTypeId = (int) ($item['roomTypeId'] ?? 0);
            if ($itemId < 1 || $roomTypeId < 1) {
                throw new InvalidArgumentException('Hạng mục đổi ngày thiếu ID hoặc loại phòng.');
            }
            foreach ($oldStay->nights() as $date) {
                $itemKey = self::itemDateKey($itemId, $date);
                $expectedOldKeys[$itemKey] = true;
                $hold = $holdByItemDate[$itemKey] ?? null;
                if ($hold === null || (string) $hold['status'] !== 'ACTIVE'
                    || (int) $hold['roomTypeId'] !== $roomTypeId) {
                    throw new DomainException('Ledger active hiện tại không khớp booking tại ' . $itemKey . '.');
                }
                self::addCount($oldCounts, $roomTypeId, $date);
            }
            foreach ($newStay->nights() as $date) {
                self::addCount($newCounts, $roomTypeId, $date);
            }
        }
        if (count($activeKeys) !== count($expectedOldKeys)
            || array_diff_key($activeKeys, $expectedOldKeys) !== []
            || array_diff_key($expectedOldKeys, $activeKeys) !== []) {
            throw new DomainException('Booking có ledger active thừa hoặc thiếu so với kỳ lưu trú hiện tại.');
        }

        $allCounts = $oldCounts + $newCounts;
        foreach ($newCounts as $key => $value) {
            $allCounts[$key] = max($allCounts[$key] ?? 0, $value);
        }
        $lockDemands = self::demands($allCounts);
        $rows = $this->inventory->lockRows($lockDemands);
        $increments = [];
        $decrements = [];
        foreach ($allCounts as $key => $_quantity) {
            $row = $rows[$key] ?? null;
            if ($row === null) {
                throw new DomainException('Thiếu dữ liệu tồn phòng cho ngày đổi tại ' . $key . '.');
            }
            $delta = ($newCounts[$key] ?? 0) - ($oldCounts[$key] ?? 0);
            if ($delta > 0) {
                if ((int) $row['availableQuantity'] < $delta) {
                    throw new DomainException('Không đủ tồn phòng bổ sung tại ' . $key . '.');
                }
                $increments[$key] = $delta;
            } elseif ($delta < 0) {
                if ((int) $row['heldQuantity'] < -$delta) {
                    throw new DomainException('Counter tồn không đủ để trả tại ' . $key . '.');
                }
                $decrements[$key] = -$delta;
            }
        }

        $now = $this->clock->now();
        $releaseIds = [];
        $kept = 0;
        $activated = 0;
        foreach ($items as $item) {
            $itemId = (int) $item['id'];
            $roomTypeId = (int) $item['roomTypeId'];
            $oldDates = array_fill_keys($oldStay->nights(), true);
            $newDates = array_fill_keys($newStay->nights(), true);
            foreach ($oldDates as $date => $_) {
                $hold = $holdByItemDate[self::itemDateKey($itemId, $date)];
                if (isset($newDates[$date])) {
                    ++$kept;
                } else {
                    $releaseIds[] = (int) $hold['id'];
                }
            }
            foreach ($newDates as $date => $_) {
                if (isset($oldDates[$date])) {
                    continue;
                }
                $oldHold = $holdByItemDate[self::itemDateKey($itemId, $date)] ?? null;
                if ($oldHold !== null) {
                    if ((int) $oldHold['roomTypeId'] !== $roomTypeId || (string) $oldHold['status'] !== 'RELEASED') {
                        throw new DomainException('Ledger lịch sử không hợp lệ tại hạng mục/ngày mới.');
                    }
                    $this->inventory->reactivateHold((int) $oldHold['id'], $now);
                } else {
                    $this->inventory->insertActiveHold($itemId, $roomTypeId, $date, null, $now);
                }
                ++$activated;
            }
        }

        if ($releaseIds !== []) {
            $released = $this->inventory->releaseHolds($releaseIds, 'DATE_CHANGED', $now);
            if ($released !== count($releaseIds)) {
                throw new DomainException('Số ledger trả khi đổi ngày không khớp số đã khóa.');
            }
        }
        if ($increments !== []) {
            $this->inventory->incrementHeld(self::demands($increments));
        }
        if ($decrements !== []) {
            $this->inventory->decrementHeld(self::demands($decrements));
        }
        return ['kept' => $kept, 'released' => count($releaseIds), 'activated' => $activated];
    }

    /** @param array<string, int> $counts */
    private static function addCount(array &$counts, int $roomTypeId, string $date): void
    {
        $key = self::demandKey($roomTypeId, $date);
        $counts[$key] = ($counts[$key] ?? 0) + 1;
    }

    /** @param array<string, int> $counts @return list<InventoryDemand> */
    private static function demands(array $counts): array
    {
        $rows = [];
        foreach ($counts as $key => $quantity) {
            [$roomTypeId, $date] = explode(':', $key, 2);
            $rows[] = new InventoryDemand((int) $roomTypeId, $date, $quantity);
        }
        usort($rows, static fn (InventoryDemand $a, InventoryDemand $b): int =>
            [$a->roomTypeId, $a->date] <=> [$b->roomTypeId, $b->date]
        );
        return $rows;
    }

    private function assertTransaction(): void
    {
        if (!$this->pdo->inTransaction()) {
            throw new LogicException('Đổi ngày tồn phòng phải chạy trong transaction booking.');
        }
    }

    private static function demandKey(int $roomTypeId, string $date): string
    {
        return $roomTypeId . ':' . $date;
    }

    private static function itemDateKey(int $itemId, string $date): string
    {
        return $itemId . ':' . $date;
    }
}

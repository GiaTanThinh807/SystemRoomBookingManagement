<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Application;

use App\Modules\Inventory\Infrastructure\InventoryRepository;
use App\Shared\Clock\ClockInterface;
use App\Shared\Contract\InventoryDemand;
use DateTimeImmutable;
use DomainException;
use InvalidArgumentException;
use LogicException;
use PDO;

final readonly class TransactionalInventoryReservationService implements InventoryReservationService
{
    public function __construct(
        private PDO $pdo,
        private InventoryRepository $inventory,
        private ClockInterface $clock,
    ) {
    }

    public function lockAndAssertAvailable(array $demands): void
    {
        $this->assertTransaction();
        $aggregated = $this->aggregateDemands($demands);
        if ($aggregated === []) {
            throw new InvalidArgumentException('Nhu cầu giữ tồn không được rỗng.');
        }

        $rows = $this->inventory->lockRows($aggregated);
        foreach ($aggregated as $demand) {
            $key = self::demandKey($demand->roomTypeId, $demand->date);
            $row = $rows[$key] ?? null;
            if ($row === null) {
                throw new DomainException('Thiếu dữ liệu tồn cho loại phòng/ngày ' . $key . '.');
            }
            if ((int) $row['availableQuantity'] < $demand->quantity) {
                throw new DomainException('Không đủ tồn phòng cho loại phòng/ngày ' . $key . '.');
            }
        }
    }

    public function holdForBooking(int $bookingId, array $items, ?DateTimeImmutable $expiresAt): void
    {
        $this->assertTransaction();
        if ($bookingId < 1 || !array_is_list($items) || $items === []) {
            throw new InvalidArgumentException('Booking và danh sách hạng mục giữ tồn không hợp lệ.');
        }
        foreach ($items as $item) {
            if (!$item instanceof BookingInventoryItem) {
                throw new InvalidArgumentException('Hạng mục giữ tồn sai kiểu dữ liệu.');
            }
        }
        usort($items, static fn (BookingInventoryItem $a, BookingInventoryItem $b): int =>
            $a->bookingItemId <=> $b->bookingItemId
        );

        $this->inventory->assertBookingItemsEligible($bookingId, $items);
        $demands = [];
        foreach ($items as $item) {
            foreach ($item->stayDates as $date) {
                $demands[] = new InventoryDemand($item->roomTypeId, $date, 1);
            }
        }
        $aggregated = $this->aggregateDemands($demands);
        $this->lockAndAssertAvailable($aggregated);

        $heldAt = $this->clock->now();
        if ($expiresAt !== null && $expiresAt <= $heldAt) {
            throw new InvalidArgumentException('Hạn giữ tồn phải sau thời điểm giữ.');
        }
        foreach ($items as $item) {
            foreach ($item->stayDates as $date) {
                $this->inventory->insertActiveHold(
                    $item->bookingItemId,
                    $item->roomTypeId,
                    $date,
                    $expiresAt,
                    $heldAt,
                );
            }
        }
        $this->inventory->incrementHeld($aggregated);
    }

    public function releaseActive(int $bookingId, string $reason): int
    {
        $this->assertTransaction();
        if ($bookingId < 1 || preg_match('/^[A-Z][A-Z0-9_]{1,31}$/', $reason) !== 1) {
            throw new InvalidArgumentException('Booking hoặc lý do trả tồn không hợp lệ.');
        }

        $holds = $this->inventory->lockActiveHoldsForBooking($bookingId);
        if ($holds === []) {
            return 0;
        }
        $demands = [];
        foreach ($holds as $hold) {
            $demands[] = new InventoryDemand(
                (int) $hold['roomTypeId'],
                (string) $hold['stayDate'],
                1,
            );
        }
        $aggregated = $this->aggregateDemands($demands);
        $rows = $this->inventory->lockRows($aggregated);
        foreach ($aggregated as $demand) {
            $key = self::demandKey($demand->roomTypeId, $demand->date);
            if (!isset($rows[$key]) || (int) $rows[$key]['heldQuantity'] < $demand->quantity) {
                throw new DomainException('Counter tồn không đủ để trả các ledger đang active tại ' . $key . '.');
            }
        }

        $releasedAt = $this->clock->now();
        $released = $this->inventory->releaseHolds(
            array_map(static fn (array $hold): int => (int) $hold['id'], $holds),
            $reason,
            $releasedAt,
        );
        if ($released !== count($holds)) {
            throw new DomainException('Số ledger được trả không khớp số ledger đã khóa.');
        }
        $this->inventory->decrementHeld($aggregated);
        return $released;
    }

    public function confirmActive(int $bookingId): int
    {
        $this->assertTransaction();
        if ($bookingId < 1) {
            throw new InvalidArgumentException('Booking cần xác nhận giữ tồn không hợp lệ.');
        }

        $holds = $this->inventory->lockActiveHoldsForBooking($bookingId);
        if ($holds === []) {
            throw new DomainException('Booking không còn ledger active để xác nhận.');
        }

        $confirmed = $this->inventory->clearHoldExpiry(
            array_map(static fn (array $hold): int => (int) $hold['id'], $holds),
        );
        if ($confirmed !== count($holds)) {
            throw new DomainException('Số ledger được xác nhận không khớp số ledger đã khóa.');
        }
        return $confirmed;
    }

    public function findExpiredBookingIds(DateTimeImmutable $at, int $limit = 100): array
    {
        if ($limit < 1 || $limit > 1_000) {
            throw new InvalidArgumentException('Giới hạn booking hết hạn phải từ 1 đến 1000.');
        }
        return $this->inventory->findExpiredBookingIds($at, $limit);
    }

    /**
     * @param list<InventoryDemand> $demands
     * @return list<InventoryDemand>
     */
    private function aggregateDemands(array $demands): array
    {
        if (!array_is_list($demands)) {
            throw new InvalidArgumentException('Nhu cầu tồn phải là list.');
        }
        $aggregated = [];
        foreach ($demands as $demand) {
            if (!$demand instanceof InventoryDemand) {
                throw new InvalidArgumentException('Nhu cầu tồn sai kiểu dữ liệu.');
            }
            $key = self::demandKey($demand->roomTypeId, $demand->date);
            $quantity = ($aggregated[$key]['quantity'] ?? 0) + $demand->quantity;
            $aggregated[$key] = [
                'roomTypeId' => $demand->roomTypeId,
                'date' => $demand->date,
                'quantity' => $quantity,
            ];
        }
        uasort($aggregated, static fn (array $a, array $b): int =>
            [$a['roomTypeId'], $a['date']] <=> [$b['roomTypeId'], $b['date']]
        );
        return array_map(
            static fn (array $row): InventoryDemand => new InventoryDemand(
                $row['roomTypeId'], $row['date'], $row['quantity'],
            ),
            array_values($aggregated),
        );
    }

    private function assertTransaction(): void
    {
        if (!$this->pdo->inTransaction()) {
            throw new LogicException('Thao tác giữ/trả tồn phải chạy trong transaction của nghiệp vụ booking.');
        }
    }

    private static function demandKey(int $roomTypeId, string $date): string
    {
        return $roomTypeId . ':' . $date;
    }
}

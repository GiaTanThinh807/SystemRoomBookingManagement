<?php

declare(strict_types=1);

namespace Tests\Integration\Modules\Inventory;

use App\Bootstrap\App;
use App\Modules\Inventory\Application\InventoryDateChangeService;
use App\Modules\Inventory\Infrastructure\InventoryRepository;
use App\Shared\Clock\FrozenClock;
use App\Shared\Contract\StayPeriod;
use DateTimeImmutable;
use DomainException;
use PDO;
use Tests\Support\TestCase;

final class InventoryDateChangeServiceTest extends TestCase
{
    private PDO $pdo;
    /** @var array<string, mixed> */
    private array $booking;
    /** @var list<array<string, mixed>> */
    private array $items;

    protected function setUp(): void
    {
        parent::setUp();
        $this->pdo = App::boot(dirname(__DIR__, 4))->container()->get(PDO::class);
        $row = $this->pdo->query(
            "SELECT dc.DatChoID id, dc.NgayNhanPhong checkIn, dc.NgayTraPhong checkOut
             FROM DatCho dc INNER JOIN ThanhToan tt ON tt.DatChoID=dc.DatChoID
             WHERE dc.TrangThai='CONFIRMED' AND dc.ThoiDiemThanhToan='PAY_AT_PROPERTY'
               AND tt.TrangThai='NOT_TRACKED'
             ORDER BY dc.NgayNhanPhong DESC, dc.DatChoID DESC LIMIT 1"
        )->fetch(PDO::FETCH_ASSOC);
        self::assertIsArray($row);
        $this->booking = $row;
        $statement = $this->pdo->prepare(
            'SELECT HangMucDatChoID id, LoaiPhongID roomTypeId FROM HangMucDatCho WHERE DatChoID=? ORDER BY HangMucDatChoID'
        );
        $statement->execute([(int) $row['id']]);
        $this->items = $statement->fetchAll(PDO::FETCH_ASSOC);
        self::assertNotEmpty($this->items);
    }

    public function test_reschedule_keeps_overlap_and_can_reactivate_released_dates(): void
    {
        $oldStay = new StayPeriod((string) $this->booking['checkIn'], (string) $this->booking['checkOut']);
        $newStay = $this->shiftedStay(1);
        $beforeCounters = $this->counters($oldStay, $newStay);
        $service = $this->service();
        $this->pdo->beginTransaction();
        try {
            $outbound = $service->reschedule((int) $this->booking['id'], $this->items, $oldStay, $newStay);
            self::assertGreaterThan(0, $outbound['kept']);
            self::assertSame(count($this->items), $outbound['released']);
            self::assertSame(count($this->items), $outbound['activated']);
            self::assertSame($this->expectedActiveKeys($newStay), $this->activeKeys());

            $returned = $service->reschedule((int) $this->booking['id'], $this->items, $newStay, $oldStay);
            self::assertSame(count($this->items), $returned['released']);
            self::assertSame(count($this->items), $returned['activated']);
            self::assertSame($this->expectedActiveKeys($oldStay), $this->activeKeys());
            self::assertSame($beforeCounters, $this->counters($oldStay, $newStay));
        } finally {
            $this->pdo->rollBack();
        }
    }

    public function test_missing_inventory_row_rejects_without_partial_ledger_change(): void
    {
        $oldStay = new StayPeriod((string) $this->booking['checkIn'], (string) $this->booking['checkOut']);
        $newStay = $this->shiftedStay(1);
        $before = $this->activeKeys();
        $lastNight = $newStay->nights()[count($newStay->nights()) - 1];
        $this->pdo->beginTransaction();
        try {
            $delete = $this->pdo->prepare('DELETE FROM TonPhongNgay WHERE LoaiPhongID=? AND NgayLuuTru=?');
            $delete->execute([(int) $this->items[0]['roomTypeId'], $lastNight]);
            try {
                $this->service()->reschedule((int) $this->booking['id'], $this->items, $oldStay, $newStay);
                self::fail('Thiếu row tồn phải làm đổi ngày thất bại.');
            } catch (DomainException $exception) {
                self::assertStringContainsString('Thiếu dữ liệu tồn phòng', $exception->getMessage());
            }
            self::assertSame($before, $this->activeKeys());
        } finally {
            $this->pdo->rollBack();
        }
    }

    public function test_reschedule_supports_a_completely_non_overlapping_stay(): void
    {
        $oldStay = new StayPeriod((string) $this->booking['checkIn'], (string) $this->booking['checkOut']);
        $newStay = $this->shiftedStay(10);
        $this->pdo->beginTransaction();
        try {
            $result = $this->service()->reschedule(
                (int) $this->booking['id'],
                $this->items,
                $oldStay,
                $newStay,
            );
            self::assertSame(0, $result['kept']);
            self::assertSame(count($this->items) * count($oldStay->nights()), $result['released']);
            self::assertSame(count($this->items) * count($newStay->nights()), $result['activated']);
            self::assertSame($this->expectedActiveKeys($newStay), $this->activeKeys());
        } finally {
            $this->pdo->rollBack();
        }
    }

    private function service(): InventoryDateChangeService
    {
        return new InventoryDateChangeService(
            $this->pdo,
            new InventoryRepository($this->pdo),
            new FrozenClock(new DateTimeImmutable('2026-11-02T00:00:00+00:00')),
        );
    }

    private function shiftedStay(int $days): StayPeriod
    {
        return new StayPeriod(
            (new DateTimeImmutable((string) $this->booking['checkIn']))->modify('+' . $days . ' days')->format('Y-m-d'),
            (new DateTimeImmutable((string) $this->booking['checkOut']))->modify('+' . $days . ' days')->format('Y-m-d'),
        );
    }

    /** @return list<string> */
    private function activeKeys(): array
    {
        $statement = $this->pdo->prepare(
            "SELECT CONCAT(gt.HangMucDatChoID, ':', gt.NgayLuuTru)
             FROM GiuTonPhongDem gt INNER JOIN HangMucDatCho hm ON hm.HangMucDatChoID=gt.HangMucDatChoID
             WHERE hm.DatChoID=? AND gt.TrangThai='ACTIVE' ORDER BY gt.HangMucDatChoID, gt.NgayLuuTru"
        );
        $statement->execute([(int) $this->booking['id']]);
        return array_map('strval', $statement->fetchAll(PDO::FETCH_COLUMN));
    }

    /** @return list<string> */
    private function expectedActiveKeys(StayPeriod $stay): array
    {
        $keys = [];
        foreach ($this->items as $item) {
            foreach ($stay->nights() as $date) {
                $keys[] = (int) $item['id'] . ':' . $date;
            }
        }
        sort($keys, SORT_NATURAL);
        return $keys;
    }

    /** @return list<string> */
    private function counters(StayPeriod $first, StayPeriod $second): array
    {
        $dates = array_values(array_unique([...$first->nights(), ...$second->nights()]));
        sort($dates);
        $result = [];
        $statement = $this->pdo->prepare('SELECT SoLuongDaGiu FROM TonPhongNgay WHERE LoaiPhongID=? AND NgayLuuTru=?');
        foreach ($this->items as $item) {
            foreach ($dates as $date) {
                $statement->execute([(int) $item['roomTypeId'], $date]);
                $result[] = (int) $item['roomTypeId'] . ':' . $date . ':' . (string) $statement->fetchColumn();
            }
        }
        sort($result);
        return $result;
    }
}

<?php

declare(strict_types=1);

namespace Tests\Integration\Modules\Bookings;

use App\Bootstrap\App;
use App\Modules\Bookings\Application\BookingException;
use App\Modules\Bookings\Application\DateChangeCommand;
use App\Modules\Bookings\Application\DateChangeQuoteService;
use App\Modules\Bookings\Application\DateChangeService;
use App\Modules\Bookings\Infrastructure\BookingRepository;
use App\Modules\Bookings\Infrastructure\DateChangeRepository;
use App\Modules\Bookings\Infrastructure\PaymentRepository;
use App\Modules\Commercial\Application\StandardPriceCalculator;
use App\Modules\Inventory\Application\InventoryDateChangeService;
use App\Modules\Inventory\Infrastructure\InventoryRepository;
use App\Shared\Audit\DatabaseAuditTrail;
use App\Shared\Clock\FrozenClock;
use App\Shared\Contract\StayPeriod;
use App\Shared\Database\TransactionManager;
use DateTimeImmutable;
use DateTimeZone;
use PDO;
use Tests\Support\TestCase;

final class DateChangeServiceTest extends TestCase
{
    private PDO $pdo;
    private FrozenClock $clock;
    /** @var array<string, mixed> */
    private array $booking;
    /** @var list<array<string, mixed>> */
    private array $items;
    /** @var array<string, list<array<string, mixed>>> */
    private array $snapshots = [];
    /** @var list<string> */
    private array $requestIds = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->pdo = App::boot(dirname(__DIR__, 4))->container()->get(PDO::class);
        $row = $this->pdo->query(
            "SELECT dc.*, cs.GioNhanPhong testCheckInTime, cs.MuiGio testTimezone
             FROM DatCho dc
             INNER JOIN ThanhToan tt ON tt.DatChoID=dc.DatChoID
             INNER JOIN CoSoLuuTru cs ON cs.CoSoLuuTruID=dc.CoSoLuuTruID
             WHERE dc.TrangThai='CONFIRMED' AND dc.ThoiDiemThanhToan='PAY_AT_PROPERTY'
               AND tt.TrangThai='NOT_TRACKED'
             ORDER BY dc.NgayNhanPhong DESC, dc.DatChoID DESC LIMIT 1"
        )->fetch(PDO::FETCH_ASSOC);
        self::assertIsArray($row);
        $this->booking = $row;
        $this->items = $this->rows('SELECT * FROM HangMucDatCho WHERE DatChoID=? ORDER BY HangMucDatChoID', [(int) $row['DatChoID']]);
        self::assertNotEmpty($this->items);
        $itemIds = array_map(static fn (array $item): int => (int) $item['HangMucDatChoID'], $this->items);
        $this->snapshots['nights'] = $this->rows(
            'SELECT * FROM GiaDemDatCho WHERE HangMucDatChoID IN (' . implode(',', $itemIds) . ') ORDER BY HangMucDatChoID, NgayLuuTru'
        );
        $this->snapshots['holds'] = $this->rows(
            'SELECT gt.* FROM GiuTonPhongDem gt INNER JOIN HangMucDatCho hm ON hm.HangMucDatChoID=gt.HangMucDatChoID WHERE hm.DatChoID=? ORDER BY gt.GiuTonPhongDemID',
            [(int) $row['DatChoID']],
        );
        $this->snapshots['payment'] = $this->rows('SELECT * FROM ThanhToan WHERE DatChoID=?', [(int) $row['DatChoID']]);
        $old = $this->oldStay();
        $new = $this->shiftedStay(1);
        $roomTypes = array_values(array_unique(array_map(static fn (array $item): int => (int) $item['LoaiPhongID'], $this->items)));
        $dates = array_values(array_unique([...$old->nights(), ...$new->nights()]));
        $this->snapshots['inventory'] = $this->rows(
            'SELECT * FROM TonPhongNgay WHERE LoaiPhongID IN (' . implode(',', $roomTypes) . ") AND NgayLuuTru IN ('" . implode("','", $dates) . "')"
        );
        $this->clock = new FrozenClock($this->cutoff()->modify('-30 days'));
    }

    protected function tearDown(): void
    {
        if (isset($this->pdo, $this->booking)) {
            $bookingId = (int) $this->booking['DatChoID'];
            $itemIds = array_map(static fn (array $item): int => (int) $item['HangMucDatChoID'], $this->items);
            $this->pdo->beginTransaction();
            try {
                foreach ($this->requestIds as $requestId) {
                    $this->pdo->prepare('DELETE FROM NhatKyHeThong WHERE RequestID=?')->execute([$requestId]);
                }
                $this->pdo->prepare('DELETE gt FROM GiuTonPhongDem gt INNER JOIN HangMucDatCho hm ON hm.HangMucDatChoID=gt.HangMucDatChoID WHERE hm.DatChoID=?')->execute([$bookingId]);
                $this->insertRows('GiuTonPhongDem', $this->snapshots['holds']);
                $this->pdo->exec('DELETE FROM GiaDemDatCho WHERE HangMucDatChoID IN (' . implode(',', $itemIds) . ')');
                $this->insertRows('GiaDemDatCho', $this->snapshots['nights']);
                foreach ($this->items as $item) {
                    $this->restoreRow('HangMucDatCho', 'HangMucDatChoID', $item);
                }
                foreach ($this->snapshots['inventory'] as $inventory) {
                    $this->restoreCompositeRow('TonPhongNgay', ['LoaiPhongID', 'NgayLuuTru'], $inventory);
                }
                $this->restoreRow('ThanhToan', 'ThanhToanID', $this->snapshots['payment'][0]);
                $booking = $this->booking;
                unset($booking['testCheckInTime'], $booking['testTimezone']);
                $this->restoreRow('DatCho', 'DatChoID', $booking);
                $this->pdo->commit();
            } catch (\Throwable $exception) {
                $this->pdo->rollBack();
                throw $exception;
            }
        }
        parent::tearDown();
    }

    public function test_change_updates_all_snapshots_inventory_payment_and_is_idempotent(): void
    {
        $stay = $this->shiftedStay(1);
        $quote = $this->quoteService()->preview(
            (string) $this->booking['MaDatCho'],
            (int) $this->booking['KhachHangID'],
            $stay,
        );
        $command = new DateChangeCommand($stay, $quote->fingerprint);
        $requestId = $this->requestId('success');

        $changed = $this->service()->change(
            (string) $this->booking['MaDatCho'],
            (int) $this->booking['KhachHangID'],
            $command,
            $requestId,
            '127.0.0.1',
        );

        self::assertSame($stay->checkIn, $changed->checkIn);
        self::assertSame($stay->checkOut, $changed->checkOut);
        $paymentTotal = $this->scalar('SELECT TongPhaiTra FROM ThanhToan WHERE DatChoID=' . (int) $this->booking['DatChoID']);
        self::assertSame($changed->grandTotal->decimal(), $paymentTotal);
        self::assertSame($this->expectedActiveCount($stay), $this->activeCount());
        $audit = (new DatabaseAuditTrail($this->pdo))->findByRequestId($requestId);
        self::assertSame('BOOKING_DATES_CHANGED', $audit['action'] ?? null);
        self::assertSame($stay->checkIn, $audit['after']['checkIn'] ?? null);

        $this->clock->freeze($this->cutoff()->modify('+2 days'));
        $replayed = $this->service()->change(
            (string) $this->booking['MaDatCho'],
            (int) $this->booking['KhachHangID'],
            $command,
            $requestId,
            '127.0.0.1',
        );
        self::assertSame($changed->id, $replayed->id);
        self::assertSame(1, (int) $this->scalar("SELECT COUNT(*) FROM NhatKyHeThong WHERE RequestID='" . $requestId . "'"));
    }

    public function test_stale_quote_rolls_back_everything(): void
    {
        $stay = $this->shiftedStay(1);
        $before = $this->scalar('SELECT CONCAT(NgayNhanPhong,\'|\',NgayTraPhong,\'|\',TongTien) FROM DatCho WHERE DatChoID=' . (int) $this->booking['DatChoID']);
        $requestId = $this->requestId('stale');
        try {
            $this->service()->change(
                (string) $this->booking['MaDatCho'],
                (int) $this->booking['KhachHangID'],
                new DateChangeCommand($stay, str_repeat('a', 64)),
                $requestId,
                '127.0.0.1',
            );
            self::fail('Báo giá cũ phải bị từ chối.');
        } catch (BookingException $exception) {
            self::assertSame('DATE_CHANGE_QUOTE_STALE', $exception->errorCode);
        }
        self::assertSame($before, $this->scalar('SELECT CONCAT(NgayNhanPhong,\'|\',NgayTraPhong,\'|\',TongTien) FROM DatCho WHERE DatChoID=' . (int) $this->booking['DatChoID']));
        self::assertNull((new DatabaseAuditTrail($this->pdo))->findByRequestId($requestId));
    }

    private function service(): DateChangeService
    {
        $dates = new DateChangeRepository($this->pdo);
        return new DateChangeService(
            $dates,
            new BookingRepository($this->pdo),
            new PaymentRepository($this->pdo),
            $this->quoteService(),
            new InventoryDateChangeService($this->pdo, new InventoryRepository($this->pdo), $this->clock),
            new TransactionManager($this->pdo, static fn (int $_): null => null),
            new DatabaseAuditTrail($this->pdo),
        );
    }

    private function quoteService(): DateChangeQuoteService
    {
        return new DateChangeQuoteService(new DateChangeRepository($this->pdo), new StandardPriceCalculator(), $this->clock);
    }

    private function oldStay(): StayPeriod
    {
        return new StayPeriod((string) $this->booking['NgayNhanPhong'], (string) $this->booking['NgayTraPhong']);
    }

    private function shiftedStay(int $days): StayPeriod
    {
        return new StayPeriod(
            (new DateTimeImmutable((string) $this->booking['NgayNhanPhong']))->modify('+' . $days . ' days')->format('Y-m-d'),
            (new DateTimeImmutable((string) $this->booking['NgayTraPhong']))->modify('+' . $days . ' days')->format('Y-m-d'),
        );
    }

    private function cutoff(): DateTimeImmutable
    {
        return new DateTimeImmutable(
            (string) $this->booking['NgayNhanPhong'] . ' ' . (string) $this->booking['testCheckInTime'],
            new DateTimeZone((string) $this->booking['testTimezone']),
        );
    }

    private function requestId(string $suffix): string
    {
        $id = 'phase13-' . $suffix . '-' . bin2hex(random_bytes(5));
        $this->requestIds[] = $id;
        return $id;
    }

    private function expectedActiveCount(StayPeriod $stay): int
    {
        return count($this->items) * count($stay->nights());
    }

    private function activeCount(): int
    {
        return (int) $this->scalar(
            "SELECT COUNT(*) FROM GiuTonPhongDem gt INNER JOIN HangMucDatCho hm ON hm.HangMucDatChoID=gt.HangMucDatChoID WHERE hm.DatChoID="
            . (int) $this->booking['DatChoID'] . " AND gt.TrangThai='ACTIVE'",
        );
    }

    /** @param list<mixed> $parameters @return list<array<string, mixed>> */
    private function rows(string $sql, array $parameters = []): array
    {
        $statement = $this->pdo->prepare($sql);
        $statement->execute($parameters);
        return $statement->fetchAll(PDO::FETCH_ASSOC);
    }

    private function scalar(string $sql): string
    {
        return (string) $this->pdo->query($sql)->fetchColumn();
    }

    /** @param list<array<string, mixed>> $rows */
    private function insertRows(string $table, array $rows): void
    {
        foreach ($rows as $row) {
            $columns = array_keys($row);
            $sql = 'INSERT INTO `' . $table . '` (`' . implode('`,`', $columns) . '`) VALUES ('
                . implode(',', array_fill(0, count($columns), '?')) . ')';
            $this->pdo->prepare($sql)->execute(array_values($row));
        }
    }

    /** @param array<string, mixed> $row */
    private function restoreRow(string $table, string $primaryKey, array $row): void
    {
        $this->restoreCompositeRow($table, [$primaryKey], $row);
    }

    /** @param list<string> $primaryKeys @param array<string, mixed> $row */
    private function restoreCompositeRow(string $table, array $primaryKeys, array $row): void
    {
        $columns = array_keys($row);
        $assignments = implode(',', array_map(static fn (string $column): string => '`' . $column . '`=?', $columns));
        $where = implode(' AND ', array_map(static fn (string $column): string => '`' . $column . '`=?', $primaryKeys));
        $values = [...array_values($row), ...array_map(static fn (string $key): mixed => $row[$key], $primaryKeys)];
        $this->pdo->prepare('UPDATE `' . $table . '` SET ' . $assignments . ' WHERE ' . $where)->execute($values);
    }
}

<?php

declare(strict_types=1);

namespace Tests\Integration\Modules\Bookings;

use App\Bootstrap\App;
use App\Modules\Bookings\Application\PreviewService;
use App\Modules\Inventory\Application\InventoryReservationService;
use App\Modules\Search\Application\PropertyAllocationService;
use App\Modules\Search\Infrastructure\SearchCatalogRepository;
use App\Shared\Clock\ClockInterface;
use App\Shared\Contract\AllocationResult;
use App\Shared\Contract\RoomParty;
use App\Shared\Contract\SearchCriteria;
use App\Shared\Contract\StayPeriod;
use App\Shared\Database\TransactionManager;
use PDO;
use RuntimeException;
use Tests\Support\TestCase;

final class BookingConcurrencyTest extends TestCase
{
    private PDO $pdo;
    private PreviewService $previews;
    private InventoryReservationService $inventory;
    private TransactionManager $transactions;
    /** @var list<int> */
    private array $previewIds = [];
    /** @var list<int> */
    private array $bookingIds = [];
    /** @var list<array{roomTypeId: int, stayDate: string, total: int, held: int, version: int}> */
    private array $inventoryBefore = [];

    protected function setUp(): void
    {
        parent::setUp();
        $container = App::boot(dirname(__DIR__, 4))->container();
        $this->pdo = $container->get(PDO::class);
        $this->previews = $container->get(PreviewService::class);
        $this->inventory = $container->get(InventoryReservationService::class);
        $this->transactions = $container->get(TransactionManager::class);
    }

    protected function tearDown(): void
    {
        foreach (array_reverse(array_unique($this->bookingIds)) as $bookingId) {
            $this->transactions->run(function () use ($bookingId): void {
                $this->inventory->releaseActive($bookingId, 'TEST_CLEANUP');
                $this->pdo->exec('DELETE FROM NhatKyHeThong WHERE LoaiDoiTu = \'DatCho\' AND DoiTuID = ' . $bookingId);
                $this->pdo->exec('DELETE FROM ThanhToan WHERE DatChoID = ' . $bookingId);
                $this->pdo->exec('DELETE gt FROM GiuTonPhongDem gt INNER JOIN HangMucDatCho hm ON hm.HangMucDatChoID = gt.HangMucDatChoID WHERE hm.DatChoID = ' . $bookingId);
                $this->pdo->exec('DELETE gd FROM GiaDemDatCho gd INNER JOIN HangMucDatCho hm ON hm.HangMucDatChoID = gd.HangMucDatChoID WHERE hm.DatChoID = ' . $bookingId);
                $this->pdo->exec('DELETE FROM HangMucDatCho WHERE DatChoID = ' . $bookingId);
                $this->pdo->exec('DELETE FROM DatCho WHERE DatChoID = ' . $bookingId);
            });
        }
        foreach ($this->inventoryBefore as $row) {
            $this->pdo->prepare(
                'UPDATE TonPhongNgay SET TongSoLuong = ?, SoLuongDaGiu = ?, PhienBan = ? WHERE LoaiPhongID = ? AND NgayLuuTru = ?'
            )->execute([$row['total'], $row['held'], $row['version'], $row['roomTypeId'], $row['stayDate']]);
        }
        foreach (array_reverse($this->previewIds) as $previewId) {
            $this->pdo->prepare('DELETE FROM XemTruocDatCho WHERE XemTruocDatChoID = ?')->execute([$previewId]);
        }
        parent::tearDown();
    }

    public function test_two_booking_transactions_competing_for_last_unit_allow_only_one_winner(): void
    {
        [$property, $allocation] = $this->selection();
        foreach ($allocation->rooms as $room) {
            foreach ($room->priceBreakdown->nights as $night) {
                $this->limitToOneAvailable($room->roomTypeId, (string) $night['stayDate']);
            }
        }

        // Đọc lại allocation sau khi thay đổi version tồn để cả hai preview có cùng snapshot mới.
        [$property, $allocation] = $this->selection();
        $customerId = $this->customerId();
        $first = $this->previews->create(
            $customerId,
            $property->slug,
            $this->criteria(),
            'PAY_AT_PROPERTY',
            $allocation->selectionFingerprint,
            $this->mapping($allocation),
        );
        $second = $this->previews->create(
            $customerId,
            $property->slug,
            $this->criteria(),
            'PAY_AT_PROPERTY',
            $allocation->selectionFingerprint,
            $this->mapping($allocation),
        );
        $this->previewIds = [$first->preview->id, $second->preview->id];

        $results = $this->runConcurrently([
            [
                'previewToken' => $first->token,
                'customerId' => $customerId,
                'clientRequestId' => $this->uuid(),
                'requestId' => 'phase9-race-first',
            ],
            [
                'previewToken' => $second->token,
                'customerId' => $customerId,
                'clientRequestId' => $this->uuid(),
                'requestId' => 'phase9-race-second',
            ],
        ]);
        $winners = array_values(array_filter($results, static fn (array $row): bool => $row['success'] === true));
        self::assertCount(1, $winners);
        $this->bookingIds[] = (int) $winners[0]['bookingId'];
        $losers = array_values(array_filter($results, static fn (array $row): bool => $row['success'] === false));
        self::assertCount(1, $losers);
        self::assertContains($losers[0]['error'], ['PREVIEW_STALE', 'INVENTORY_UNAVAILABLE']);

        foreach ($this->inventoryBefore as $row) {
            $statement = $this->pdo->prepare(
                'SELECT TongSoLuong, SoLuongDaGiu FROM TonPhongNgay WHERE LoaiPhongID = ? AND NgayLuuTru = ?'
            );
            $statement->execute([$row['roomTypeId'], $row['stayDate']]);
            $current = $statement->fetch(PDO::FETCH_ASSOC);
            self::assertIsArray($current);
            self::assertSame($row['held'] + 1, (int) $current['SoLuongDaGiu']);
            self::assertSame((int) $current['SoLuongDaGiu'], $this->activeLedgerCount($row['roomTypeId'], $row['stayDate']));
        }
    }

    /** @return array{\App\Modules\Search\Application\PropertySearchSnapshot, AllocationResult} */
    private function selection(): array
    {
        $container = App::boot(dirname(__DIR__, 4))->container();
        $catalog = new SearchCatalogRepository($this->pdo);
        $allocations = $container->get(PropertyAllocationService::class);
        $clock = $container->get(ClockInterface::class);
        foreach ($catalog->findCandidates($this->criteria(), $clock->now()) as $property) {
            $allocation = $allocations->allocate($property, $this->criteria(), $clock->now(), 'PAY_AT_PROPERTY')[0] ?? null;
            if ($allocation !== null && count($allocation->rooms) === 1) {
                return [$property, $allocation];
            }
        }
        self::fail('Fixture phải có một allocation PAY_AT_PROPERTY một phòng.');
    }

    private function limitToOneAvailable(int $roomTypeId, string $stayDate): void
    {
        $statement = $this->pdo->prepare(
            'SELECT TongSoLuong, SoLuongDaGiu, PhienBan FROM TonPhongNgay WHERE LoaiPhongID = ? AND NgayLuuTru = ?'
        );
        $statement->execute([$roomTypeId, $stayDate]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        self::assertIsArray($row);
        $this->inventoryBefore[] = [
            'roomTypeId' => $roomTypeId,
            'stayDate' => $stayDate,
            'total' => (int) $row['TongSoLuong'],
            'held' => (int) $row['SoLuongDaGiu'],
            'version' => (int) $row['PhienBan'],
        ];
        $this->pdo->prepare(
            'UPDATE TonPhongNgay SET TongSoLuong = SoLuongDaGiu + 1, PhienBan = PhienBan + 1 WHERE LoaiPhongID = ? AND NgayLuuTru = ?'
        )->execute([$roomTypeId, $stayDate]);
    }

    /** @param list<array<string, int|string>> $jobs @return list<array<string, mixed>> */
    private function runConcurrently(array $jobs): array
    {
        $worker = dirname(__DIR__, 3) . '/Support/BookingConcurrencyWorker.php';
        $processes = [];
        foreach ($jobs as $payload) {
            $command = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($worker) . ' '
                . escapeshellarg(base64_encode(json_encode($payload, JSON_THROW_ON_ERROR)));
            $pipes = [];
            $process = proc_open($command, [
                0 => ['pipe', 'r'],
                1 => ['pipe', 'w'],
                2 => ['pipe', 'w'],
            ], $pipes, dirname(__DIR__, 4));
            if (!is_resource($process)) {
                throw new RuntimeException('Không thể khởi chạy worker booking concurrency.');
            }
            fclose($pipes[0]);
            $processes[] = [$process, $pipes];
        }
        $results = [];
        foreach ($processes as [$process, $pipes]) {
            $stdout = stream_get_contents($pipes[1]);
            $stderr = stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            $exitCode = proc_close($process);
            if ($exitCode !== 0 || $stderr !== '') {
                throw new RuntimeException('Worker booking concurrency lỗi: ' . trim($stderr));
            }
            $result = json_decode($stdout, true, flags: JSON_THROW_ON_ERROR);
            self::assertIsArray($result);
            $results[] = $result;
        }
        return $results;
    }

    private function activeLedgerCount(int $roomTypeId, string $stayDate): int
    {
        $statement = $this->pdo->prepare(
            "SELECT COUNT(*) FROM GiuTonPhongDem WHERE LoaiPhongID = ? AND NgayLuuTru = ? AND TrangThai = 'ACTIVE'"
        );
        $statement->execute([$roomTypeId, $stayDate]);
        return (int) $statement->fetchColumn();
    }

    private function criteria(): SearchCriteria
    {
        return new SearchCriteria('HCM', new StayPeriod('2026-12-10', '2026-12-12'), [new RoomParty(1, 2)]);
    }

    /** @return array<int, array{roomTypeId: int, productId: int}> */
    private function mapping(AllocationResult $allocation): array
    {
        $mapping = [];
        foreach ($allocation->rooms as $room) {
            $mapping[$room->roomOrder] = ['roomTypeId' => $room->roomTypeId, 'productId' => $room->productId];
        }
        return $mapping;
    }

    private function customerId(): int
    {
        return (int) $this->pdo->query(
            "SELECT NguoiDungID FROM NguoiDung WHERE VaiTro = 'CUSTOMER' AND TrangThai = 'HOAT_DONG' ORDER BY NguoiDungID LIMIT 1"
        )->fetchColumn();
    }

    private function uuid(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
        $hex = bin2hex($bytes);
        return sprintf('%s-%s-%s-%s-%s', substr($hex, 0, 8), substr($hex, 8, 4), substr($hex, 12, 4), substr($hex, 16, 4), substr($hex, 20));
    }
}

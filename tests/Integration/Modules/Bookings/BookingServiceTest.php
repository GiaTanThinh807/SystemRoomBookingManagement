<?php

declare(strict_types=1);

namespace Tests\Integration\Modules\Bookings;

use App\Bootstrap\App;
use App\Modules\Bookings\Application\BookingRequest;
use App\Modules\Bookings\Application\BookingException;
use App\Modules\Bookings\Application\BookingService;
use App\Modules\Bookings\Application\PreviewService;
use App\Modules\Bookings\Application\PreviewSnapshotFactory;
use App\Modules\Bookings\Infrastructure\BookingCodeGenerator;
use App\Modules\Bookings\Infrastructure\BookingRepository;
use App\Modules\Bookings\Infrastructure\PreviewRepository;
use App\Modules\Inventory\Application\TransactionalInventoryReservationService;
use App\Modules\Inventory\Infrastructure\InventoryRepository;
use App\Modules\Search\Application\PropertyAvailabilityService;
use App\Modules\Search\Infrastructure\SearchCatalogRepository;
use App\Shared\Audit\DatabaseAuditTrail;
use App\Shared\Clock\FrozenClock;
use App\Shared\Contract\AllocationResult;
use App\Shared\Contract\RoomParty;
use App\Shared\Contract\SearchCriteria;
use App\Shared\Contract\StayPeriod;
use App\Shared\Database\TransactionManager;
use App\Shared\Security\Hasher;
use App\Shared\Security\TokenGenerator;
use DateTimeImmutable;
use DateTimeZone;
use PDO;
use Tests\Support\TestCase;

final class BookingServiceTest extends TestCase
{
    private PDO $pdo;
    private FrozenClock $clock;
    private PreviewService $previews;
    private BookingService $bookings;
    private TransactionManager $transactions;
    private TransactionalInventoryReservationService $inventory;
    /** @var list<int> */
    private array $bookingIds = [];
    /** @var list<int> */
    private array $previewIds = [];

    protected function setUp(): void
    {
        parent::setUp();
        $container = App::boot(dirname(__DIR__, 4))->container();
        $this->pdo = $container->get(PDO::class);
        $this->clock = new FrozenClock(new DateTimeImmutable('2026-11-01 02:00:00', new DateTimeZone('UTC')));
        $catalog = new SearchCatalogRepository($this->pdo);
        $allocations = $container->get(\App\Modules\Search\Application\PropertyAllocationService::class);
        $availability = new PropertyAvailabilityService($catalog, $allocations, $this->clock);
        $snapshotFactory = new PreviewSnapshotFactory();
        $previewRepository = new PreviewRepository($this->pdo);
        $bookingRepository = new BookingRepository($this->pdo);
        $inventoryRepository = new InventoryRepository($this->pdo);
        $this->transactions = new TransactionManager($this->pdo, static fn (int $_): null => null);
        $this->inventory = new TransactionalInventoryReservationService(
            $this->pdo,
            $inventoryRepository,
            $this->clock,
        );
        $this->previews = new PreviewService(
            $availability,
            $snapshotFactory,
            $previewRepository,
            new TokenGenerator(),
            new Hasher(),
            $this->clock,
        );
        $this->bookings = new BookingService(
            $previewRepository,
            $bookingRepository,
            $availability,
            $snapshotFactory,
            $this->inventory,
            $this->transactions,
            new DatabaseAuditTrail($this->pdo),
            new BookingCodeGenerator(),
            new Hasher(),
            $this->clock,
        );
    }

    protected function tearDown(): void
    {
        foreach (array_reverse($this->bookingIds) as $bookingId) {
            $this->transactions->run(function () use ($bookingId): void {
                $this->inventory->releaseActive($bookingId, 'TEST_CLEANUP');
                $this->pdo->exec('DELETE FROM NhatKyHeThong WHERE LoaiDoiTu = \'DatCho\' AND DoiTuID = ' . $bookingId);
                $this->pdo->exec('DELETE FROM ThanhToan WHERE DatChoID = ' . $bookingId);
                $this->pdo->exec(
                    'DELETE gt FROM GiuTonPhongDem gt INNER JOIN HangMucDatCho hm ON hm.HangMucDatChoID = gt.HangMucDatChoID WHERE hm.DatChoID = ' . $bookingId
                );
                $this->pdo->exec(
                    'DELETE gd FROM GiaDemDatCho gd INNER JOIN HangMucDatCho hm ON hm.HangMucDatChoID = gd.HangMucDatChoID WHERE hm.DatChoID = ' . $bookingId
                );
                $this->pdo->exec('DELETE FROM HangMucDatCho WHERE DatChoID = ' . $bookingId);
                $this->pdo->exec('DELETE FROM DatCho WHERE DatChoID = ' . $bookingId);
            });
        }
        foreach (array_reverse($this->previewIds) as $previewId) {
            $statement = $this->pdo->prepare('DELETE FROM XemTruocDatCho WHERE XemTruocDatChoID = ?');
            $statement->execute([$previewId]);
        }
        parent::tearDown();
    }

    public function test_creates_confirmed_pay_at_property_booking_and_replays_same_request(): void
    {
        [$property, $allocation] = $this->selection();
        $customerId = $this->customerId();
        $createdPreview = $this->previews->create(
            $customerId,
            $property->slug,
            $this->criteria(),
            'PAY_AT_PROPERTY',
            $allocation->selectionFingerprint,
            $this->mapping($allocation),
        );
        $this->previewIds[] = $createdPreview->preview->id;
        $request = BookingRequest::fromInput(
            'a1b2c3d4-1234-4abc-8def-1234567890ab',
            'Nguyễn Văn Kiểm Thử',
            'booking-test@example.test',
            '0901234567',
            'Phòng yên tĩnh nếu có thể',
            true,
        );

        $first = $this->bookings->createPayAtProperty(
            $createdPreview->token,
            $customerId,
            $request,
            'phase9-booking-create',
            '127.0.0.1',
        );
        $this->bookingIds[] = $first->booking->id;
        $second = $this->bookings->createPayAtProperty(
            $createdPreview->token,
            $customerId,
            $request,
            'phase9-booking-retry',
            '127.0.0.1',
        );

        self::assertFalse($first->replayed);
        self::assertTrue($second->replayed);
        self::assertSame($first->booking->id, $second->booking->id);
        self::assertSame('CONFIRMED', $first->booking->status);
        self::assertSame('PAY_AT_PROPERTY', $first->booking->paymentTiming);
        self::assertSame($createdPreview->preview->grandTotal->decimal(), $first->booking->grandTotal->decimal());
        self::assertCount(count($allocation->rooms), $first->booking->items);
        self::assertSame(1, $this->rowCount('DatCho', 'ClientRequestID', $request->clientRequestId));
        self::assertSame(1, $this->rowCount('ThanhToan', 'DatChoID', (string) $first->booking->id));
        self::assertSame(
            'NOT_TRACKED',
            $this->pdo->query('SELECT TrangThai FROM ThanhToan WHERE DatChoID = ' . $first->booking->id)->fetchColumn(),
        );
        self::assertSame(
            'USED',
            $this->pdo->query('SELECT TrangThai FROM XemTruocDatCho WHERE XemTruocDatChoID = ' . $createdPreview->preview->id)->fetchColumn(),
        );
        $expectedLedger = count($allocation->rooms) * $this->criteria()->stayPeriod->nightCount();
        self::assertSame($expectedLedger, (int) $this->pdo->query(
            'SELECT COUNT(*) FROM GiuTonPhongDem gt INNER JOIN HangMucDatCho hm ON hm.HangMucDatChoID = gt.HangMucDatChoID WHERE hm.DatChoID = ' . $first->booking->id . " AND gt.TrangThai = 'ACTIVE'"
        )->fetchColumn());
    }

    public function test_creates_all_items_and_nights_for_multi_room_booking(): void
    {
        $criteria = new SearchCriteria(
            'HCM',
            new StayPeriod('2026-12-10', '2026-12-12'),
            [new RoomParty(1, 2), new RoomParty(2, 2, [7])],
        );
        [$property, $allocation] = $this->selection($criteria);
        $customerId = $this->customerId();
        $preview = $this->previews->create(
            $customerId,
            $property->slug,
            $criteria,
            'PAY_AT_PROPERTY',
            $allocation->selectionFingerprint,
            $this->mapping($allocation),
        );
        $this->previewIds[] = $preview->preview->id;

        $created = $this->bookings->createPayAtProperty(
            $preview->token,
            $customerId,
            $this->request('b1b2c3d4-1234-4abc-8def-1234567890ab'),
            'phase9-multi-room',
            '127.0.0.1',
        );
        $this->bookingIds[] = $created->booking->id;

        self::assertCount(2, $created->booking->items);
        self::assertSame([1, 2], array_column($created->booking->items, 'roomOrder'));
        foreach ($created->booking->items as $item) {
            self::assertCount(2, $item['nights']);
        }
        self::assertSame(4, (int) $this->pdo->query(
            'SELECT COUNT(*) FROM GiuTonPhongDem gt INNER JOIN HangMucDatCho hm ON hm.HangMucDatChoID = gt.HangMucDatChoID WHERE hm.DatChoID = ' . $created->booking->id
        )->fetchColumn());
    }

    public function test_rejects_second_request_for_used_preview_and_conflicting_idempotency_key(): void
    {
        [$property, $allocation] = $this->selection();
        $customerId = $this->customerId();
        $firstPreview = $this->previews->create(
            $customerId,
            $property->slug,
            $this->criteria(),
            'PAY_AT_PROPERTY',
            $allocation->selectionFingerprint,
            $this->mapping($allocation),
        );
        $this->previewIds[] = $firstPreview->preview->id;
        $request = $this->request('c1b2c3d4-1234-4abc-8def-1234567890ab');
        $created = $this->bookings->createPayAtProperty(
            $firstPreview->token,
            $customerId,
            $request,
            'phase9-first-request',
            '127.0.0.1',
        );
        $this->bookingIds[] = $created->booking->id;

        try {
            $this->bookings->createPayAtProperty(
                $firstPreview->token,
                $customerId,
                $this->request('c2b2c3d4-1234-4abc-8def-1234567890ab'),
                'phase9-preview-reuse',
                '127.0.0.1',
            );
            self::fail('Preview đã dùng không được tạo booking thứ hai.');
        } catch (BookingException $exception) {
            self::assertSame('PREVIEW_NOT_USABLE', $exception->errorCode);
        }

        [$secondProperty, $secondAllocation] = $this->selection();
        $secondPreview = $this->previews->create(
            $customerId,
            $secondProperty->slug,
            $this->criteria(),
            'PAY_AT_PROPERTY',
            $secondAllocation->selectionFingerprint,
            $this->mapping($secondAllocation),
        );
        $this->previewIds[] = $secondPreview->preview->id;
        try {
            $this->bookings->createPayAtProperty(
                $secondPreview->token,
                $customerId,
                $request,
                'phase9-idempotency-conflict',
                '127.0.0.1',
            );
            self::fail('Cùng ClientRequestID nhưng khác preview phải xung đột.');
        } catch (BookingException $exception) {
            self::assertSame('IDEMPOTENCY_CONFLICT', $exception->errorCode);
            self::assertStringNotContainsString($created->booking->code, $exception->getMessage());
            self::assertStringNotContainsString($request->bookerEmail, $exception->getMessage());
        }

        $otherCustomerId = (int) $this->pdo->query(
            "SELECT NguoiDungID FROM NguoiDung WHERE VaiTro = 'CUSTOMER' AND TrangThai = 'HOAT_DONG' ORDER BY NguoiDungID LIMIT 1 OFFSET 1"
        )->fetchColumn();
        $otherPreview = $this->previews->create(
            $otherCustomerId,
            $secondProperty->slug,
            $this->criteria(),
            'PAY_AT_PROPERTY',
            $secondAllocation->selectionFingerprint,
            $this->mapping($secondAllocation),
        );
        $this->previewIds[] = $otherPreview->preview->id;
        try {
            $this->bookings->createPayAtProperty(
                $otherPreview->token,
                $otherCustomerId,
                $request,
                'phase9-owner-conflict',
                '127.0.0.1',
            );
            self::fail('Cùng ClientRequestID của khách khác phải xung đột.');
        } catch (BookingException $exception) {
            self::assertSame('IDEMPOTENCY_CONFLICT', $exception->errorCode);
            self::assertStringNotContainsString($created->booking->code, $exception->getMessage());
            self::assertStringNotContainsString($created->booking->bookerEmail, $exception->getMessage());
        }
        self::assertSame(1, $this->rowCount('DatCho', 'ClientRequestID', $request->clientRequestId));
    }

    public function test_stale_preview_rolls_back_without_booking_payment_or_ledger(): void
    {
        [$property, $allocation] = $this->selection();
        $customerId = $this->customerId();
        $preview = $this->previews->create(
            $customerId,
            $property->slug,
            $this->criteria(),
            'PAY_AT_PROPERTY',
            $allocation->selectionFingerprint,
            $this->mapping($allocation),
        );
        $this->previewIds[] = $preview->preview->id;
        $roomTypeId = $allocation->rooms[0]->roomTypeId;
        $stayDate = $this->criteria()->stayPeriod->checkIn;
        $statement = $this->pdo->prepare(
            'SELECT TongSoLuong, SoLuongDaGiu, PhienBan FROM TonPhongNgay WHERE LoaiPhongID = ? AND NgayLuuTru = ?'
        );
        $statement->execute([$roomTypeId, $stayDate]);
        $inventoryBefore = $statement->fetch(PDO::FETCH_ASSOC);
        self::assertIsArray($inventoryBefore);
        $this->pdo->prepare(
            'UPDATE TonPhongNgay SET TongSoLuong = SoLuongDaGiu, PhienBan = PhienBan + 1 WHERE LoaiPhongID = ? AND NgayLuuTru = ?'
        )->execute([$roomTypeId, $stayDate]);
        $request = $this->request('d1b2c3d4-1234-4abc-8def-1234567890ab');

        try {
            try {
                $this->bookings->createPayAtProperty(
                    $preview->token,
                    $customerId,
                    $request,
                    'phase9-stale-preview',
                    '127.0.0.1',
                );
                self::fail('Preview stale không được tạo booking.');
            } catch (BookingException $exception) {
                self::assertSame('PREVIEW_STALE', $exception->errorCode);
            }
            self::assertSame(0, $this->rowCount('DatCho', 'ClientRequestID', $request->clientRequestId));
        } finally {
            $this->pdo->prepare(
                'UPDATE TonPhongNgay SET TongSoLuong = ?, SoLuongDaGiu = ?, PhienBan = ? WHERE LoaiPhongID = ? AND NgayLuuTru = ?'
            )->execute([
                $inventoryBefore['TongSoLuong'],
                $inventoryBefore['SoLuongDaGiu'],
                $inventoryBefore['PhienBan'],
                $roomTypeId,
                $stayDate,
            ]);
        }
    }

    public function test_booking_reads_saved_snapshot_after_catalog_name_changes(): void
    {
        [$property, $allocation] = $this->selection();
        $customerId = $this->customerId();
        $preview = $this->previews->create(
            $customerId,
            $property->slug,
            $this->criteria(),
            'PAY_AT_PROPERTY',
            $allocation->selectionFingerprint,
            $this->mapping($allocation),
        );
        $this->previewIds[] = $preview->preview->id;
        $created = $this->bookings->createPayAtProperty(
            $preview->token,
            $customerId,
            $this->request('e1b2c3d4-1234-4abc-8def-1234567890ab'),
            'phase9-snapshot',
            '127.0.0.1',
        );
        $this->bookingIds[] = $created->booking->id;
        $productId = (int) $created->booking->items[0]['productId'];
        $originalName = (string) $this->pdo->query(
            'SELECT TenSanPham FROM SanPhamPhong WHERE SanPhamPhongID = ' . $productId
        )->fetchColumn();
        $this->pdo->prepare('UPDATE SanPhamPhong SET TenSanPham = ? WHERE SanPhamPhongID = ?')
            ->execute(['Tên catalog đã đổi', $productId]);
        try {
            $loaded = $this->bookings->findConfirmation($created->booking->code, $customerId);
            self::assertSame($created->booking->items[0]['productName'], $loaded->items[0]['productName']);
            self::assertNotSame('Tên catalog đã đổi', $loaded->items[0]['productName']);
        } finally {
            $this->pdo->prepare('UPDATE SanPhamPhong SET TenSanPham = ? WHERE SanPhamPhongID = ?')
                ->execute([$originalName, $productId]);
        }
    }

    /** @return array{\App\Modules\Search\Application\PropertySearchSnapshot, AllocationResult} */
    private function selection(?SearchCriteria $criteria = null): array
    {
        $criteria ??= $this->criteria();
        $catalog = new SearchCatalogRepository($this->pdo);
        $container = App::boot(dirname(__DIR__, 4))->container();
        $allocations = $container->get(\App\Modules\Search\Application\PropertyAllocationService::class);
        foreach ($catalog->findCandidates($criteria, $this->clock->now()) as $property) {
            $allocation = $allocations->allocate($property, $criteria, $this->clock->now(), 'PAY_AT_PROPERTY')[0] ?? null;
            if ($allocation !== null) {
                return [$property, $allocation];
            }
        }
        self::fail('Fixture phải có allocation PAY_AT_PROPERTY để kiểm thử booking.');
    }

    private function criteria(): SearchCriteria
    {
        return new SearchCriteria(
            'HCM',
            new StayPeriod('2026-12-10', '2026-12-12'),
            [new RoomParty(1, 2, [7])],
        );
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

    private function request(string $clientRequestId): BookingRequest
    {
        return BookingRequest::fromInput(
            $clientRequestId,
            'Nguyễn Văn Kiểm Thử',
            'booking-test@example.test',
            '0901234567',
            null,
            true,
        );
    }

    private function rowCount(string $table, string $column, string $value): int
    {
        $statement = $this->pdo->prepare("SELECT COUNT(*) FROM {$table} WHERE {$column} = ?");
        $statement->execute([$value]);
        return (int) $statement->fetchColumn();
    }
}

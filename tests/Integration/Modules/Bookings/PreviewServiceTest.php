<?php

declare(strict_types=1);

namespace Tests\Integration\Modules\Bookings;

use App\Bootstrap\App;
use App\Modules\Bookings\Application\PreviewException;
use App\Modules\Bookings\Application\PreviewService;
use App\Modules\Bookings\Application\PreviewSnapshotFactory;
use App\Modules\Bookings\Domain\BookingPreview;
use App\Modules\Bookings\Infrastructure\PreviewRepository;
use App\Modules\Search\Application\PropertyAllocationService;
use App\Modules\Search\Application\PropertyAvailabilityService;
use App\Modules\Search\Infrastructure\SearchCatalogRepository;
use App\Shared\Clock\FrozenClock;
use App\Shared\Contract\AllocationResult;
use App\Shared\Contract\RoomParty;
use App\Shared\Contract\SearchCriteria;
use App\Shared\Contract\StayPeriod;
use App\Shared\Security\Hasher;
use App\Shared\Security\TokenGenerator;
use DateInterval;
use DateTimeImmutable;
use DateTimeZone;
use PDO;
use Tests\Support\TestCase;

final class PreviewServiceTest extends TestCase
{
    private PDO $pdo;
    private FrozenClock $clock;
    private SearchCatalogRepository $catalog;
    private PropertyAllocationService $allocations;
    private PreviewRepository $repository;
    private PreviewSnapshotFactory $snapshots;
    private PreviewService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $container = App::boot(dirname(__DIR__, 4))->container();
        $this->pdo = $container->get(PDO::class);
        $this->pdo->beginTransaction();
        $this->clock = new FrozenClock(new DateTimeImmutable('2026-11-01 02:00:00', new DateTimeZone('UTC')));
        $this->catalog = new SearchCatalogRepository($this->pdo);
        $this->allocations = $container->get(PropertyAllocationService::class);
        $this->repository = new PreviewRepository($this->pdo);
        $this->snapshots = new PreviewSnapshotFactory();
        $availability = new PropertyAvailabilityService($this->catalog, $this->allocations, $this->clock);
        $this->service = new PreviewService(
            $availability,
            $this->snapshots,
            $this->repository,
            new TokenGenerator(),
            new Hasher(),
            $this->clock,
        );
    }

    protected function tearDown(): void
    {
        if ($this->pdo->inTransaction()) {
            $this->pdo->rollBack();
        }
        parent::tearDown();
    }

    public function test_creates_fifteen_minute_preview_without_holding_inventory(): void
    {
        [$property, $allocation] = $this->selection();
        $beforeHeld = $this->heldTotal();
        $beforeLedger = $this->ledgerCount();

        $created = $this->service->create(
            $this->customerIds()[0],
            $property->slug,
            $this->criteria(),
            $allocation->paymentTiming,
            $allocation->selectionFingerprint,
            $this->mapping($allocation),
        );

        self::assertSame(900, $created->preview->expiresAt->getTimestamp() - $created->preview->createdAt->getTimestamp());
        self::assertSame($allocation->grandTotal->decimal(), $created->preview->grandTotal->decimal());
        self::assertSame($allocation->paymentTiming, $created->preview->allocationSnapshot['paymentTiming']);
        self::assertSame(1, $created->preview->allocationSnapshot['version']);
        self::assertNotEmpty($created->preview->allocationSnapshot['rooms'][0]['nightPrices']);
        self::assertArrayHasKey('cancellationSnapshot', $created->preview->allocationSnapshot['rooms'][0]);
        self::assertSame($beforeHeld, $this->heldTotal());
        self::assertSame($beforeLedger, $this->ledgerCount());

        $storedToken = $this->pdo->query(
            'SELECT TokenHash FROM XemTruocDatCho WHERE XemTruocDatChoID = ' . $created->preview->id
        )->fetchColumn();
        self::assertNotSame($created->token, $storedToken);
        self::assertSame(hash('sha256', $created->token), $storedToken);
    }

    public function test_price_version_change_creates_new_preview_with_warning_when_mapping_is_unchanged(): void
    {
        [$property, $allocation] = $this->selection();

        $created = $this->service->create(
            $this->customerIds()[0],
            $property->slug,
            $this->criteria(),
            $allocation->paymentTiming,
            str_repeat('f', 64),
            $this->mapping($allocation),
        );

        self::assertNotEmpty($created->preview->allocationSnapshot['warnings']);
        self::assertStringContainsString('thay đổi', $created->preview->allocationSnapshot['warnings'][0]);
    }

    public function test_checkout_accepts_only_current_preview_owned_by_customer(): void
    {
        $created = $this->createPreview();

        $loaded = $this->service->loadForCheckout($created->token, $this->customerIds()[0]);

        self::assertSame($created->preview->id, $loaded->id);
        self::assertSame(BookingPreview::STATUS_VALID, $loaded->status);

        try {
            $this->service->loadForCheckout($created->token, $this->customerIds()[1]);
            self::fail('Preview của khách khác phải bị từ chối.');
        } catch (PreviewException $exception) {
            self::assertSame('PREVIEW_NOT_USABLE', $exception->errorCode);
        }
    }

    public function test_checkout_rejects_expired_used_and_invalidated_preview(): void
    {
        $expired = $this->createPreview();
        $this->clock->advance(new DateInterval('PT16M'));
        $this->assertNotUsable($expired->token);

        $this->clock->freeze(new DateTimeImmutable('2026-11-01 02:00:00', new DateTimeZone('UTC')));
        $used = $this->createPreview();
        $statement = $this->pdo->prepare(
            "UPDATE XemTruocDatCho SET TrangThai = 'USED', DaDungLuc = :usedAt WHERE XemTruocDatChoID = :id"
        );
        $statement->execute(['usedAt' => '2026-11-01 02:01:00.000000', 'id' => $used->preview->id]);
        $this->assertNotUsable($used->token);

        $invalidated = $this->createPreview();
        $this->repository->invalidate($invalidated->preview->id);
        $this->assertNotUsable($invalidated->token);
    }

    public function test_checkout_invalidates_preview_when_rate_fingerprint_is_stale(): void
    {
        $created = $this->createPreview();
        $productId = (int) $created->preview->allocationSnapshot['rooms'][0]['productId'];
        $statement = $this->pdo->prepare(
            'UPDATE GiaPhongNgay SET GiaCoBan = GiaCoBan + 1000, PhienBan = PhienBan + 1
             WHERE SanPhamPhongID = :productId AND NgayLuuTru = :stayDate'
        );
        $statement->execute(['productId' => $productId, 'stayDate' => '2026-12-10']);

        try {
            $this->service->loadForCheckout($created->token, $this->customerIds()[0]);
            self::fail('Preview stale phải bị từ chối.');
        } catch (PreviewException $exception) {
            self::assertSame('PREVIEW_STALE', $exception->errorCode);
        }

        $stored = $this->repository->findByTokenHash(hash('sha256', $created->token));
        self::assertNotNull($stored);
        self::assertSame(BookingPreview::STATUS_INVALIDATED, $stored->status);
    }

    public function test_snapshot_factory_rejects_rooms_from_mixed_payment_timings(): void
    {
        $criteria = new SearchCriteria(
            'HCM',
            new StayPeriod('2026-12-10', '2026-12-12'),
            [new RoomParty(1, 2)],
        );
        $property = null;
        $atProperty = null;
        $online = null;
        foreach ($this->catalog->findCandidates($criteria, $this->clock->now()) as $candidate) {
            $candidateAtProperty = $this->allocations->allocate(
                $candidate,
                $criteria,
                $this->clock->now(),
                'PAY_AT_PROPERTY',
            )[0] ?? null;
            $candidateOnline = $this->allocations->allocate(
                $candidate,
                $criteria,
                $this->clock->now(),
                'PAY_ONLINE',
            )[0] ?? null;
            if ($atProperty === null && $candidateAtProperty !== null) {
                $property = $candidate;
                $atProperty = $candidateAtProperty;
            }
            if ($online === null && $candidateOnline !== null) {
                $online = $candidateOnline;
            }
            if ($property !== null && $atProperty !== null && $online !== null) {
                break;
            }
        }
        self::assertNotNull($property);
        self::assertNotNull($atProperty);
        self::assertNotNull($online);

        try {
            $this->snapshots->create(
                $property,
                $criteria,
                [$atProperty->rooms[0], $online->rooms[0]],
                'PAY_AT_PROPERTY',
                $atProperty->selectionFingerprint,
            );
            self::fail('Snapshot timing hỗn hợp phải bị từ chối.');
        } catch (PreviewException $exception) {
            self::assertSame('MIXED_PAYMENT_TIMING', $exception->errorCode);
        }
    }

    private function createPreview(): \App\Modules\Bookings\Application\CreatedPreview
    {
        [$property, $allocation] = $this->selection();
        return $this->service->create(
            $this->customerIds()[0],
            $property->slug,
            $this->criteria(),
            $allocation->paymentTiming,
            $allocation->selectionFingerprint,
            $this->mapping($allocation),
        );
    }

    /** @return array{\App\Modules\Search\Application\PropertySearchSnapshot, AllocationResult} */
    private function selection(): array
    {
        $criteria = $this->criteria();
        foreach ($this->catalog->findCandidates($criteria, $this->clock->now()) as $property) {
            $allocation = $this->allocations->allocate($property, $criteria, $this->clock->now())[0] ?? null;
            if ($allocation !== null) {
                return [$property, $allocation];
            }
        }
        self::fail('Fixture không có allocation để kiểm thử preview.');
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

    /** @return list<int> */
    private function customerIds(): array
    {
        return array_map('intval', $this->pdo->query(
            "SELECT NguoiDungID FROM NguoiDung WHERE VaiTro = 'CUSTOMER' AND TrangThai = 'HOAT_DONG' ORDER BY NguoiDungID LIMIT 2"
        )->fetchAll(PDO::FETCH_COLUMN));
    }

    private function heldTotal(): int
    {
        return (int) $this->pdo->query('SELECT COALESCE(SUM(SoLuongDaGiu), 0) FROM TonPhongNgay')->fetchColumn();
    }

    private function ledgerCount(): int
    {
        return (int) $this->pdo->query('SELECT COUNT(*) FROM GiuTonPhongDem')->fetchColumn();
    }

    private function assertNotUsable(string $token): void
    {
        try {
            $this->service->loadForCheckout($token, $this->customerIds()[0]);
            self::fail('Preview không hợp lệ phải bị từ chối.');
        } catch (PreviewException $exception) {
            self::assertSame('PREVIEW_NOT_USABLE', $exception->errorCode);
        }
    }
}

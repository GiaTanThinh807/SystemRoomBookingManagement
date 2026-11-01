<?php

declare(strict_types=1);

namespace Tests\Integration\Modules\Bookings;

use App\Bootstrap\App;
use App\Modules\Bookings\Application\BookingRequest;
use App\Modules\Bookings\Application\BookingService;
use App\Modules\Bookings\Application\OnlinePaymentService;
use App\Modules\Bookings\Application\PaymentExpiryService;
use App\Modules\Bookings\Application\PaymentGateway;
use App\Modules\Bookings\Application\PaymentGatewayRequest;
use App\Modules\Bookings\Application\PaymentGatewayResult;
use App\Modules\Bookings\Application\PreviewService;
use App\Modules\Bookings\Application\PreviewSnapshotFactory;
use App\Modules\Bookings\Infrastructure\BookingCodeGenerator;
use App\Modules\Bookings\Infrastructure\BookingRepository;
use App\Modules\Bookings\Infrastructure\PaymentRepository;
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
use DateInterval;
use DateTimeImmutable;
use DateTimeZone;
use PDO;
use RuntimeException;
use Tests\Support\TestCase;

final class OnlinePaymentServiceTest extends TestCase
{
    private PDO $pdo;
    private FrozenClock $clock;
    private PreviewService $previews;
    private BookingService $bookings;
    private BookingRepository $bookingRepository;
    private PaymentRepository $payments;
    private TransactionalInventoryReservationService $inventory;
    private TransactionManager $transactions;
    private DatabaseAuditTrail $audit;
    /** @var list<int> */
    private array $bookingIds = [];
    /** @var list<int> */
    private array $previewIds = [];

    protected function setUp(): void
    {
        parent::setUp();
        $container = App::boot(dirname(__DIR__, 4))->container();
        $this->pdo = $container->get(PDO::class);
        $this->clock = new FrozenClock(new DateTimeImmutable('2025-11-01 02:00:00', new DateTimeZone('UTC')));
        $catalog = new SearchCatalogRepository($this->pdo);
        $availability = new PropertyAvailabilityService(
            $catalog,
            $container->get(\App\Modules\Search\Application\PropertyAllocationService::class),
            $this->clock,
        );
        $snapshotFactory = new PreviewSnapshotFactory();
        $previewRepository = new PreviewRepository($this->pdo);
        $this->bookingRepository = new BookingRepository($this->pdo);
        $this->payments = new PaymentRepository($this->pdo);
        $this->transactions = new TransactionManager($this->pdo, static fn (int $_): null => null);
        $this->inventory = new TransactionalInventoryReservationService(
            $this->pdo,
            new InventoryRepository($this->pdo),
            $this->clock,
        );
        $this->audit = new DatabaseAuditTrail($this->pdo);
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
            $this->bookingRepository,
            $this->payments,
            $availability,
            $snapshotFactory,
            $this->inventory,
            $this->transactions,
            $this->audit,
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
                $this->pdo->exec("DELETE FROM NhatKyHeThong WHERE LoaiDoiTu = 'DatCho' AND DoiTuID = {$bookingId}");
                $this->pdo->exec("DELETE FROM ThanhToan WHERE DatChoID = {$bookingId}");
                $this->pdo->exec(
                    "DELETE gt FROM GiuTonPhongDem gt INNER JOIN HangMucDatCho hm ON hm.HangMucDatChoID = gt.HangMucDatChoID WHERE hm.DatChoID = {$bookingId}"
                );
                $this->pdo->exec(
                    "DELETE gd FROM GiaDemDatCho gd INNER JOIN HangMucDatCho hm ON hm.HangMucDatChoID = gd.HangMucDatChoID WHERE hm.DatChoID = {$bookingId}"
                );
                $this->pdo->exec("DELETE FROM HangMucDatCho WHERE DatChoID = {$bookingId}");
                $this->pdo->exec("DELETE FROM DatCho WHERE DatChoID = {$bookingId}");
            });
        }
        foreach (array_reverse($this->previewIds) as $previewId) {
            $this->pdo->prepare('DELETE FROM XemTruocDatCho WHERE XemTruocDatChoID = ?')->execute([$previewId]);
        }
        parent::tearDown();
    }

    public function test_success_is_finalized_after_gateway_and_makes_hold_permanent(): void
    {
        $created = $this->createPending('10a2c3d4-1234-4abc-8def-1234567890ab');
        $gateway = new RecordingPaymentGateway($this->pdo, [
            new PaymentGatewayResult(PaymentGatewayResult::SUCCESS, 'MOCK-PAY-SUCCESS'),
        ]);

        $confirmed = $this->online($gateway)->process($created->booking->id, 'phase10-create', '127.0.0.1');
        $payment = $this->payments->findByBookingId($created->booking->id);

        self::assertSame('CONFIRMED', $confirmed->status);
        self::assertSame('PAID', $payment?->status);
        self::assertTrue($payment?->paid->equals($payment->total));
        self::assertSame(1, $gateway->chargeCalls);
        self::assertSame(1, $this->rowCount('DatCho', 'ClientRequestID', $created->booking->clientRequestId));
        self::assertSame(1, $this->rowCount('ThanhToan', 'DatChoID', (string) $created->booking->id));
        self::assertSame(0, (int) $this->pdo->query($this->holdCountSql($created->booking->id, "AND gt.HetHanLuc IS NOT NULL"))->fetchColumn());
        self::assertSame(0, (int) $this->pdo->query(
            "SELECT COUNT(*) FROM GiaDemDatCho gd INNER JOIN HangMucDatCho hm ON hm.HangMucDatChoID=gd.HangMucDatChoID WHERE hm.DatChoID={$created->booking->id} AND gd.ThuePhiTaiCoSo<>0"
        )->fetchColumn());
        $this->clock->advance(new DateInterval('PT11M'));
        self::assertSame(0, (new PaymentExpiryService(
            $this->bookingRepository,
            $this->payments,
            $this->inventory,
            $this->transactions,
            $this->audit,
            $this->clock,
        ))->expireDue());
    }

    public function test_timeout_keeps_processing_and_retry_reuses_booking_payment_and_key(): void
    {
        $created = $this->createPending('10b2c3d4-1234-4abc-8def-1234567890ab');
        $gateway = new RecordingPaymentGateway($this->pdo, [
            new PaymentGatewayResult(PaymentGatewayResult::TIMEOUT, null, 'MOCK_TIMEOUT'),
            new PaymentGatewayResult(PaymentGatewayResult::SUCCESS, 'MOCK-PAY-RETRY'),
        ]);
        $service = $this->online($gateway);

        $pending = $service->process($created->booking->id, 'phase10-timeout', '127.0.0.1');
        $first = $this->payments->findByBookingId($created->booking->id);
        $confirmed = $service->process($created->booking->id, 'phase10-retry', '127.0.0.1', true);
        $second = $this->payments->findByBookingId($created->booking->id);

        self::assertSame('PENDING_PAYMENT', $pending->status);
        self::assertSame('MOCK_TIMEOUT', $first?->lastError);
        self::assertSame('CONFIRMED', $confirmed->status);
        self::assertSame('PAID', $second?->status);
        self::assertSame(2, $second?->attempts);
        self::assertSame([$first?->idempotencyKey, $first?->idempotencyKey], $gateway->chargeKeys);
        self::assertSame(1, $this->rowCount('DatCho', 'ClientRequestID', $created->booking->clientRequestId));
        self::assertSame(1, $this->rowCount('ThanhToan', 'DatChoID', (string) $created->booking->id));
    }

    public function test_definite_failure_releases_inventory_exactly_once(): void
    {
        $created = $this->createPending('10c2c3d4-1234-4abc-8def-1234567890ab');
        $gateway = new RecordingPaymentGateway($this->pdo, [
            new PaymentGatewayResult(PaymentGatewayResult::FAILED, null, 'MOCK_DECLINED'),
        ]);

        $failed = $this->online($gateway)->process($created->booking->id, 'phase10-failed', '127.0.0.1');
        $releasedAgain = $this->transactions->run(
            fn (): int => $this->inventory->releaseActive($created->booking->id, 'PAYMENT_FAILED'),
        );

        self::assertSame('PAYMENT_FAILED', $failed->status);
        self::assertSame('FAILED', $this->payments->findByBookingId($created->booking->id)?->status);
        self::assertSame(0, (int) $this->pdo->query($this->holdCountSql($created->booking->id, "AND gt.TrangThai='ACTIVE'"))->fetchColumn());
        self::assertGreaterThan(0, (int) $this->pdo->query($this->holdCountSql($created->booking->id, "AND gt.TrangThai='RELEASED'"))->fetchColumn());
        self::assertSame(0, $releasedAgain);
    }

    public function test_expiry_wins_then_late_success_is_refunded_without_reviving_booking(): void
    {
        $created = $this->createPending('10d2c3d4-1234-4abc-8def-1234567890ab');
        $gateway = new RecordingPaymentGateway($this->pdo, []);
        $this->clock->advance(new DateInterval('PT11M'));
        $expiry = new PaymentExpiryService(
            $this->bookingRepository,
            $this->payments,
            $this->inventory,
            $this->transactions,
            $this->audit,
            $this->clock,
        );

        self::assertSame(1, $expiry->expireDue());
        $afterCallback = $this->online($gateway)->handleResult(
            $created->booking->id,
            new PaymentGatewayResult(PaymentGatewayResult::SUCCESS, 'MOCK-LATE-CAPTURE'),
            'phase10-late-success',
            '127.0.0.1',
        );
        $payment = $this->payments->findByBookingId($created->booking->id);

        self::assertSame('PAYMENT_FAILED', $afterCallback->status);
        self::assertSame('FAILED', $payment?->status);
        self::assertTrue($payment?->paid->equals($payment->total));
        self::assertTrue($payment?->refunded->equals($payment->total));
        self::assertSame('SUCCEEDED', $payment?->refundStatus);
        self::assertSame(1, $gateway->refundCalls);
        $replayedCallback = $this->online($gateway)->handleResult(
            $created->booking->id,
            new PaymentGatewayResult(PaymentGatewayResult::SUCCESS, 'MOCK-LATE-CAPTURE'),
            'phase10-late-success',
            '127.0.0.1',
        );
        self::assertSame('PAYMENT_FAILED', $replayedCallback->status);
        self::assertSame(1, $gateway->refundCalls);
        self::assertSame(0, $expiry->expireDue());
    }

    private function online(RecordingPaymentGateway $gateway): OnlinePaymentService
    {
        return new OnlinePaymentService(
            $this->pdo,
            $this->bookingRepository,
            $this->payments,
            $this->inventory,
            $gateway,
            $this->transactions,
            $this->audit,
            $this->clock,
        );
    }

    private function createPending(string $clientRequestId): \App\Modules\Bookings\Application\CreatedBooking
    {
        [$property, $allocation] = $this->selection();
        $customerId = (int) $this->pdo->query(
            "SELECT NguoiDungID FROM NguoiDung WHERE VaiTro='CUSTOMER' AND TrangThai='HOAT_DONG' ORDER BY NguoiDungID LIMIT 1"
        )->fetchColumn();
        $preview = $this->previews->create(
            $customerId,
            $property->slug,
            $this->criteria(),
            'PAY_ONLINE',
            $allocation->selectionFingerprint,
            $this->mapping($allocation),
        );
        $this->previewIds[] = $preview->preview->id;
        $created = $this->bookings->createFromPreview(
            $preview->token,
            $customerId,
            BookingRequest::fromInput(
                $clientRequestId,
                'Nguyễn Văn Thanh Toán',
                'payment-test@example.test',
                '0901234567',
                null,
                true,
            ),
            'phase10-create',
            '127.0.0.1',
        );
        $this->bookingIds[] = $created->booking->id;
        self::assertSame('PENDING_PAYMENT', $created->booking->status);
        self::assertSame('PROCESSING', $this->payments->findByBookingId($created->booking->id)?->status);
        self::assertGreaterThan(0, (int) $this->pdo->query($this->holdCountSql($created->booking->id, "AND gt.HetHanLuc IS NOT NULL"))->fetchColumn());
        return $created;
    }

    /** @return array{\App\Modules\Search\Application\PropertySearchSnapshot, AllocationResult} */
    private function selection(): array
    {
        $criteria = $this->criteria();
        $catalog = new SearchCatalogRepository($this->pdo);
        $allocations = App::boot(dirname(__DIR__, 4))->container()
            ->get(\App\Modules\Search\Application\PropertyAllocationService::class);
        foreach ($catalog->findCandidates($criteria, $this->clock->now()) as $property) {
            $allocation = $allocations->allocate($property, $criteria, $this->clock->now(), 'PAY_ONLINE')[0] ?? null;
            if ($allocation !== null) {
                return [$property, $allocation];
            }
        }
        self::fail('Fixture phải có allocation PAY_ONLINE để kiểm thử payment.');
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

    private function rowCount(string $table, string $column, string $value): int
    {
        $statement = $this->pdo->prepare("SELECT COUNT(*) FROM {$table} WHERE {$column}=?");
        $statement->execute([$value]);
        return (int) $statement->fetchColumn();
    }

    private function holdCountSql(int $bookingId, string $condition): string
    {
        return "SELECT COUNT(*) FROM GiuTonPhongDem gt INNER JOIN HangMucDatCho hm ON hm.HangMucDatChoID=gt.HangMucDatChoID WHERE hm.DatChoID={$bookingId} {$condition}";
    }
}

final class RecordingPaymentGateway implements PaymentGateway
{
    public int $chargeCalls = 0;
    public int $refundCalls = 0;
    /** @var list<string> */
    public array $chargeKeys = [];

    /** @param list<PaymentGatewayResult> $chargeResults */
    public function __construct(private PDO $pdo, private array $chargeResults)
    {
    }

    public function charge(PaymentGatewayRequest $request): PaymentGatewayResult
    {
        $this->assertOutsideTransaction();
        ++$this->chargeCalls;
        $this->chargeKeys[] = $request->idempotencyKey;
        return array_shift($this->chargeResults)
            ?? throw new RuntimeException('Test gateway thiếu kết quả charge.');
    }

    public function refund(PaymentGatewayRequest $request): PaymentGatewayResult
    {
        $this->assertOutsideTransaction();
        ++$this->refundCalls;
        return new PaymentGatewayResult(PaymentGatewayResult::SUCCESS, 'MOCK-REFUND-SUCCESS');
    }

    public function void(PaymentGatewayRequest $request): PaymentGatewayResult
    {
        $this->assertOutsideTransaction();
        return new PaymentGatewayResult(PaymentGatewayResult::SUCCESS, 'MOCK-VOID-SUCCESS');
    }

    private function assertOutsideTransaction(): void
    {
        if ($this->pdo->inTransaction()) {
            throw new RuntimeException('Gateway bị gọi khi transaction database còn mở.');
        }
    }
}

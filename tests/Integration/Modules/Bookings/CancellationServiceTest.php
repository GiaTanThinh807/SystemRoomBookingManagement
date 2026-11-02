<?php

declare(strict_types=1);

namespace Tests\Integration\Modules\Bookings;

use App\Bootstrap\App;
use App\Modules\Bookings\Application\BookingException;
use App\Modules\Bookings\Application\CancellationCommand;
use App\Modules\Bookings\Application\CancellationFeeService;
use App\Modules\Bookings\Application\CancellationFinalizer;
use App\Modules\Bookings\Application\CancellationQuoteService;
use App\Modules\Bookings\Application\CancellationRefundService;
use App\Modules\Bookings\Application\CancellationService;
use App\Modules\Bookings\Application\PaymentGateway;
use App\Modules\Bookings\Application\PaymentGatewayRequest;
use App\Modules\Bookings\Application\PaymentGatewayResult;
use App\Modules\Bookings\Infrastructure\CancellationRepository;
use App\Modules\Bookings\Infrastructure\PaymentRepository;
use App\Modules\Commercial\Application\CancellationCalculator;
use App\Modules\Inventory\Application\TransactionalInventoryReservationService;
use App\Modules\Inventory\Infrastructure\InventoryRepository;
use App\Shared\Audit\DatabaseAuditTrail;
use App\Shared\Clock\FrozenClock;
use App\Shared\Database\TransactionManager;
use App\Shared\Money\Money;
use DateTimeImmutable;
use DateTimeZone;
use PDO;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\TestCase;

final class CancellationServiceTest extends TestCase
{
    private PDO $pdo;
    /** @var array<string, mixed>|null */
    private ?array $booking = null;
    /** @var array<string, mixed>|null */
    private ?array $payment = null;
    /** @var list<array<string, mixed>> */
    private array $holds = [];
    /** @var list<array<string, mixed>> */
    private array $inventory = [];
    /** @var array<int, string> */
    private array $cancellationSnapshots = [];
    private int $auditMax = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->pdo = App::boot(dirname(__DIR__, 4))->container()->get(PDO::class);
        $this->auditMax = (int) $this->pdo->query('SELECT COALESCE(MAX(NhatKyHeThongID),0) FROM NhatKyHeThong')->fetchColumn();
    }

    protected function tearDown(): void
    {
        if ($this->booking !== null && $this->payment !== null) {
            $bookingColumns = array_keys($this->booking);
            $bookingId = (int) $this->booking['DatChoID'];
            $assignments = implode(',', array_map(static fn (string $column): string => "`{$column}`=?", array_slice($bookingColumns, 1)));
            $values = array_values(array_slice($this->booking, 1));
            $this->pdo->prepare("UPDATE DatCho SET {$assignments} WHERE DatChoID=?")->execute([...$values, $bookingId]);

            $paymentColumns = array_keys($this->payment);
            $paymentId = (int) $this->payment['ThanhToanID'];
            $paymentAssignments = implode(',', array_map(static fn (string $column): string => "`{$column}`=?", array_slice($paymentColumns, 1)));
            $paymentValues = array_values(array_slice($this->payment, 1));
            $this->pdo->prepare("UPDATE ThanhToan SET {$paymentAssignments} WHERE ThanhToanID=?")->execute([...$paymentValues, $paymentId]);

            $hold = $this->pdo->prepare(
                'UPDATE GiuTonPhongDem SET TrangThai=?, HetHanLuc=?, GiuLuc=?, NhaLuc=?, LyDoNha=? WHERE GiuTonPhongDemID=?'
            );
            foreach ($this->holds as $row) {
                $hold->execute([$row['TrangThai'], $row['HetHanLuc'], $row['GiuLuc'], $row['NhaLuc'], $row['LyDoNha'], $row['GiuTonPhongDemID']]);
            }
            $stock = $this->pdo->prepare(
                'UPDATE TonPhongNgay SET SoLuongDaGiu=? WHERE LoaiPhongID=? AND NgayLuuTru=?'
            );
            foreach ($this->inventory as $row) {
                $stock->execute([$row['SoLuongDaGiu'], $row['LoaiPhongID'], $row['NgayLuuTru']]);
            }
            $snapshot = $this->pdo->prepare('UPDATE HangMucDatCho SET SnapshotHuyJSON=? WHERE HangMucDatChoID=?');
            foreach ($this->cancellationSnapshots as $itemId => $json) {
                $snapshot->execute([$json, $itemId]);
            }
            $this->pdo->exec('DELETE FROM NhatKyHeThong WHERE NhatKyHeThongID>' . $this->auditMax);
        }
        parent::tearDown();
    }

    public function test_pay_at_property_cancel_releases_once_and_retry_is_idempotent(): void
    {
        $row = $this->fixture('PAY_AT_PROPERTY');
        $clock = new FrozenClock($this->cutoff($row)->modify('-1 hour'));
        $quote = $this->quote($clock, $row);
        $key = $this->uuid();
        $command = new CancellationCommand($key, 'Khách thay đổi kế hoạch lưu trú.', $quote->fee);
        $service = $this->service($clock, new CancellationTestGateway($this->pdo));
        $beforeHeld = $this->heldTotal();

        $first = $service->cancel((string) $row['MaDatCho'], (int) $row['KhachHangID'], $command, '127.0.0.1');
        $second = $service->cancel((string) $row['MaDatCho'], (int) $row['KhachHangID'], $command, '127.0.0.1');

        self::assertSame('NONE', $first->refundStatus);
        self::assertSame(0, $first->refunded->amount());
        self::assertSame($first->fee->amount(), $second->fee->amount());
        self::assertSame('CANCELLED', $this->scalar('SELECT TrangThai FROM DatCho WHERE DatChoID=' . (int) $row['DatChoID']));
        self::assertSame(0, $this->activeHoldCount((int) $row['DatChoID']));
        self::assertSame($beforeHeld - count($this->holds), $this->heldTotal());
        self::assertSame(1, $this->auditCount($key));
        try {
            $service->cancel(
                (string) $row['MaDatCho'],
                (int) $row['KhachHangID'],
                new CancellationCommand($key, 'Payload khác dùng lại cùng khóa hủy.', $quote->fee),
                '127.0.0.1',
            );
            self::fail('Cùng khóa hủy nhưng payload khác phải bị từ chối.');
        } catch (BookingException $exception) {
            self::assertSame('IDEMPOTENCY_CONFLICT', $exception->errorCode);
        }
    }

    public function test_online_refund_failure_keeps_cancelled_and_same_key_can_retry_successfully(): void
    {
        $row = $this->fixture('PAY_ONLINE');
        $clock = new FrozenClock($this->cutoff($row)->modify('-1 hour'));
        $quote = $this->quote($clock, $row);
        self::assertGreaterThan(0, $quote->expectedRefund->amount());
        $command = new CancellationCommand($this->uuid(), 'Khách yêu cầu hủy booking online.', $quote->fee);

        $failed = $this->service(
            $clock,
            new CancellationTestGateway($this->pdo, PaymentGatewayResult::FAILED),
        )->cancel((string) $row['MaDatCho'], (int) $row['KhachHangID'], $command, '127.0.0.1');
        self::assertSame('FAILED', $failed->refundStatus);
        self::assertSame(0, $failed->refunded->amount());
        self::assertSame('CANCELLED', $this->scalar('SELECT TrangThai FROM DatCho WHERE DatChoID=' . (int) $row['DatChoID']));

        $succeeded = $this->service($clock, new CancellationTestGateway($this->pdo))->cancel(
            (string) $row['MaDatCho'],
            (int) $row['KhachHangID'],
            $command,
            '127.0.0.1',
        );
        self::assertSame('SUCCEEDED', $succeeded->refundStatus);
        self::assertSame($quote->expectedRefund->amount(), $succeeded->refunded->amount());
        self::assertSame(0, $this->activeHoldCount((int) $row['DatChoID']));
        self::assertSame(1, $this->auditCount($command->idempotencyKey));
    }

    public function test_changed_quote_rolls_back_booking_inventory_and_payment(): void
    {
        $row = $this->fixture('PAY_AT_PROPERTY');
        $clock = new FrozenClock($this->cutoff($row)->modify('-1 hour'));
        $quote = $this->quote($clock, $row);
        $command = new CancellationCommand(
            $this->uuid(),
            'Khách xác nhận bằng báo giá đã cũ.',
            Money::vnd($quote->fee->amount() + 1),
        );
        $beforeActive = $this->activeHoldCount((int) $row['DatChoID']);

        try {
            $this->service($clock, new CancellationTestGateway($this->pdo))->cancel(
                (string) $row['MaDatCho'],
                (int) $row['KhachHangID'],
                $command,
                '127.0.0.1',
            );
            self::fail('Báo giá thay đổi phải buộc customer xác nhận lại.');
        } catch (BookingException $exception) {
            self::assertSame('CANCELLATION_QUOTE_CHANGED', $exception->errorCode);
        }
        self::assertSame('CONFIRMED', $this->scalar('SELECT TrangThai FROM DatCho WHERE DatChoID=' . (int) $row['DatChoID']));
        self::assertSame($beforeActive, $this->activeHoldCount((int) $row['DatChoID']));
        self::assertSame(0, $this->auditCount($command->idempotencyKey));
    }

    public function test_provider_amount_mismatch_never_marks_refund_successful(): void
    {
        $row = $this->fixture('PAY_ONLINE');
        $clock = new FrozenClock($this->cutoff($row)->modify('-1 hour'));
        $quote = $this->quote($clock, $row);
        self::assertGreaterThan(0, $quote->expectedRefund->amount());
        $command = new CancellationCommand($this->uuid(), 'Khách hủy để kiểm tra chênh lệch refund.', $quote->fee);
        $gateway = new CancellationTestGateway(
            $this->pdo,
            PaymentGatewayResult::SUCCESS,
            Money::vnd($quote->expectedRefund->amount() - 1),
        );

        $receipt = $this->service($clock, $gateway)->cancel(
            (string) $row['MaDatCho'],
            (int) $row['KhachHangID'],
            $command,
            '127.0.0.1',
        );

        self::assertSame('FAILED', $receipt->refundStatus);
        self::assertSame(0, $receipt->refunded->amount());
        self::assertSame('0.00', $this->scalar('SELECT SoTienHoan FROM DatCho WHERE DatChoID=' . (int) $row['DatChoID']));
    }

    /** @return array<string, mixed> */
    private function fixture(string $timing): array
    {
        $extra = $timing === 'PAY_ONLINE'
            ? " AND tt.TrangThai='PAID' AND tt.TrangThaiHoanTien='NONE'"
            : " AND tt.TrangThai='NOT_TRACKED'";
        $statement = $this->pdo->prepare(
            "SELECT dc.*, cs.GioNhanPhong checkInTime, cs.MuiGio timezone
             FROM DatCho dc INNER JOIN CoSoLuuTru cs ON cs.CoSoLuuTruID=dc.CoSoLuuTruID
             INNER JOIN ThanhToan tt ON tt.DatChoID=dc.DatChoID
             WHERE dc.TrangThai='CONFIRMED' AND dc.ThoiDiemThanhToan=:timing {$extra}
             ORDER BY dc.NgayNhanPhong DESC LIMIT 1"
        );
        $statement->execute(['timing' => $timing]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        self::assertIsArray($row);
        $bookingId = (int) $row['DatChoID'];
        $this->booking = $this->pdo->query('SELECT * FROM DatCho WHERE DatChoID=' . $bookingId)->fetch(PDO::FETCH_ASSOC);
        $this->payment = $this->pdo->query('SELECT * FROM ThanhToan WHERE DatChoID=' . $bookingId)->fetch(PDO::FETCH_ASSOC);
        self::assertIsArray($this->booking);
        self::assertIsArray($this->payment);
        $this->holds = $this->pdo->query(
            'SELECT gt.* FROM GiuTonPhongDem gt INNER JOIN HangMucDatCho hm ON hm.HangMucDatChoID=gt.HangMucDatChoID WHERE hm.DatChoID=' . $bookingId
        )->fetchAll(PDO::FETCH_ASSOC);
        self::assertNotEmpty($this->holds);
        $this->inventory = $this->pdo->query(
            'SELECT DISTINCT tp.LoaiPhongID,tp.NgayLuuTru,tp.SoLuongDaGiu FROM TonPhongNgay tp '
            . 'INNER JOIN GiuTonPhongDem gt ON gt.LoaiPhongID=tp.LoaiPhongID AND gt.NgayLuuTru=tp.NgayLuuTru '
            . 'INNER JOIN HangMucDatCho hm ON hm.HangMucDatChoID=gt.HangMucDatChoID WHERE hm.DatChoID=' . $bookingId
        )->fetchAll(PDO::FETCH_ASSOC);
        $itemStatement = $this->pdo->query(
            'SELECT HangMucDatChoID,SnapshotHuyJSON FROM HangMucDatCho WHERE DatChoID=' . $bookingId
        );
        foreach ($itemStatement->fetchAll(PDO::FETCH_ASSOC) as $item) {
            $this->cancellationSnapshots[(int) $item['HangMucDatChoID']] = (string) $item['SnapshotHuyJSON'];
        }
        if ($timing === 'PAY_ONLINE') {
            $this->pdo->exec(
                "UPDATE HangMucDatCho SET SnapshotHuyJSON=JSON_SET(SnapshotHuyJSON, '$.absoluteFeeAtBooking', 0) WHERE DatChoID=" . $bookingId
            );
        }
        return $row;
    }

    private function service(FrozenClock $clock, PaymentGateway $gateway): CancellationService
    {
        $cancellations = new CancellationRepository($this->pdo);
        $payments = new PaymentRepository($this->pdo);
        $transactions = new TransactionManager($this->pdo, static fn (int $_): null => null);
        $audit = new DatabaseAuditTrail($this->pdo);
        return new CancellationService(
            $cancellations,
            $payments,
            new CancellationFeeService(new CancellationCalculator()),
            new CancellationFinalizer(
                $cancellations,
                $payments,
                new TransactionalInventoryReservationService($this->pdo, new InventoryRepository($this->pdo), $clock),
                $audit,
            ),
            new CancellationRefundService(
                $this->pdo,
                $cancellations,
                $payments,
                $gateway,
                $transactions,
                $audit,
                $clock,
            ),
            $transactions,
            $clock,
        );
    }

    /** @param array<string, mixed> $row */
    private function quote(FrozenClock $clock, array $row): \App\Modules\Bookings\Application\CancellationQuote
    {
        return (new CancellationQuoteService(
            new CancellationRepository($this->pdo),
            new CancellationFeeService(new CancellationCalculator()),
            $clock,
        ))->preview((string) $row['MaDatCho'], (int) $row['KhachHangID']);
    }

    /** @param array<string, mixed> $row */
    private function cutoff(array $row): DateTimeImmutable
    {
        return new DateTimeImmutable(
            (string) $row['NgayNhanPhong'] . ' ' . (string) $row['checkInTime'],
            new DateTimeZone((string) $row['timezone']),
        );
    }

    private function activeHoldCount(int $bookingId): int
    {
        return (int) $this->pdo->query(
            "SELECT COUNT(*) FROM GiuTonPhongDem gt INNER JOIN HangMucDatCho hm ON hm.HangMucDatChoID=gt.HangMucDatChoID WHERE hm.DatChoID={$bookingId} AND gt.TrangThai='ACTIVE'"
        )->fetchColumn();
    }

    private function heldTotal(): int
    {
        $total = 0;
        foreach ($this->inventory as $row) {
            $total += (int) $this->pdo->query(
                'SELECT SoLuongDaGiu FROM TonPhongNgay WHERE LoaiPhongID=' . (int) $row['LoaiPhongID']
                . ' AND NgayLuuTru=' . $this->pdo->quote((string) $row['NgayLuuTru'])
            )->fetchColumn();
        }
        return $total;
    }

    private function auditCount(string $requestId): int
    {
        $statement = $this->pdo->prepare('SELECT COUNT(*) FROM NhatKyHeThong WHERE RequestID=?');
        $statement->execute([$requestId]);
        return (int) $statement->fetchColumn();
    }

    private function scalar(string $sql): string
    {
        return (string) $this->pdo->query($sql)->fetchColumn();
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

final readonly class CancellationTestGateway implements PaymentGateway
{
    public function __construct(
        private PDO $pdo,
        private string $refundOutcome = PaymentGatewayResult::SUCCESS,
        private ?Money $refundAmount = null,
    ) {
    }

    public function charge(PaymentGatewayRequest $request): PaymentGatewayResult
    {
        throw new \LogicException('Test hủy không gọi charge.');
    }

    public function refund(PaymentGatewayRequest $request): PaymentGatewayResult
    {
        if ($this->pdo->inTransaction()) {
            throw new \LogicException('Refund bị gọi trong transaction.');
        }
        if ($this->refundOutcome === PaymentGatewayResult::SUCCESS) {
            return new PaymentGatewayResult(
                PaymentGatewayResult::SUCCESS,
                'TEST-REFUND-' . substr(hash('sha256', $request->idempotencyKey), 0, 16),
                null,
                $this->refundAmount ?? $request->amount,
            );
        }
        return new PaymentGatewayResult($this->refundOutcome, null, 'TEST_REFUND_FAILED');
    }

    public function void(PaymentGatewayRequest $request): PaymentGatewayResult
    {
        throw new \LogicException('Test hủy không gọi void.');
    }
}

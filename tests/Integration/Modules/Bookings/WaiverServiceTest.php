<?php

declare(strict_types=1);

namespace Tests\Integration\Modules\Bookings;

use App\Bootstrap\App;
use App\Modules\Bookings\Application\BookingException;
use App\Modules\Bookings\Application\CancellationFinalizer;
use App\Modules\Bookings\Application\CancellationRefundService;
use App\Modules\Bookings\Application\PaymentGateway;
use App\Modules\Bookings\Application\PaymentGatewayRequest;
use App\Modules\Bookings\Application\PaymentGatewayResult;
use App\Modules\Bookings\Application\WaiverDecision;
use App\Modules\Bookings\Application\WaiverService;
use App\Modules\Bookings\Infrastructure\CancellationRepository;
use App\Modules\Bookings\Infrastructure\PaymentRepository;
use App\Modules\Bookings\Infrastructure\WaiverRepository;
use App\Modules\Inventory\Application\TransactionalInventoryReservationService;
use App\Modules\Inventory\Infrastructure\InventoryRepository;
use App\Shared\Audit\DatabaseAuditTrail;
use App\Shared\Clock\FrozenClock;
use App\Shared\Database\TransactionManager;
use App\Shared\Money\Money;
use DateTimeImmutable;
use DateTimeZone;
use PDO;
use Tests\Support\TestCase;

final class WaiverServiceTest extends TestCase
{
    private PDO $pdo;
    /** @var array<string, mixed> */
    private array $booking;
    /** @var array<string, mixed> */
    private array $payment;
    /** @var list<array<string, mixed>> */
    private array $holds = [];
    /** @var list<array<string, mixed>> */
    private array $inventory = [];
    private int $waiverMax;
    private int $auditMax;

    protected function setUp(): void
    {
        parent::setUp();
        $this->pdo = App::boot(dirname(__DIR__, 4))->container()->get(PDO::class);
        $this->waiverMax = (int) $this->pdo->query('SELECT COALESCE(MAX(YeuCauHuyMienPhiID),0) FROM YeuCauHuyMienPhi')->fetchColumn();
        $this->auditMax = (int) $this->pdo->query('SELECT COALESCE(MAX(NhatKyHeThongID),0) FROM NhatKyHeThong')->fetchColumn();
    }

    protected function tearDown(): void
    {
        if (isset($this->booking, $this->payment)) {
            $this->restore('DatCho', 'DatChoID', $this->booking);
            $this->restore('ThanhToan', 'ThanhToanID', $this->payment);
            $hold = $this->pdo->prepare(
                'UPDATE GiuTonPhongDem SET TrangThai=?, HetHanLuc=?, GiuLuc=?, NhaLuc=?, LyDoNha=? WHERE GiuTonPhongDemID=?'
            );
            foreach ($this->holds as $row) {
                $hold->execute([$row['TrangThai'], $row['HetHanLuc'], $row['GiuLuc'], $row['NhaLuc'], $row['LyDoNha'], $row['GiuTonPhongDemID']]);
            }
            $stock = $this->pdo->prepare('UPDATE TonPhongNgay SET SoLuongDaGiu=? WHERE LoaiPhongID=? AND NgayLuuTru=?');
            foreach ($this->inventory as $row) {
                $stock->execute([$row['SoLuongDaGiu'], $row['LoaiPhongID'], $row['NgayLuuTru']]);
            }
        }
        $this->pdo->exec('DELETE FROM NhatKyHeThong WHERE NhatKyHeThongID>' . $this->auditMax);
        $this->pdo->exec('DELETE FROM YeuCauHuyMienPhi WHERE YeuCauHuyMienPhiID>' . $this->waiverMax);
        parent::tearDown();
    }

    public function test_pending_request_does_not_change_booking_payment_or_inventory(): void
    {
        $row = $this->fixture('PAY_AT_PROPERTY');
        $clock = new FrozenClock($this->cutoff($row)->modify('-2 hours'));
        $beforeHolds = $this->activeHolds((int) $row['DatChoID']);

        $waiverId = $this->service($clock)->request(
            (string) $row['MaDatCho'],
            (int) $row['KhachHangID'],
            'Xin hỗ trợ miễn phí hủy do thay đổi lịch trình.',
            'waiver-create-' . bin2hex(random_bytes(6)),
            '127.0.0.1',
        );

        self::assertGreaterThan(0, $waiverId);
        self::assertSame('PENDING', $this->value('SELECT TrangThai FROM YeuCauHuyMienPhi WHERE YeuCauHuyMienPhiID=' . $waiverId));
        self::assertSame('CONFIRMED', $this->value('SELECT TrangThai FROM DatCho WHERE DatChoID=' . (int) $row['DatChoID']));
        self::assertSame($beforeHolds, $this->activeHolds((int) $row['DatChoID']));
        self::assertSame('NONE', $this->value('SELECT TrangThaiHoanTien FROM ThanhToan WHERE DatChoID=' . (int) $row['DatChoID']));
    }

    public function test_only_same_organization_can_reject_and_rejection_keeps_booking(): void
    {
        $row = $this->fixture('PAY_AT_PROPERTY');
        $clock = new FrozenClock($this->cutoff($row)->modify('-2 hours'));
        $service = $this->service($clock);
        $waiverId = $service->request(
            (string) $row['MaDatCho'],
            (int) $row['KhachHangID'],
            'Xin miễn phí hủy để kiểm tra phạm vi tổ chức.',
            'waiver-create-' . bin2hex(random_bytes(6)),
            '127.0.0.1',
        );
        $organizationId = (int) $row['organizationId'];

        try {
            $service->decide(
                $waiverId,
                $organizationId + 9999,
                2,
                new WaiverDecision('REJECTED', 'Không thuộc tổ chức xử lý.'),
                'waiver-decide-' . bin2hex(random_bytes(6)),
                '127.0.0.1',
            );
            self::fail('Tổ chức khác không được xử lý waiver.');
        } catch (BookingException $exception) {
            self::assertSame('WAIVER_NOT_FOUND', $exception->errorCode);
        }
        $service->decide(
            $waiverId,
            $organizationId,
            2,
            new WaiverDecision('REJECTED', 'Cơ sở chưa thể hỗ trợ yêu cầu này.'),
            'waiver-decide-' . bin2hex(random_bytes(6)),
            '127.0.0.1',
        );

        self::assertSame('REJECTED', $this->value('SELECT TrangThai FROM YeuCauHuyMienPhi WHERE YeuCauHuyMienPhiID=' . $waiverId));
        self::assertSame('CONFIRMED', $this->value('SELECT TrangThai FROM DatCho WHERE DatChoID=' . (int) $row['DatChoID']));
        self::assertGreaterThan(0, $this->activeHolds((int) $row['DatChoID']));
    }

    public function test_approval_atomically_cancels_with_zero_fee_and_refunds_online_payment(): void
    {
        $row = $this->fixture('PAY_ONLINE');
        $clock = new FrozenClock($this->cutoff($row)->modify('-2 hours'));
        $service = $this->service($clock);
        $waiverId = $service->request(
            (string) $row['MaDatCho'],
            (int) $row['KhachHangID'],
            'Xin miễn phí hủy booking đã thanh toán trực tuyến.',
            'waiver-create-' . bin2hex(random_bytes(6)),
            '127.0.0.1',
        );
        $service->decide(
            $waiverId,
            (int) $row['organizationId'],
            2,
            new WaiverDecision('APPROVED', 'Đồng ý hỗ trợ miễn phí hủy.'),
            'waiver-decide-' . bin2hex(random_bytes(6)),
            '127.0.0.1',
        );

        self::assertSame('APPROVED', $this->value('SELECT TrangThai FROM YeuCauHuyMienPhi WHERE YeuCauHuyMienPhiID=' . $waiverId));
        self::assertSame('CANCELLED', $this->value('SELECT TrangThai FROM DatCho WHERE DatChoID=' . (int) $row['DatChoID']));
        self::assertSame('0.00', $this->value('SELECT PhiHuy FROM DatCho WHERE DatChoID=' . (int) $row['DatChoID']));
        self::assertSame(0, $this->activeHolds((int) $row['DatChoID']));
        self::assertSame('SUCCEEDED', $this->value('SELECT TrangThaiHoanTien FROM ThanhToan WHERE DatChoID=' . (int) $row['DatChoID']));
        self::assertSame(
            $this->value('SELECT DaThanhToan FROM ThanhToan WHERE DatChoID=' . (int) $row['DatChoID']),
            $this->value('SELECT DaHoan FROM ThanhToan WHERE DatChoID=' . (int) $row['DatChoID']),
        );
    }

    public function test_approval_at_cutoff_keeps_waiver_pending(): void
    {
        $row = $this->fixture('PAY_AT_PROPERTY');
        $clock = new FrozenClock($this->cutoff($row)->modify('-1 hour'));
        $service = $this->service($clock);
        $waiverId = $service->request(
            (string) $row['MaDatCho'],
            (int) $row['KhachHangID'],
            'Xin hỗ trợ miễn phí hủy trước thời điểm nhận phòng.',
            'waiver-create-' . bin2hex(random_bytes(6)),
            '127.0.0.1',
        );
        $clock->freeze($this->cutoff($row));

        try {
            $service->decide(
                $waiverId,
                (int) $row['organizationId'],
                2,
                new WaiverDecision('APPROVED', 'Đồng ý hỗ trợ miễn phí hủy.'),
                'waiver-decide-' . bin2hex(random_bytes(6)),
                '127.0.0.1',
            );
            self::fail('Không được approve waiver tại cutoff.');
        } catch (BookingException $exception) {
            self::assertSame('BOOKING_CUTOFF_PASSED', $exception->errorCode);
        }
        self::assertSame('PENDING', $this->value('SELECT TrangThai FROM YeuCauHuyMienPhi WHERE YeuCauHuyMienPhiID=' . $waiverId));
        self::assertSame('CONFIRMED', $this->value('SELECT TrangThai FROM DatCho WHERE DatChoID=' . (int) $row['DatChoID']));
    }

    /** @return array<string, mixed> */
    private function fixture(string $timing): array
    {
        $status = $timing === 'PAY_ONLINE' ? "tt.TrangThai='PAID'" : "tt.TrangThai='NOT_TRACKED'";
        $statement = $this->pdo->prepare(
            "SELECT dc.*, cs.GioNhanPhong AS checkInTime, cs.MuiGio AS timezone,
                    cs.ToChucDoiTacID AS organizationId
             FROM DatCho dc
             INNER JOIN CoSoLuuTru cs ON cs.CoSoLuuTruID=dc.CoSoLuuTruID
             INNER JOIN ThanhToan tt ON tt.DatChoID=dc.DatChoID
             WHERE dc.TrangThai='CONFIRMED' AND dc.ThoiDiemThanhToan=:timing
               AND {$status} AND tt.TrangThaiHoanTien='NONE'
               AND NOT EXISTS (SELECT 1 FROM YeuCauHuyMienPhi w WHERE w.DatChoID=dc.DatChoID AND w.TrangThai='PENDING')
             ORDER BY dc.NgayNhanPhong DESC LIMIT 1"
        );
        $statement->execute(['timing' => $timing]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        self::assertIsArray($row);
        $bookingId = (int) $row['DatChoID'];
        $this->booking = $this->pdo->query('SELECT * FROM DatCho WHERE DatChoID=' . $bookingId)->fetch(PDO::FETCH_ASSOC);
        $this->payment = $this->pdo->query('SELECT * FROM ThanhToan WHERE DatChoID=' . $bookingId)->fetch(PDO::FETCH_ASSOC);
        $this->holds = $this->pdo->query(
            'SELECT gt.* FROM GiuTonPhongDem gt INNER JOIN HangMucDatCho hm ON hm.HangMucDatChoID=gt.HangMucDatChoID WHERE hm.DatChoID=' . $bookingId
        )->fetchAll(PDO::FETCH_ASSOC);
        $this->inventory = $this->pdo->query(
            'SELECT DISTINCT tp.LoaiPhongID,tp.NgayLuuTru,tp.SoLuongDaGiu FROM TonPhongNgay tp '
            . 'INNER JOIN GiuTonPhongDem gt ON gt.LoaiPhongID=tp.LoaiPhongID AND gt.NgayLuuTru=tp.NgayLuuTru '
            . 'INNER JOIN HangMucDatCho hm ON hm.HangMucDatChoID=gt.HangMucDatChoID WHERE hm.DatChoID=' . $bookingId
        )->fetchAll(PDO::FETCH_ASSOC);
        self::assertNotEmpty($this->holds);
        return $row;
    }

    private function service(FrozenClock $clock): WaiverService
    {
        $cancellations = new CancellationRepository($this->pdo);
        $payments = new PaymentRepository($this->pdo);
        $waivers = new WaiverRepository($this->pdo);
        $transactions = new TransactionManager($this->pdo, static fn (int $_): null => null);
        $audit = new DatabaseAuditTrail($this->pdo);
        $finalizer = new CancellationFinalizer(
            $cancellations,
            $payments,
            new TransactionalInventoryReservationService($this->pdo, new InventoryRepository($this->pdo), $clock),
            $audit,
        );
        return new WaiverService(
            $cancellations,
            $payments,
            $waivers,
            $finalizer,
            new CancellationRefundService(
                $this->pdo,
                $cancellations,
                $payments,
                new WaiverTestGateway($this->pdo),
                $transactions,
                $audit,
                $clock,
            ),
            $transactions,
            $audit,
            $clock,
        );
    }

    /** @param array<string, mixed> $row */
    private function cutoff(array $row): DateTimeImmutable
    {
        return new DateTimeImmutable(
            (string) $row['NgayNhanPhong'] . ' ' . (string) $row['checkInTime'],
            new DateTimeZone((string) $row['timezone']),
        );
    }

    /** @param array<string, mixed> $row */
    private function restore(string $table, string $idColumn, array $row): void
    {
        $id = $row[$idColumn];
        $columns = array_keys($row);
        $assignments = implode(',', array_map(static fn (string $column): string => "`{$column}`=?", array_slice($columns, 1)));
        $this->pdo->prepare("UPDATE {$table} SET {$assignments} WHERE {$idColumn}=?")
            ->execute([...array_values(array_slice($row, 1)), $id]);
    }

    private function activeHolds(int $bookingId): int
    {
        return (int) $this->pdo->query(
            "SELECT COUNT(*) FROM GiuTonPhongDem gt INNER JOIN HangMucDatCho hm ON hm.HangMucDatChoID=gt.HangMucDatChoID WHERE hm.DatChoID={$bookingId} AND gt.TrangThai='ACTIVE'"
        )->fetchColumn();
    }

    private function value(string $sql): string
    {
        return (string) $this->pdo->query($sql)->fetchColumn();
    }
}

final readonly class WaiverTestGateway implements PaymentGateway
{
    public function __construct(private PDO $pdo)
    {
    }

    public function charge(PaymentGatewayRequest $request): PaymentGatewayResult
    {
        throw new \LogicException('Waiver test không gọi charge.');
    }

    public function refund(PaymentGatewayRequest $request): PaymentGatewayResult
    {
        if ($this->pdo->inTransaction()) {
            throw new \LogicException('Refund waiver bị gọi trong transaction.');
        }
        return new PaymentGatewayResult(PaymentGatewayResult::SUCCESS, 'WAIVER-REFUND-' . $request->bookingId, null, $request->amount);
    }

    public function void(PaymentGatewayRequest $request): PaymentGatewayResult
    {
        throw new \LogicException('Waiver test không gọi void.');
    }
}

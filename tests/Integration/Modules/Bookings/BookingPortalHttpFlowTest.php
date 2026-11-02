<?php

declare(strict_types=1);

namespace Tests\Integration\Modules\Bookings;

use App\Bootstrap\App;
use App\Bootstrap\SessionBootstrap;
use App\Http\Request;
use App\Modules\Identity\Infrastructure\IdentitySession;
use App\Modules\Identity\Infrastructure\PartnerMembershipRepository;
use App\Modules\Identity\Infrastructure\UserRepository;
use PDO;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Tests\Support\TestCase;

#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class BookingPortalHttpFlowTest extends TestCase
{
    private App $app;
    private PDO $pdo;
    private SessionBootstrap $session;
    private IdentitySession $identity;
    /** @var array<string, mixed>|null */
    private ?array $originalBooking = null;
    /** @var array<int, string> */
    private array $originalParties = [];
    /** @var list<string> */
    private array $requestIds = [];
    /** @var list<int> */
    private array $createdWaivers = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->app = App::boot(dirname(__DIR__, 4));
        $container = $this->app->container();
        $this->pdo = $container->get(PDO::class);
        $this->session = $container->get(SessionBootstrap::class);
        $this->session->start();
        $this->identity = $container->get(IdentitySession::class);
    }

    protected function tearDown(): void
    {
        if (isset($this->pdo)) {
            if ($this->originalBooking !== null) {
                $this->pdo->prepare(
                    'UPDATE DatCho SET TenNguoiDat=?, EmailNguoiDat=?, DienThoaiNguoiDat=?, YeuCauDacBiet=? WHERE DatChoID=?'
                )->execute([
                    $this->originalBooking['bookerName'],
                    $this->originalBooking['bookerEmail'],
                    $this->originalBooking['bookerPhone'],
                    $this->originalBooking['specialRequest'],
                    $this->originalBooking['id'],
                ]);
                $statement = $this->pdo->prepare('UPDATE HangMucDatCho SET KhachJSON=? WHERE HangMucDatChoID=?');
                foreach ($this->originalParties as $itemId => $json) {
                    $statement->execute([$json, $itemId]);
                }
            }
            $statement = $this->pdo->prepare('DELETE FROM NhatKyHeThong WHERE RequestID=?');
            foreach ($this->requestIds as $requestId) {
                $statement->execute([$requestId]);
            }
            $deleteWaiver = $this->pdo->prepare('DELETE FROM YeuCauHuyMienPhi WHERE YeuCauHuyMienPhiID=?');
            foreach ($this->createdWaivers as $waiverId) {
                $deleteWaiver->execute([$waiverId]);
            }
        }
        if (isset($this->session) && session_status() === PHP_SESSION_ACTIVE) {
            $this->session->destroy();
        }
        parent::tearDown();
    }

    public function test_customer_lists_opens_and_updates_only_own_booking(): void
    {
        $booking = $this->futureConfirmedBooking();
        $this->signIn((int) $booking['customerId']);

        $index = $this->app->handle(new Request('GET', '/account/bookings', requestId: $this->requestId('customer-list')));
        self::assertSame(200, $index->status());
        self::assertSame('no-store, private', $index->headers()['Cache-Control']);
        self::assertStringContainsString('Đặt chỗ của tôi', $index->body());
        self::assertStringContainsString((string) $booking['code'], $index->body());

        $detail = $this->app->handle(new Request(
            'GET', '/account/bookings/' . $booking['code'], requestId: $this->requestId('customer-detail'),
        ));
        self::assertSame(200, $detail->status());
        self::assertStringContainsString('Dữ liệu bên dưới là snapshot', $detail->body());
        self::assertStringContainsString('name="bookerName"', $detail->body());

        $foreignCode = (string) $this->pdo->query(
            'SELECT MaDatCho FROM DatCho WHERE KhachHangID <> ' . (int) $booking['customerId'] . ' LIMIT 1'
        )->fetchColumn();
        self::assertSame(404, $this->app->handle(new Request(
            'GET', '/account/bookings/' . $foreignCode, requestId: $this->requestId('customer-foreign'),
        ))->status());

        $beforeTotal = (string) $booking['grandTotal'];
        $beforeLedger = $this->ledgerCount((int) $booking['id']);
        $updateRequestId = $this->requestId('customer-update');
        $updated = $this->app->handle(new Request('POST', '/account/bookings/' . $booking['code'] . '/guest-details', body: [
            '_token' => $this->session->csrfToken(),
            'bookerName' => 'Khách HTTP Đã Sửa',
            'bookerEmail' => 'phase11-http@example.test',
            'bookerPhone' => '0901234567',
            'specialRequest' => 'Phòng yên tĩnh.',
            'guestNames' => $this->guestNames(),
        ], requestId: $updateRequestId));
        self::assertSame(303, $updated->status());
        self::assertSame('http://localhost/account/bookings/' . $booking['code'], $updated->headers()['Location']);
        self::assertSame('phase11-http@example.test', $this->scalar(
            'SELECT EmailNguoiDat FROM DatCho WHERE DatChoID=' . (int) $booking['id'],
        ));
        self::assertSame($beforeTotal, $this->scalar('SELECT TongTien FROM DatCho WHERE DatChoID=' . (int) $booking['id']));
        self::assertSame($beforeLedger, $this->ledgerCount((int) $booking['id']));
    }

    public function test_partner_scope_and_admin_sensitive_lookup_are_enforced(): void
    {
        $users = new UserRepository($this->pdo);
        $memberships = new PartnerMembershipRepository($this->pdo);
        $partner = $users->findById(2);
        self::assertNotNull($partner);
        $organization = $memberships->findOrganizationForUser($partner->id);
        self::assertNotNull($organization);
        $this->identity->signIn($partner->toCurrentUser(), $organization);

        $ownedCode = (string) $this->pdo->query(
            'SELECT dc.MaDatCho FROM DatCho dc INNER JOIN CoSoLuuTru cs ON cs.CoSoLuuTruID=dc.CoSoLuuTruID '
            . 'WHERE cs.ToChucDoiTacID=' . $organization->id . ' LIMIT 1'
        )->fetchColumn();
        $partnerIndex = $this->app->handle(new Request('GET', '/partner/bookings', requestId: $this->requestId('partner-list')));
        self::assertSame(200, $partnerIndex->status());
        self::assertStringContainsString('Không gian đối tác', $partnerIndex->body());
        self::assertSame(200, $this->app->handle(new Request(
            'GET', '/partner/bookings/' . $ownedCode, requestId: $this->requestId('partner-detail'),
        ))->status());

        $foreignCode = (string) $this->pdo->query(
            'SELECT dc.MaDatCho FROM DatCho dc INNER JOIN CoSoLuuTru cs ON cs.CoSoLuuTruID=dc.CoSoLuuTruID '
            . 'WHERE cs.ToChucDoiTacID<>' . $organization->id . ' LIMIT 1'
        )->fetchColumn();
        self::assertSame(404, $this->app->handle(new Request(
            'GET', '/partner/bookings/' . $foreignCode, requestId: $this->requestId('partner-foreign'),
        ))->status());

        $this->identity->signOut();
        $this->session->start();
        $admin = $users->findById(1);
        self::assertNotNull($admin);
        $this->identity->signIn($admin->toCurrentUser(), null);
        $adminList = $this->app->handle(new Request(
            'GET', '/admin/bookings', query: ['q' => $foreignCode], requestId: $this->requestId('admin-list'),
        ));
        self::assertSame(200, $adminList->status());
        self::assertStringContainsString($foreignCode, $adminList->body());

        $adminRequestId = $this->requestId('admin-detail');
        $adminDetail = $this->app->handle(new Request(
            'GET', '/admin/bookings/' . $foreignCode, requestId: $adminRequestId, clientIp: '127.0.0.1',
        ));
        self::assertSame(200, $adminDetail->status());
        $audit = $this->pdo->prepare('SELECT HanhDong FROM NhatKyHeThong WHERE RequestID=?');
        $audit->execute([$adminRequestId]);
        self::assertSame('ADMIN_BOOKING_VIEW', $audit->fetchColumn());
    }

    public function test_customer_submits_waiver_and_partner_rejects_it_through_http(): void
    {
        $booking = $this->futureConfirmedBooking();
        $this->signIn((int) $booking['customerId']);

        $detail = $this->app->handle(new Request(
            'GET', '/account/bookings/' . $booking['code'], requestId: $this->requestId('waiver-detail'),
        ));
        self::assertSame(200, $detail->status());
        self::assertStringContainsString('/cancel-preview', $detail->body());
        self::assertStringContainsString('Yêu cầu miễn phí hủy', $detail->body());

        $createId = $this->requestId('waiver-create');
        $created = $this->app->handle(new Request(
            'POST',
            '/account/bookings/' . $booking['code'] . '/waiver',
            body: [
                '_token' => $this->session->csrfToken(),
                'reason' => 'Khách cần thay đổi lịch trình và xin cơ sở hỗ trợ.',
            ],
            requestId: $createId,
            clientIp: '127.0.0.1',
        ));
        self::assertSame(303, $created->status());
        $waiverId = (int) $this->pdo->query(
            'SELECT YeuCauHuyMienPhiID FROM YeuCauHuyMienPhi WHERE DatChoID=' . (int) $booking['id']
            . " AND TrangThai='PENDING' ORDER BY YeuCauHuyMienPhiID DESC LIMIT 1"
        )->fetchColumn();
        self::assertGreaterThan(0, $waiverId);
        $this->createdWaivers[] = $waiverId;
        self::assertSame('CONFIRMED', $this->scalar('SELECT TrangThai FROM DatCho WHERE DatChoID=' . (int) $booking['id']));

        $organizationId = (int) $this->pdo->query(
            'SELECT cs.ToChucDoiTacID FROM DatCho dc INNER JOIN CoSoLuuTru cs ON cs.CoSoLuuTruID=dc.CoSoLuuTruID '
            . 'WHERE dc.DatChoID=' . (int) $booking['id']
        )->fetchColumn();
        $partnerId = (int) $this->pdo->query(
            "SELECT NguoiDungID FROM ThanhVienDoiTac WHERE ToChucDoiTacID={$organizationId} LIMIT 1"
        )->fetchColumn();
        $user = (new UserRepository($this->pdo))->findById($partnerId);
        $organization = (new PartnerMembershipRepository($this->pdo))->findOrganizationForUser($partnerId);
        self::assertNotNull($user);
        self::assertNotNull($organization);
        $this->identity->signIn($user->toCurrentUser(), $organization);

        $queue = $this->app->handle(new Request('GET', '/partner/waivers', requestId: $this->requestId('waiver-list')));
        self::assertSame(200, $queue->status());
        self::assertStringContainsString('Yêu cầu #' . $waiverId, $queue->body());
        $decision = $this->app->handle(new Request(
            'POST',
            '/partner/waivers/' . $waiverId . '/decision',
            body: [
                '_token' => $this->session->csrfToken(),
                'decision' => 'REJECTED',
                'response' => 'Cơ sở chưa thể hỗ trợ yêu cầu này.',
            ],
            requestId: $this->requestId('waiver-reject'),
            clientIp: '127.0.0.1',
        ));
        self::assertSame(303, $decision->status());
        self::assertSame('REJECTED', $this->scalar(
            'SELECT TrangThai FROM YeuCauHuyMienPhi WHERE YeuCauHuyMienPhiID=' . $waiverId,
        ));
        self::assertSame('CONFIRMED', $this->scalar('SELECT TrangThai FROM DatCho WHERE DatChoID=' . (int) $booking['id']));
    }

    public function test_customer_previews_date_change_through_http_without_mutating_booking(): void
    {
        $booking = $this->pdo->query(
            "SELECT dc.DatChoID id, dc.MaDatCho code, dc.KhachHangID customerId,
                    dc.NgayNhanPhong checkIn, dc.NgayTraPhong checkOut, dc.TongTien grandTotal
             FROM DatCho dc INNER JOIN ThanhToan tt ON tt.DatChoID=dc.DatChoID
             WHERE dc.TrangThai='CONFIRMED' AND dc.ThoiDiemThanhToan='PAY_AT_PROPERTY'
               AND tt.TrangThai='NOT_TRACKED' AND dc.NgayNhanPhong>CURRENT_DATE()
             ORDER BY dc.NgayNhanPhong DESC, dc.DatChoID DESC LIMIT 1"
        )->fetch(PDO::FETCH_ASSOC);
        self::assertIsArray($booking);
        $this->signIn((int) $booking['customerId']);

        $detail = $this->app->handle(new Request(
            'GET', '/account/bookings/' . $booking['code'], requestId: $this->requestId('date-change-detail'),
        ));
        self::assertSame(200, $detail->status());
        self::assertStringContainsString('/date-change-preview', $detail->body());

        $checkIn = (new \DateTimeImmutable((string) $booking['checkIn']))->modify('+1 day')->format('Y-m-d');
        $checkOut = (new \DateTimeImmutable((string) $booking['checkOut']))->modify('+1 day')->format('Y-m-d');
        $preview = $this->app->handle(new Request(
            'POST',
            '/account/bookings/' . $booking['code'] . '/date-change-preview',
            body: ['_token' => $this->session->csrfToken(), 'checkIn' => $checkIn, 'checkOut' => $checkOut],
            requestId: $this->requestId('date-change-preview'),
            clientIp: '127.0.0.1',
        ));
        self::assertSame(200, $preview->status());
        self::assertStringContainsString('Xác nhận đổi ngày', $preview->body());
        self::assertStringContainsString('name="quoteFingerprint"', $preview->body());
        self::assertSame(
            $booking['checkIn'] . '|' . $booking['checkOut'] . '|' . $booking['grandTotal'],
            $this->scalar(
                "SELECT CONCAT(NgayNhanPhong,'|',NgayTraPhong,'|',TongTien) FROM DatCho WHERE DatChoID=" . (int) $booking['id'],
            ),
        );
    }

    /** @return array<string, mixed> */
    private function futureConfirmedBooking(): array
    {
        $row = $this->pdo->query(
            "SELECT DatChoID id, MaDatCho code, KhachHangID customerId, TenNguoiDat bookerName,
                    EmailNguoiDat bookerEmail, DienThoaiNguoiDat bookerPhone, YeuCauDacBiet specialRequest,
                    TongTien grandTotal
             FROM DatCho WHERE TrangThai='CONFIRMED' AND NgayNhanPhong > CURRENT_DATE()
             ORDER BY NgayNhanPhong DESC LIMIT 1"
        )->fetch(PDO::FETCH_ASSOC);
        self::assertIsArray($row);
        $this->originalBooking = $row;
        $statement = $this->pdo->prepare(
            'SELECT HangMucDatChoID id, KhachJSON partyJson FROM HangMucDatCho WHERE DatChoID=? ORDER BY HangMucDatChoID'
        );
        $statement->execute([(int) $row['id']]);
        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $item) {
            $this->originalParties[(int) $item['id']] = (string) $item['partyJson'];
        }
        return $row;
    }

    /** @return array<int, list<string>> */
    private function guestNames(): array
    {
        $result = [];
        foreach ($this->originalParties as $itemId => $json) {
            $party = json_decode($json, true, 32, JSON_THROW_ON_ERROR);
            $count = array_is_list($party) ? count($party) : (int) $party['adults'] + count($party['childAges']);
            for ($index = 1; $index <= $count; ++$index) {
                $result[$itemId][] = 'Khách HTTP ' . $itemId . '-' . $index;
            }
        }
        return $result;
    }

    private function signIn(int $userId): void
    {
        $user = (new UserRepository($this->pdo))->findById($userId);
        self::assertNotNull($user);
        $this->identity->signIn($user->toCurrentUser(), null);
    }

    private function requestId(string $name): string
    {
        $requestId = 'phase11-http-' . $name . '-' . bin2hex(random_bytes(4));
        $this->requestIds[] = $requestId;
        return $requestId;
    }

    private function ledgerCount(int $bookingId): int
    {
        return (int) $this->pdo->query(
            'SELECT COUNT(*) FROM GiuTonPhongDem gt INNER JOIN HangMucDatCho hm ON hm.HangMucDatChoID=gt.HangMucDatChoID '
            . 'WHERE hm.DatChoID=' . $bookingId
        )->fetchColumn();
    }

    private function scalar(string $sql): string
    {
        return (string) $this->pdo->query($sql)->fetchColumn();
    }
}

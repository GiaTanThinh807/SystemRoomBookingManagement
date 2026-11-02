<?php

declare(strict_types=1);

namespace Tests\Integration\Modules\Bookings;

use App\Bootstrap\App;
use App\Modules\Bookings\Application\BookingAccessService;
use App\Modules\Bookings\Application\BookingException;
use App\Modules\Bookings\Infrastructure\BookingRepository;
use App\Shared\Audit\DatabaseAuditTrail;
use App\Shared\Database\TransactionManager;
use PDO;
use Tests\Support\TestCase;

final class BookingAccessServiceTest extends TestCase
{
    private PDO $pdo;
    private BookingAccessService $service;
    /** @var list<string> */
    private array $auditRequestIds = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->pdo = App::boot(dirname(__DIR__, 4))->container()->get(PDO::class);
        $this->service = new BookingAccessService(
            new BookingRepository($this->pdo),
            new DatabaseAuditTrail($this->pdo),
            new TransactionManager($this->pdo, static fn (int $_): null => null),
        );
    }

    protected function tearDown(): void
    {
        foreach ($this->auditRequestIds as $requestId) {
            $this->pdo->prepare('DELETE FROM NhatKyHeThong WHERE RequestID=?')->execute([$requestId]);
        }
        parent::tearDown();
    }

    public function test_customer_list_is_paginated_and_detail_is_owner_scoped(): void
    {
        $row = $this->pdo->query(
            'SELECT KhachHangID customerId, MaDatCho code FROM DatCho ORDER BY DatChoID LIMIT 1'
        )->fetch(PDO::FETCH_ASSOC);
        self::assertIsArray($row);
        $otherCustomer = (int) $this->pdo->query(
            'SELECT NguoiDungID FROM NguoiDung WHERE VaiTro=\'CUSTOMER\' AND NguoiDungID<>' . (int) $row['customerId'] . ' ORDER BY NguoiDungID LIMIT 1'
        )->fetchColumn();

        $page = $this->service->customerBookings((int) $row['customerId'], 1);
        $owned = $this->service->customerBooking((string) $row['code'], (int) $row['customerId']);
        $forbidden = $this->service->customerBooking((string) $row['code'], $otherCustomer);

        self::assertGreaterThan(0, $page->total);
        self::assertLessThanOrEqual(10, count($page->items));
        self::assertSame(1, $page->page);
        self::assertNotNull($owned);
        self::assertSame((string) $row['code'], $owned->code);
        self::assertNull($forbidden);
    }

    public function test_partner_can_only_read_bookings_of_its_organization(): void
    {
        $row = $this->pdo->query(
            'SELECT dc.MaDatCho code, cs.ToChucDoiTacID organizationId
             FROM DatCho dc INNER JOIN CoSoLuuTru cs ON cs.CoSoLuuTruID=dc.CoSoLuuTruID
             ORDER BY dc.DatChoID LIMIT 1'
        )->fetch(PDO::FETCH_ASSOC);
        self::assertIsArray($row);
        $otherOrganization = (int) $this->pdo->query(
            'SELECT ToChucDoiTacID FROM ToChucDoiTac WHERE ToChucDoiTacID<>' . (int) $row['organizationId'] . ' ORDER BY ToChucDoiTacID LIMIT 1'
        )->fetchColumn();

        $page = $this->service->partnerBookings((int) $row['organizationId'], 1);
        $owned = $this->service->partnerBooking((string) $row['code'], (int) $row['organizationId']);
        $outside = $this->service->partnerBooking((string) $row['code'], $otherOrganization);

        self::assertGreaterThan(0, $page->total);
        self::assertLessThanOrEqual(20, count($page->items));
        self::assertNotNull($owned);
        self::assertNull($outside);
    }

    public function test_admin_support_view_is_audited_and_same_request_is_idempotent(): void
    {
        $codes = $this->pdo->query('SELECT MaDatCho FROM DatCho ORDER BY DatChoID LIMIT 2')
            ->fetchAll(PDO::FETCH_COLUMN);
        self::assertCount(2, $codes);
        $adminId = (int) $this->pdo->query(
            "SELECT NguoiDungID FROM NguoiDung WHERE VaiTro='ADMIN' AND TrangThai='HOAT_DONG' ORDER BY NguoiDungID LIMIT 1"
        )->fetchColumn();
        $requestId = 'phase11-admin-' . bin2hex(random_bytes(8));
        $this->auditRequestIds[] = $requestId;

        $first = $this->service->adminBooking((string) $codes[0], $adminId, $requestId, '127.0.0.1');
        $second = $this->service->adminBooking((string) $codes[0], $adminId, $requestId, '127.0.0.1');

        self::assertNotNull($first);
        self::assertSame($first->id, $second?->id);
        $statement = $this->pdo->prepare('SELECT COUNT(*) FROM NhatKyHeThong WHERE RequestID=? AND HanhDong=\'ADMIN_BOOKING_VIEW\'');
        $statement->execute([$requestId]);
        self::assertSame(1, (int) $statement->fetchColumn());

        try {
            $this->service->adminBooking((string) $codes[1], $adminId, $requestId, '127.0.0.1');
            self::fail('Một RequestID không được dùng để audit hai booking khác nhau.');
        } catch (BookingException $exception) {
            self::assertSame('IDEMPOTENCY_CONFLICT', $exception->errorCode);
        }
    }

    public function test_terminal_booking_detail_is_hydrated_from_saved_snapshot(): void
    {
        $row = $this->pdo->query(
            "SELECT MaDatCho, KhachHangID FROM DatCho WHERE TrangThai IN ('CANCELLED','NO_SHOW','COMPLETED') ORDER BY DatChoID LIMIT 1"
        )->fetch(PDO::FETCH_ASSOC);
        self::assertIsArray($row);

        $booking = $this->service->customerBooking((string) $row['MaDatCho'], (int) $row['KhachHangID']);

        self::assertNotNull($booking);
        self::assertNotEmpty($booking->items);
        self::assertNotEmpty($booking->items[0]['nights']);
        self::assertNotNull($booking->confirmedAt);
    }
}

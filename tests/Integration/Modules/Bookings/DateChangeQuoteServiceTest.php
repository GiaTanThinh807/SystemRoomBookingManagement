<?php

declare(strict_types=1);

namespace Tests\Integration\Modules\Bookings;

use App\Bootstrap\App;
use App\Modules\Bookings\Application\BookingException;
use App\Modules\Bookings\Application\DateChangeQuoteService;
use App\Modules\Bookings\Infrastructure\DateChangeRepository;
use App\Modules\Commercial\Application\StandardPriceCalculator;
use App\Shared\Clock\FrozenClock;
use App\Shared\Contract\StayPeriod;
use DateTimeImmutable;
use DateTimeZone;
use PDO;
use Tests\Support\TestCase;

final class DateChangeQuoteServiceTest extends TestCase
{
    private PDO $pdo;
    /** @var array<string, mixed> */
    private array $booking;

    protected function setUp(): void
    {
        parent::setUp();
        $this->pdo = App::boot(dirname(__DIR__, 4))->container()->get(PDO::class);
        $row = $this->pdo->query(
            "SELECT dc.DatChoID id, dc.MaDatCho code, dc.KhachHangID customerId,
                    dc.NgayNhanPhong checkIn, dc.NgayTraPhong checkOut,
                    cs.GioNhanPhong checkInTime, cs.MuiGio timezone
             FROM DatCho dc
             INNER JOIN ThanhToan tt ON tt.DatChoID=dc.DatChoID
             INNER JOIN CoSoLuuTru cs ON cs.CoSoLuuTruID=dc.CoSoLuuTruID
             WHERE dc.TrangThai='CONFIRMED' AND dc.ThoiDiemThanhToan='PAY_AT_PROPERTY'
               AND tt.TrangThai='NOT_TRACKED'
             ORDER BY dc.NgayNhanPhong DESC, dc.DatChoID DESC LIMIT 1"
        )->fetch(PDO::FETCH_ASSOC);
        self::assertIsArray($row);
        $this->booking = $row;
    }

    public function test_preview_reprices_without_mutating_booking_or_inventory(): void
    {
        $clock = new FrozenClock($this->cutoff()->modify('-30 days'));
        $service = new DateChangeQuoteService(
            new DateChangeRepository($this->pdo),
            new StandardPriceCalculator(),
            $clock,
        );
        $stay = $this->shiftedStay(1);
        $beforeBooking = $this->bookingState();
        $beforeLedger = $this->ledgerState();

        $first = $service->preview((string) $this->booking['code'], (int) $this->booking['customerId'], $stay);
        $clock->freeze($clock->now()->modify('+1 second'));
        $second = $service->preview((string) $this->booking['code'], (int) $this->booking['customerId'], $stay);

        self::assertSame($stay->checkIn, $first->stay->checkIn);
        self::assertSame($stay->checkOut, $first->stay->checkOut);
        self::assertSame($first->fingerprint, $second->fingerprint);
        self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $first->fingerprint);
        self::assertNotEmpty($first->items);
        self::assertSame($beforeBooking, $this->bookingState());
        self::assertSame($beforeLedger, $this->ledgerState());
    }

    public function test_preview_hides_foreign_booking_and_rejects_unchanged_dates(): void
    {
        $service = new DateChangeQuoteService(
            new DateChangeRepository($this->pdo),
            new StandardPriceCalculator(),
            new FrozenClock($this->cutoff()->modify('-30 days')),
        );
        try {
            $service->preview(
                (string) $this->booking['code'],
                (int) $this->booking['customerId'] + 1,
                $this->shiftedStay(1),
            );
            self::fail('Khách khác không được xem báo giá đổi ngày.');
        } catch (BookingException $exception) {
            self::assertSame('BOOKING_NOT_FOUND', $exception->errorCode);
        }
        try {
            $service->preview(
                (string) $this->booking['code'],
                (int) $this->booking['customerId'],
                new StayPeriod((string) $this->booking['checkIn'], (string) $this->booking['checkOut']),
            );
            self::fail('Khoảng ngày giữ nguyên phải bị từ chối.');
        } catch (BookingException $exception) {
            self::assertSame('DATE_CHANGE_UNCHANGED', $exception->errorCode);
        }
    }

    private function shiftedStay(int $days): StayPeriod
    {
        return new StayPeriod(
            (new DateTimeImmutable((string) $this->booking['checkIn']))->modify('+' . $days . ' days')->format('Y-m-d'),
            (new DateTimeImmutable((string) $this->booking['checkOut']))->modify('+' . $days . ' days')->format('Y-m-d'),
        );
    }

    private function cutoff(): DateTimeImmutable
    {
        return new DateTimeImmutable(
            (string) $this->booking['checkIn'] . ' ' . (string) $this->booking['checkInTime'],
            new DateTimeZone((string) $this->booking['timezone']),
        );
    }

    /** @return array<string, mixed> */
    private function bookingState(): array
    {
        $statement = $this->pdo->prepare('SELECT NgayNhanPhong, NgayTraPhong, TongTien FROM DatCho WHERE DatChoID=?');
        $statement->execute([(int) $this->booking['id']]);
        return $statement->fetch(PDO::FETCH_ASSOC) ?: [];
    }

    /** @return list<array<string, mixed>> */
    private function ledgerState(): array
    {
        $statement = $this->pdo->prepare(
            'SELECT gt.GiuTonPhongDemID, gt.NgayLuuTru, gt.TrangThai
             FROM GiuTonPhongDem gt
             INNER JOIN HangMucDatCho hm ON hm.HangMucDatChoID=gt.HangMucDatChoID
             WHERE hm.DatChoID=? ORDER BY gt.GiuTonPhongDemID'
        );
        $statement->execute([(int) $this->booking['id']]);
        return $statement->fetchAll(PDO::FETCH_ASSOC);
    }
}

<?php

declare(strict_types=1);

namespace Tests\Integration\Modules\Bookings;

use App\Bootstrap\App;
use App\Modules\Bookings\Application\BookingException;
use App\Modules\Bookings\Application\CancellationFeeService;
use App\Modules\Bookings\Application\CancellationQuoteService;
use App\Modules\Bookings\Infrastructure\CancellationRepository;
use App\Modules\Commercial\Application\CancellationCalculator;
use App\Shared\Clock\FrozenClock;
use DateTimeImmutable;
use DateTimeZone;
use PDO;
use Tests\Support\TestCase;

final class CancellationQuoteServiceTest extends TestCase
{
    private PDO $pdo;
    /** @var array<string, mixed> */
    private array $booking;

    protected function setUp(): void
    {
        parent::setUp();
        $this->pdo = App::boot(dirname(__DIR__, 4))->container()->get(PDO::class);
        $row = $this->pdo->query(
            "SELECT dc.MaDatCho code, dc.KhachHangID customerId, dc.NgayNhanPhong checkIn,
                    cs.GioNhanPhong checkInTime, cs.MuiGio timezone
             FROM DatCho dc INNER JOIN CoSoLuuTru cs ON cs.CoSoLuuTruID=dc.CoSoLuuTruID
             WHERE dc.TrangThai='CONFIRMED' ORDER BY dc.NgayNhanPhong DESC LIMIT 1"
        )->fetch(PDO::FETCH_ASSOC);
        self::assertIsArray($row);
        $this->booking = $row;
    }

    public function test_preview_uses_saved_snapshot_and_paid_amount(): void
    {
        $clock = new FrozenClock($this->cutoff()->modify('-1 hour'));
        $repository = new CancellationRepository($this->pdo);
        $context = $repository->findForCustomer((string) $this->booking['code'], (int) $this->booking['customerId']);
        self::assertIsArray($context);
        $service = new CancellationQuoteService(
            $repository,
            new CancellationFeeService(new CancellationCalculator()),
            $clock,
        );

        $quote = $service->preview((string) $this->booking['code'], (int) $this->booking['customerId']);

        self::assertSame((string) $this->booking['code'], $quote->bookingCode);
        self::assertSame($clock->now()->modify('+5 minutes')->getTimestamp(), $quote->validUntil->getTimestamp());
        self::assertNotEmpty($quote->items);
        self::assertGreaterThanOrEqual(0, $quote->fee->amount());
        self::assertGreaterThanOrEqual(0, $quote->expectedRefund->amount());
        self::assertSame(
            max(0, (int) $context['paid'] - $quote->fee->amount()),
            $quote->expectedRefund->amount(),
        );
    }

    public function test_preview_hides_foreign_booking_and_rejects_at_cutoff(): void
    {
        $service = new CancellationQuoteService(
            new CancellationRepository($this->pdo),
            new CancellationFeeService(new CancellationCalculator()),
            new FrozenClock($this->cutoff()),
        );
        try {
            $service->preview((string) $this->booking['code'], (int) $this->booking['customerId'] + 1);
            self::fail('Customer khác không được xem báo giá hủy.');
        } catch (BookingException $exception) {
            self::assertSame('BOOKING_NOT_FOUND', $exception->errorCode);
        }
        try {
            $service->preview((string) $this->booking['code'], (int) $this->booking['customerId']);
            self::fail('Không được báo giá hủy tại cutoff.');
        } catch (BookingException $exception) {
            self::assertSame('BOOKING_CUTOFF_PASSED', $exception->errorCode);
        }
    }

    private function cutoff(): DateTimeImmutable
    {
        return new DateTimeImmutable(
            (string) $this->booking['checkIn'] . ' ' . (string) $this->booking['checkInTime'],
            new DateTimeZone((string) $this->booking['timezone']),
        );
    }
}

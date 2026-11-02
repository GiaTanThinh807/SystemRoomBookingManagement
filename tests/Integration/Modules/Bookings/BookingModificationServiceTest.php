<?php

declare(strict_types=1);

namespace Tests\Integration\Modules\Bookings;

use App\Bootstrap\App;
use App\Modules\Bookings\Application\BookingException;
use App\Modules\Bookings\Application\BookingGuestDetailsRequest;
use App\Modules\Bookings\Application\BookingModificationService;
use App\Modules\Bookings\Infrastructure\BookingRepository;
use App\Shared\Audit\DatabaseAuditTrail;
use App\Shared\Clock\FrozenClock;
use App\Shared\Database\TransactionManager;
use DateTimeImmutable;
use DateTimeZone;
use PDO;
use Tests\Support\TestCase;

final class BookingModificationServiceTest extends TestCase
{
    private PDO $pdo;
    /** @var array<string, mixed> */
    private array $booking;
    /** @var array<int, string> */
    private array $partyJson = [];
    /** @var list<string> */
    private array $requestIds = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->pdo = App::boot(dirname(__DIR__, 4))->container()->get(PDO::class);
        $row = $this->pdo->query(
            "SELECT dc.DatChoID id, dc.MaDatCho code, dc.KhachHangID customerId,
                    dc.TenNguoiDat bookerName, dc.EmailNguoiDat bookerEmail,
                    dc.DienThoaiNguoiDat bookerPhone, dc.YeuCauDacBiet specialRequest,
                    dc.NgayNhanPhong checkIn, cs.GioNhanPhong checkInTime, cs.MuiGio timezone
             FROM DatCho dc INNER JOIN CoSoLuuTru cs ON cs.CoSoLuuTruID=dc.CoSoLuuTruID
             WHERE dc.TrangThai='CONFIRMED' ORDER BY dc.DatChoID LIMIT 1"
        )->fetch(PDO::FETCH_ASSOC);
        self::assertIsArray($row);
        $this->booking = $row;
        $statement = $this->pdo->prepare(
            'SELECT HangMucDatChoID id, KhachJSON partyJson FROM HangMucDatCho WHERE DatChoID=? ORDER BY HangMucDatChoID'
        );
        $statement->execute([(int) $row['id']]);
        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $item) {
            $this->partyJson[(int) $item['id']] = (string) $item['partyJson'];
        }
        self::assertNotEmpty($this->partyJson);
    }

    protected function tearDown(): void
    {
        if (isset($this->pdo, $this->booking)) {
            $this->pdo->prepare(
                'UPDATE DatCho SET TenNguoiDat=?, EmailNguoiDat=?, DienThoaiNguoiDat=?, YeuCauDacBiet=? WHERE DatChoID=?'
            )->execute([
                $this->booking['bookerName'],
                $this->booking['bookerEmail'],
                $this->booking['bookerPhone'],
                $this->booking['specialRequest'],
                $this->booking['id'],
            ]);
            $statement = $this->pdo->prepare('UPDATE HangMucDatCho SET KhachJSON=? WHERE HangMucDatChoID=?');
            foreach ($this->partyJson as $itemId => $json) {
                $statement->execute([$json, $itemId]);
            }
            foreach ($this->requestIds as $requestId) {
                $this->pdo->prepare('DELETE FROM NhatKyHeThong WHERE RequestID=?')->execute([$requestId]);
            }
        }
        parent::tearDown();
    }

    public function test_updates_contact_and_guest_names_without_changing_price_or_inventory(): void
    {
        $clock = new FrozenClock($this->cutoff()->modify('-1 minute'));
        $requestId = $this->requestId('update');
        $beforeTotal = $this->scalar('SELECT TongTien FROM DatCho WHERE DatChoID=' . (int) $this->booking['id']);
        $beforeLedger = $this->ledgerCount();
        $request = $this->request();

        $updated = $this->service($clock)->updateGuestDetails(
            (string) $this->booking['code'],
            (int) $this->booking['customerId'],
            $request,
            $requestId,
            '127.0.0.1',
        );

        self::assertSame('Nguyễn Khách Đã Sửa', $updated->bookerName);
        self::assertSame('guest-updated@example.test', $updated->bookerEmail);
        self::assertSame($beforeTotal, $this->scalar('SELECT TongTien FROM DatCho WHERE DatChoID=' . (int) $this->booking['id']));
        self::assertSame($beforeLedger, $this->ledgerCount());
        foreach ($updated->items as $item) {
            $original = json_decode($this->partyJson[(int) $item['id']], true, 32, JSON_THROW_ON_ERROR);
            if (array_is_list($original)) {
                foreach ($original as $index => $guest) {
                    self::assertSame($guest['type'], $item['party'][$index]['type']);
                    self::assertSame($guest['age'], $item['party'][$index]['age']);
                    self::assertSame($request->guestNames[(int) $item['id']][$index], $item['party'][$index]['name']);
                }
            } else {
                self::assertSame($original['adults'], $item['party']['adults']);
                self::assertSame($original['childAges'], $item['party']['childAges']);
                self::assertSame($request->guestNames[(int) $item['id']], $item['party']['guestNames']);
            }
        }
        $audit = (new DatabaseAuditTrail($this->pdo))->findByRequestId($requestId);
        self::assertSame('BOOKING_GUEST_DETAILS_UPDATED', $audit['action'] ?? null);
        self::assertSame($this->booking['bookerEmail'], $audit['before']['bookerEmail'] ?? null);
        self::assertSame('guest-updated@example.test', $audit['after']['bookerEmail'] ?? null);
    }

    public function test_rejects_at_and_after_local_checkin_cutoff(): void
    {
        foreach ([$this->cutoff(), $this->cutoff()->modify('+1 second')] as $index => $time) {
            $requestId = $this->requestId('cutoff-' . $index);
            try {
                $this->service(new FrozenClock($time))->updateGuestDetails(
                    (string) $this->booking['code'],
                    (int) $this->booking['customerId'],
                    $this->request(),
                    $requestId,
                    '127.0.0.1',
                );
                self::fail('Không được sửa booking tại hoặc sau cutoff nhận phòng.');
            } catch (BookingException $exception) {
                self::assertSame('BOOKING_CUTOFF_PASSED', $exception->errorCode);
            }
        }
        self::assertSame($this->booking['bookerEmail'], $this->scalar(
            'SELECT EmailNguoiDat FROM DatCho WHERE DatChoID=' . (int) $this->booking['id'],
        ));
    }

    public function test_retry_same_request_is_idempotent_even_after_cutoff_and_conflict_is_rejected(): void
    {
        $clock = new FrozenClock($this->cutoff()->modify('-1 minute'));
        $requestId = $this->requestId('retry');
        $request = $this->request();
        $service = $this->service($clock);
        $first = $service->updateGuestDetails(
            (string) $this->booking['code'],
            (int) $this->booking['customerId'],
            $request,
            $requestId,
            '127.0.0.1',
        );
        $clock->freeze($this->cutoff());
        $replayed = $service->updateGuestDetails(
            (string) $this->booking['code'],
            (int) $this->booking['customerId'],
            $request,
            $requestId,
            '127.0.0.1',
        );

        self::assertSame($first->id, $replayed->id);
        $statement = $this->pdo->prepare('SELECT COUNT(*) FROM NhatKyHeThong WHERE RequestID=?');
        $statement->execute([$requestId]);
        self::assertSame(1, (int) $statement->fetchColumn());

        try {
            $service->updateGuestDetails(
                (string) $this->booking['code'],
                (int) $this->booking['customerId'],
                BookingGuestDetailsRequest::fromInput([
                    'bookerName' => 'Payload Khác',
                    'bookerEmail' => 'other-payload@example.test',
                    'bookerPhone' => '0909999999',
                ]),
                $requestId,
                '127.0.0.1',
            );
            self::fail('Cùng RequestID nhưng payload khác phải xung đột.');
        } catch (BookingException $exception) {
            self::assertSame('IDEMPOTENCY_CONFLICT', $exception->errorCode);
        }
    }

    public function test_invalid_guest_count_rolls_back_contact_and_audit(): void
    {
        $requestId = $this->requestId('invalid-guests');
        $input = [
            'bookerName' => 'Không Được Lưu',
            'bookerEmail' => 'must-rollback@example.test',
            'bookerPhone' => '0901234567',
            'guestNames' => $this->guestNames(),
        ];
        $firstItemId = array_key_first($input['guestNames']);
        $input['guestNames'][$firstItemId][] = 'Khách dư ngoài snapshot';

        try {
            $this->service(new FrozenClock($this->cutoff()->modify('-1 minute')))->updateGuestDetails(
                (string) $this->booking['code'],
                (int) $this->booking['customerId'],
                BookingGuestDetailsRequest::fromInput($input),
                $requestId,
                '127.0.0.1',
            );
            self::fail('Số tên khách sai phải rollback toàn bộ.');
        } catch (BookingException $exception) {
            self::assertSame('GUEST_DETAILS_INVALID', $exception->errorCode);
        }
        self::assertSame($this->booking['bookerEmail'], $this->scalar(
            'SELECT EmailNguoiDat FROM DatCho WHERE DatChoID=' . (int) $this->booking['id'],
        ));
        self::assertNull((new DatabaseAuditTrail($this->pdo))->findByRequestId($requestId));
    }

    private function service(FrozenClock $clock): BookingModificationService
    {
        return new BookingModificationService(
            new BookingRepository($this->pdo),
            new TransactionManager($this->pdo, static fn (int $_): null => null),
            new DatabaseAuditTrail($this->pdo),
            $clock,
        );
    }

    private function request(): BookingGuestDetailsRequest
    {
        return BookingGuestDetailsRequest::fromInput([
            'bookerName' => 'Nguyễn Khách Đã Sửa',
            'bookerEmail' => 'guest-updated@example.test',
            'bookerPhone' => '0901234567',
            'specialRequest' => 'Nhận phòng yên tĩnh.',
            'guestNames' => $this->guestNames(),
        ]);
    }

    /** @return array<int, list<string>> */
    private function guestNames(): array
    {
        $names = [];
        foreach ($this->partyJson as $itemId => $json) {
            $party = json_decode($json, true, 32, JSON_THROW_ON_ERROR);
            $count = array_is_list($party)
                ? count($party)
                : (int) $party['adults'] + count($party['childAges']);
            $names[$itemId] = [];
            for ($index = 1; $index <= $count; ++$index) {
                $names[$itemId][] = 'Khách phòng ' . $itemId . ' số ' . $index;
            }
        }
        return $names;
    }

    private function cutoff(): DateTimeImmutable
    {
        return new DateTimeImmutable(
            (string) $this->booking['checkIn'] . ' ' . (string) $this->booking['checkInTime'],
            new DateTimeZone((string) $this->booking['timezone']),
        );
    }

    private function requestId(string $suffix): string
    {
        $requestId = 'phase11-' . $suffix . '-' . bin2hex(random_bytes(6));
        $this->requestIds[] = $requestId;
        return $requestId;
    }

    private function ledgerCount(): int
    {
        return (int) $this->pdo->query(
            'SELECT COUNT(*) FROM GiuTonPhongDem gt INNER JOIN HangMucDatCho hm ON hm.HangMucDatChoID=gt.HangMucDatChoID WHERE hm.DatChoID=' . (int) $this->booking['id']
        )->fetchColumn();
    }

    private function scalar(string $sql): string
    {
        return (string) $this->pdo->query($sql)->fetchColumn();
    }
}

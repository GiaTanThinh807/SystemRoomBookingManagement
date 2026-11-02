<?php

declare(strict_types=1);

namespace App\Modules\Bookings\Infrastructure;

use App\Shared\Json\CanonicalJson;
use App\Shared\Money\Money;
use DateTimeImmutable;
use DateTimeZone;
use JsonException;
use PDO;
use RuntimeException;

final readonly class CancellationRepository
{
    public function __construct(private PDO $pdo)
    {
    }

    /** @return array<string, mixed>|null */
    public function findForCustomer(string $code, int $customerId): ?array
    {
        $statement = $this->pdo->prepare($this->contextSql(false));
        $statement->execute(['code' => $code, 'customerId' => $customerId]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $this->hydrate($row) : null;
    }

    /** @return array{bookingId: int, customerId: int}|null */
    public function findByCancellationKey(string $cancellationKey): ?array
    {
        $statement = $this->pdo->prepare(
            'SELECT DatChoID AS bookingId, KhachHangID AS customerId FROM DatCho WHERE HuyIdempotencyKey=?'
        );
        $statement->execute([$cancellationKey]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        return is_array($row)
            ? ['bookingId' => (int) $row['bookingId'], 'customerId' => (int) $row['customerId']]
            : null;
    }

    /** @return array<string, mixed>|null */
    public function lockForCustomer(string $code, int $customerId): ?array
    {
        $this->assertTransaction();
        $statement = $this->pdo->prepare(
            'SELECT DatChoID FROM DatCho WHERE MaDatCho=:code AND KhachHangID=:customerId FOR UPDATE'
        );
        $statement->execute(['code' => $code, 'customerId' => $customerId]);
        if ($statement->fetchColumn() === false) {
            return null;
        }
        return $this->findForCustomer($code, $customerId);
    }

    /** @return array<string, mixed>|null */
    public function lockById(int $bookingId): ?array
    {
        $this->assertTransaction();
        $statement = $this->pdo->prepare('SELECT MaDatCho, KhachHangID FROM DatCho WHERE DatChoID=? FOR UPDATE');
        $statement->execute([$bookingId]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        return is_array($row)
            ? $this->findForCustomer((string) $row['MaDatCho'], (int) $row['KhachHangID'])
            : null;
    }

    /** @param array<string, mixed> $snapshot */
    public function markCancelled(
        int $bookingId,
        string $cancellationKey,
        int $actorId,
        string $reason,
        Money $fee,
        array $snapshot,
        DateTimeImmutable $at,
    ): void {
        $statement = $this->pdo->prepare(
            "UPDATE DatCho SET TrangThai='CANCELLED', HuyIdempotencyKey=:cancellationKey,
                    NguoiHuyID=:actorId, HuyLuc=:cancelledAt, LyDoHuy=:reason,
                    PhiHuy=:fee, SoTienHoan=0, TinhHuySnapshotJSON=:snapshot
             WHERE DatChoID=:bookingId AND TrangThai='CONFIRMED' AND HuyIdempotencyKey IS NULL"
        );
        $statement->execute([
            'cancellationKey' => $cancellationKey,
            'actorId' => $actorId,
            'cancelledAt' => $at->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s.u'),
            'reason' => $reason,
            'fee' => $fee->decimal(),
            'snapshot' => CanonicalJson::encode($snapshot),
            'bookingId' => $bookingId,
        ]);
        if ($statement->rowCount() !== 1) {
            throw new RuntimeException('Không thể chuyển booking sang CANCELLED.');
        }
    }

    public function markRefunded(int $bookingId, Money $amount): void
    {
        $statement = $this->pdo->prepare(
            "UPDATE DatCho SET SoTienHoan=:amount
             WHERE DatChoID=:bookingId AND TrangThai='CANCELLED' AND SoTienHoan=0"
        );
        $statement->execute(['amount' => $amount->decimal(), 'bookingId' => $bookingId]);
        if ($statement->rowCount() !== 1) {
            throw new RuntimeException('Không thể ghi số tiền hoàn thành công vào booking.');
        }
    }

    private function contextSql(bool $lock): string
    {
        return 'SELECT dc.DatChoID AS id, dc.MaDatCho AS code, dc.KhachHangID AS customerId,
                       cs.ToChucDoiTacID AS organizationId, dc.TrangThai AS status,
                       dc.ThoiDiemThanhToan AS paymentTiming, dc.TongTien AS grandTotal,
                       dc.NgayNhanPhong AS checkIn, dc.NgayTraPhong AS checkOut,
                       cs.GioNhanPhong AS checkInTime, cs.MuiGio AS timezone,
                       dc.HuyIdempotencyKey AS cancellationKey, dc.NguoiHuyID AS cancelledBy,
                       dc.LyDoHuy AS cancellationReason, dc.PhiHuy AS cancellationFee,
                       dc.SoTienHoan AS refundedAmount, dc.TinhHuySnapshotJSON AS calculationJson,
                       COALESCE(tt.DaThanhToan, 0) AS paid, COALESCE(tt.DaHoan, 0) AS paymentRefunded,
                       COALESCE(tt.TrangThaiHoanTien, \'NONE\') AS refundStatus
                FROM DatCho dc
                INNER JOIN CoSoLuuTru cs ON cs.CoSoLuuTruID=dc.CoSoLuuTruID
                LEFT JOIN ThanhToan tt ON tt.DatChoID=dc.DatChoID
                WHERE dc.MaDatCho=:code AND dc.KhachHangID=:customerId'
                . ($lock ? ' FOR UPDATE' : '');
    }

    /** @param array<string, mixed> $row @return array<string, mixed> */
    private function hydrate(array $row): array
    {
        $row['id'] = (int) $row['id'];
        $row['customerId'] = (int) $row['customerId'];
        $row['organizationId'] = (int) $row['organizationId'];
        $row['cancelledBy'] = $row['cancelledBy'] === null ? null : (int) $row['cancelledBy'];
        $row['calculation'] = $row['calculationJson'] === null ? null : $this->decode((string) $row['calculationJson']);
        unset($row['calculationJson']);
        $row['items'] = $this->items((int) $row['id']);
        return $row;
    }

    /** @return list<array<string, mixed>> */
    private function items(int $bookingId): array
    {
        $statement = $this->pdo->prepare(
            'SELECT HangMucDatChoID AS id, TenLoaiPhongSnapshot AS roomTypeName,
                    TongTien AS total, SnapshotHuyJSON AS cancellationJson
             FROM HangMucDatCho WHERE DatChoID=:bookingId ORDER BY HangMucDatChoID'
        );
        $statement->execute(['bookingId' => $bookingId]);
        $items = [];
        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $items[] = [
                'id' => (int) $row['id'],
                'roomTypeName' => (string) $row['roomTypeName'],
                'total' => (string) $row['total'],
                'cancellationSnapshot' => $this->decode((string) $row['cancellationJson']),
                'nights' => $this->nights((int) $row['id']),
            ];
        }
        return $items;
    }

    /** @return list<array{stayDate: string, total: string}> */
    private function nights(int $bookingItemId): array
    {
        $statement = $this->pdo->prepare(
            'SELECT NgayLuuTru AS stayDate, ThanhTien AS total
             FROM GiaDemDatCho WHERE HangMucDatChoID=:itemId ORDER BY NgayLuuTru'
        );
        $statement->execute(['itemId' => $bookingItemId]);
        return array_map(static fn (array $row): array => [
            'stayDate' => (string) $row['stayDate'],
            'total' => (string) $row['total'],
        ], $statement->fetchAll(PDO::FETCH_ASSOC));
    }

    /** @return array<string, mixed> */
    private function decode(string $json): array
    {
        try {
            $value = json_decode($json, true, 64, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException('Snapshot hủy trong database không hợp lệ.', 0, $exception);
        }
        if (!is_array($value)) {
            throw new RuntimeException('Snapshot hủy phải là object.');
        }
        return $value;
    }

    private function assertTransaction(): void
    {
        if (!$this->pdo->inTransaction()) {
            throw new RuntimeException('Booking hủy chỉ được khóa trong transaction.');
        }
    }
}

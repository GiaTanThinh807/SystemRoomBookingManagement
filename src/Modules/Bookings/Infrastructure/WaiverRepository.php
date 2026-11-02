<?php

declare(strict_types=1);

namespace App\Modules\Bookings\Infrastructure;

use DateTimeImmutable;
use DateTimeZone;
use PDO;
use RuntimeException;

final readonly class WaiverRepository
{
    public function __construct(private PDO $pdo)
    {
    }

    public function hasPending(int $bookingId): bool
    {
        $statement = $this->pdo->prepare(
            "SELECT 1 FROM YeuCauHuyMienPhi WHERE DatChoID=? AND TrangThai='PENDING' LIMIT 1"
        );
        $statement->execute([$bookingId]);
        return $statement->fetchColumn() !== false;
    }

    public function create(int $bookingId, int $customerId, string $reason, DateTimeImmutable $at): int
    {
        $statement = $this->pdo->prepare(
            'INSERT INTO YeuCauHuyMienPhi (DatChoID, NguoiYeuCauID, LyDo, TrangThai, TaoLuc)
             VALUES (:bookingId, :customerId, :reason, \'PENDING\', :createdAt)'
        );
        $statement->execute([
            'bookingId' => $bookingId,
            'customerId' => $customerId,
            'reason' => $reason,
            'createdAt' => $this->utc($at),
        ]);
        return (int) $this->pdo->lastInsertId();
    }

    /** @return array{bookingId: int, customerId: int, organizationId: int}|null */
    public function findScope(int $waiverId): ?array
    {
        $statement = $this->pdo->prepare(
            'SELECT w.DatChoID AS bookingId, w.NguoiYeuCauID AS customerId,
                    cs.ToChucDoiTacID AS organizationId
             FROM YeuCauHuyMienPhi w
             INNER JOIN DatCho dc ON dc.DatChoID=w.DatChoID
             INNER JOIN CoSoLuuTru cs ON cs.CoSoLuuTruID=dc.CoSoLuuTruID
             WHERE w.YeuCauHuyMienPhiID=?'
        );
        $statement->execute([$waiverId]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? [
            'bookingId' => (int) $row['bookingId'],
            'customerId' => (int) $row['customerId'],
            'organizationId' => (int) $row['organizationId'],
        ] : null;
    }

    /** @return array<string, mixed>|null */
    public function lockForOrganization(int $waiverId, int $organizationId): ?array
    {
        $this->assertTransaction();
        $statement = $this->pdo->prepare(
            'SELECT w.YeuCauHuyMienPhiID AS id, w.DatChoID AS bookingId,
                    w.NguoiYeuCauID AS customerId, w.LyDo AS reason,
                    w.TrangThai AS status, w.NguoiXuLyID AS handledBy,
                    w.PhanHoi AS response, w.TaoLuc AS createdAt, w.XuLyLuc AS handledAt
             FROM YeuCauHuyMienPhi w
             INNER JOIN DatCho dc ON dc.DatChoID=w.DatChoID
             INNER JOIN CoSoLuuTru cs ON cs.CoSoLuuTruID=dc.CoSoLuuTruID
             WHERE w.YeuCauHuyMienPhiID=:waiverId AND cs.ToChucDoiTacID=:organizationId
             FOR UPDATE'
        );
        $statement->execute(['waiverId' => $waiverId, 'organizationId' => $organizationId]);
        return $this->hydrate($statement->fetch(PDO::FETCH_ASSOC));
    }

    /** @return array<string, mixed>|null */
    public function lockForCustomer(int $waiverId, int $customerId, int $bookingId): ?array
    {
        $this->assertTransaction();
        $statement = $this->pdo->prepare(
            'SELECT YeuCauHuyMienPhiID AS id, DatChoID AS bookingId,
                    NguoiYeuCauID AS customerId, LyDo AS reason,
                    TrangThai AS status, NguoiXuLyID AS handledBy,
                    PhanHoi AS response, TaoLuc AS createdAt, XuLyLuc AS handledAt
             FROM YeuCauHuyMienPhi
             WHERE YeuCauHuyMienPhiID=:waiverId AND NguoiYeuCauID=:customerId AND DatChoID=:bookingId
             FOR UPDATE'
        );
        $statement->execute([
            'waiverId' => $waiverId,
            'customerId' => $customerId,
            'bookingId' => $bookingId,
        ]);
        return $this->hydrate($statement->fetch(PDO::FETCH_ASSOC));
    }

    public function decide(
        int $waiverId,
        string $status,
        int $actorId,
        string $response,
        DateTimeImmutable $at,
    ): void {
        $statement = $this->pdo->prepare(
            "UPDATE YeuCauHuyMienPhi
             SET TrangThai=:status, NguoiXuLyID=:actorId, PhanHoi=:response, XuLyLuc=:handledAt
             WHERE YeuCauHuyMienPhiID=:waiverId AND TrangThai='PENDING'"
        );
        $statement->execute([
            'status' => $status,
            'actorId' => $actorId,
            'response' => $response,
            'handledAt' => $this->utc($at),
            'waiverId' => $waiverId,
        ]);
        if ($statement->rowCount() !== 1) {
            throw new RuntimeException('Không thể chốt yêu cầu miễn phí hủy.');
        }
    }

    public function withdraw(int $waiverId, DateTimeImmutable $at): void
    {
        $statement = $this->pdo->prepare(
            "UPDATE YeuCauHuyMienPhi
             SET TrangThai='WITHDRAWN', PhanHoi='Khách hàng đã rút yêu cầu.', XuLyLuc=:handledAt
             WHERE YeuCauHuyMienPhiID=:waiverId AND TrangThai='PENDING'"
        );
        $statement->execute(['handledAt' => $this->utc($at), 'waiverId' => $waiverId]);
        if ($statement->rowCount() !== 1) {
            throw new RuntimeException('Không thể rút yêu cầu miễn phí hủy.');
        }
    }

    /** @return list<array<string, mixed>> */
    public function listForBooking(int $bookingId, int $customerId): array
    {
        $statement = $this->pdo->prepare(
            'SELECT w.YeuCauHuyMienPhiID AS id, w.DatChoID AS bookingId,
                    w.NguoiYeuCauID AS customerId, w.LyDo AS reason,
                    w.TrangThai AS status, w.NguoiXuLyID AS handledBy,
                    w.PhanHoi AS response, w.TaoLuc AS createdAt, w.XuLyLuc AS handledAt
             FROM YeuCauHuyMienPhi w
             INNER JOIN DatCho dc ON dc.DatChoID=w.DatChoID
             WHERE w.DatChoID=:bookingId AND dc.KhachHangID=:customerId
             ORDER BY w.YeuCauHuyMienPhiID DESC'
        );
        $statement->execute(['bookingId' => $bookingId, 'customerId' => $customerId]);
        return array_map(fn (array $row): array => $this->hydrate($row) ?? [], $statement->fetchAll(PDO::FETCH_ASSOC));
    }

    /** @return list<array<string, mixed>> */
    public function listPendingForOrganization(int $organizationId): array
    {
        $statement = $this->pdo->prepare(
            "SELECT w.YeuCauHuyMienPhiID AS id, w.DatChoID AS bookingId,
                    w.NguoiYeuCauID AS customerId, w.LyDo AS reason,
                    w.TrangThai AS status, w.NguoiXuLyID AS handledBy,
                    w.PhanHoi AS response, w.TaoLuc AS createdAt, w.XuLyLuc AS handledAt,
                    dc.MaDatCho AS bookingCode, dc.NgayNhanPhong AS checkIn,
                    dc.NgayTraPhong AS checkOut, cs.TenCoSo AS propertyName
             FROM YeuCauHuyMienPhi w
             INNER JOIN DatCho dc ON dc.DatChoID=w.DatChoID
             INNER JOIN CoSoLuuTru cs ON cs.CoSoLuuTruID=dc.CoSoLuuTruID
             WHERE cs.ToChucDoiTacID=:organizationId AND w.TrangThai='PENDING'
             ORDER BY w.TaoLuc, w.YeuCauHuyMienPhiID"
        );
        $statement->execute(['organizationId' => $organizationId]);
        return array_map(fn (array $row): array => [
            ...($this->hydrate($row) ?? []),
            'bookingCode' => (string) $row['bookingCode'],
            'checkIn' => (string) $row['checkIn'],
            'checkOut' => (string) $row['checkOut'],
            'propertyName' => (string) $row['propertyName'],
        ], $statement->fetchAll(PDO::FETCH_ASSOC));
    }

    /** @param array<string, mixed>|false $row @return array<string, mixed>|null */
    private function hydrate(array|false $row): ?array
    {
        if (!is_array($row)) {
            return null;
        }
        $row['id'] = (int) $row['id'];
        $row['bookingId'] = (int) $row['bookingId'];
        $row['customerId'] = (int) $row['customerId'];
        $row['handledBy'] = $row['handledBy'] === null ? null : (int) $row['handledBy'];
        return $row;
    }

    private function utc(DateTimeImmutable $at): string
    {
        return $at->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s.u');
    }

    private function assertTransaction(): void
    {
        if (!$this->pdo->inTransaction()) {
            throw new RuntimeException('Yêu cầu miễn phí hủy chỉ được khóa trong transaction.');
        }
    }
}

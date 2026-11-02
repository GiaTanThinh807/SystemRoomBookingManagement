<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Infrastructure;

use App\Modules\Inventory\Application\BookingInventoryItem;
use App\Shared\Contract\InventoryDemand;
use DateTimeImmutable;
use DateTimeZone;
use DomainException;
use InvalidArgumentException;
use PDO;

final readonly class InventoryRepository
{
    public function __construct(private PDO $pdo)
    {
    }

    /** @return list<array<string, mixed>> */
    public function listForRoomType(
        int $roomTypeId,
        int $organizationId,
        string $fromDate,
        string $toDate,
    ): array {
        self::assertIds($roomTypeId, $organizationId);
        self::assertDateRange($fromDate, $toDate);
        $statement = $this->pdo->prepare(
            'SELECT tp.LoaiPhongID AS roomTypeId, lp.CoSoLuuTruID AS propertyId,
                    tp.NgayLuuTru AS stayDate, tp.TongSoLuong AS totalQuantity,
                    tp.SoLuongDaGiu AS heldQuantity,
                    tp.TongSoLuong - tp.SoLuongDaGiu AS availableQuantity,
                    tp.PhienBan AS version, tp.CapNhatLuc AS updatedAt
             FROM TonPhongNgay tp
             INNER JOIN LoaiPhong lp ON lp.LoaiPhongID = tp.LoaiPhongID
             INNER JOIN CoSoLuuTru cs ON cs.CoSoLuuTruID = lp.CoSoLuuTruID
             WHERE tp.LoaiPhongID = :roomTypeId AND cs.ToChucDoiTacID = :organizationId
                 AND tp.NgayLuuTru BETWEEN :fromDate AND :toDate
             ORDER BY tp.NgayLuuTru ASC'
        );
        $statement->execute([
            'roomTypeId' => $roomTypeId,
            'organizationId' => $organizationId,
            'fromDate' => $fromDate,
            'toDate' => $toDate,
        ]);
        return $statement->fetchAll(PDO::FETCH_ASSOC);
    }

    /** @return array<string, mixed>|null */
    public function findForOrganization(int $roomTypeId, string $stayDate, int $organizationId): ?array
    {
        $rows = $this->listForRoomType($roomTypeId, $organizationId, $stayDate, $stayDate);
        return $rows[0] ?? null;
    }

    /**
     * Ghi tổng tồn bằng một statement nguyên tử. Khi counter đã lớn hơn tổng mới,
     * statement giữ nguyên dữ liệu và trả về null.
     *
     * @return array<string, mixed>|null
     */
    public function upsertTotal(
        int $roomTypeId,
        int $organizationId,
        string $stayDate,
        int $totalQuantity,
    ): ?array {
        self::assertIds($roomTypeId, $organizationId);
        self::assertDateRange($stayDate, $stayDate);
        if ($totalQuantity < 0 || $totalQuantity > 65_535) {
            throw new InvalidArgumentException('Tổng số phòng nằm ngoài giới hạn lưu trữ.');
        }

        $statement = $this->pdo->prepare(
            'INSERT INTO TonPhongNgay
                (LoaiPhongID, NgayLuuTru, TongSoLuong, SoLuongDaGiu, PhienBan, CapNhatLuc)
             SELECT lp.LoaiPhongID, :stayDate, :totalQuantity, 0, 1, CURRENT_TIMESTAMP(6)
             FROM LoaiPhong lp
             INNER JOIN CoSoLuuTru cs ON cs.CoSoLuuTruID = lp.CoSoLuuTruID
             WHERE lp.LoaiPhongID = :roomTypeId AND cs.ToChucDoiTacID = :organizationId
             ON DUPLICATE KEY UPDATE
                TongSoLuong = IF(
                    TonPhongNgay.SoLuongDaGiu <= VALUES(TongSoLuong),
                    VALUES(TongSoLuong), TonPhongNgay.TongSoLuong
                ),
                PhienBan = IF(
                    TonPhongNgay.SoLuongDaGiu <= VALUES(TongSoLuong),
                    TonPhongNgay.PhienBan + 1, TonPhongNgay.PhienBan
                ),
                CapNhatLuc = IF(
                    TonPhongNgay.SoLuongDaGiu <= VALUES(TongSoLuong),
                    CURRENT_TIMESTAMP(6), TonPhongNgay.CapNhatLuc
                )'
        );
        $statement->execute([
            'roomTypeId' => $roomTypeId,
            'organizationId' => $organizationId,
            'stayDate' => $stayDate,
            'totalQuantity' => $totalQuantity,
        ]);
        if ($statement->rowCount() === 0) {
            return null;
        }
        return $this->findForOrganization($roomTypeId, $stayDate, $organizationId);
    }

    /**
     * Khóa theo đúng thứ tự room type tăng dần, sau đó ngày tăng dần.
     *
     * @param list<InventoryDemand> $demands đã được aggregate và sort
     * @return array<string, array<string, mixed>>
     */
    public function lockRows(array $demands): array
    {
        $statement = $this->pdo->prepare(
            'SELECT LoaiPhongID AS roomTypeId, NgayLuuTru AS stayDate,
                    TongSoLuong AS totalQuantity, SoLuongDaGiu AS heldQuantity,
                    TongSoLuong - SoLuongDaGiu AS availableQuantity, PhienBan AS version
             FROM TonPhongNgay
             WHERE LoaiPhongID = :roomTypeId AND NgayLuuTru = :stayDate
             FOR UPDATE'
        );
        $rows = [];
        foreach ($demands as $demand) {
            if (!$demand instanceof InventoryDemand) {
                throw new InvalidArgumentException('Nhu cầu khóa tồn sai kiểu dữ liệu.');
            }
            $statement->execute(['roomTypeId' => $demand->roomTypeId, 'stayDate' => $demand->date]);
            $row = $statement->fetch(PDO::FETCH_ASSOC);
            if (is_array($row)) {
                $rows[$demand->roomTypeId . ':' . $demand->date] = $row;
            }
        }
        return $rows;
    }

    /** @param list<InventoryDemand> $demands */
    public function incrementHeld(array $demands): void
    {
        $statement = $this->pdo->prepare(
            'UPDATE TonPhongNgay
             SET SoLuongDaGiu = SoLuongDaGiu + :quantityAdd,
                 PhienBan = PhienBan + 1, CapNhatLuc = CURRENT_TIMESTAMP(6)
             WHERE LoaiPhongID = :roomTypeId AND NgayLuuTru = :stayDate
                 AND SoLuongDaGiu + :quantityCheck <= TongSoLuong'
        );
        foreach ($demands as $demand) {
            $statement->execute([
                'quantityAdd' => $demand->quantity,
                'quantityCheck' => $demand->quantity,
                'roomTypeId' => $demand->roomTypeId,
                'stayDate' => $demand->date,
            ]);
            if ($statement->rowCount() !== 1) {
                throw new DomainException('Counter tồn không thể tăng an toàn.');
            }
        }
    }

    /** @param list<InventoryDemand> $demands */
    public function decrementHeld(array $demands): void
    {
        $statement = $this->pdo->prepare(
            'UPDATE TonPhongNgay
             SET SoLuongDaGiu = SoLuongDaGiu - :quantitySubtract,
                 PhienBan = PhienBan + 1, CapNhatLuc = CURRENT_TIMESTAMP(6)
             WHERE LoaiPhongID = :roomTypeId AND NgayLuuTru = :stayDate
                 AND SoLuongDaGiu >= :quantityCheck'
        );
        foreach ($demands as $demand) {
            $statement->execute([
                'quantitySubtract' => $demand->quantity,
                'quantityCheck' => $demand->quantity,
                'roomTypeId' => $demand->roomTypeId,
                'stayDate' => $demand->date,
            ]);
            if ($statement->rowCount() !== 1) {
                throw new DomainException('Counter tồn không thể giảm an toàn.');
            }
        }
    }

    /** @param list<BookingInventoryItem> $items */
    public function assertBookingItemsEligible(int $bookingId, array $items): void
    {
        $ids = array_map(static fn (BookingInventoryItem $item): int => $item->bookingItemId, $items);
        if (count(array_unique($ids)) !== count($ids)) {
            throw new InvalidArgumentException('Danh sách giữ tồn có hạng mục trùng lặp.');
        }
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $statement = $this->pdo->prepare(
            'SELECT hm.HangMucDatChoID AS bookingItemId, hm.LoaiPhongID AS roomTypeId,
                    dc.TrangThai AS bookingStatus
             FROM HangMucDatCho hm
             INNER JOIN DatCho dc ON dc.DatChoID = hm.DatChoID
             WHERE hm.DatChoID = ? AND hm.HangMucDatChoID IN (' . $placeholders . ')
             ORDER BY hm.HangMucDatChoID ASC'
        );
        $statement->execute([$bookingId, ...$ids]);
        $rows = $statement->fetchAll(PDO::FETCH_ASSOC);
        if (count($rows) !== count($items)) {
            throw new DomainException('Hạng mục giữ tồn không thuộc booking hiện tại.');
        }
        $expected = array_column($items, 'roomTypeId', 'bookingItemId');
        foreach ($rows as $row) {
            if (!in_array((string) $row['bookingStatus'], ['PENDING_PAYMENT', 'CONFIRMED'], true)) {
                throw new DomainException('Trạng thái booking không được phép có ledger active.');
            }
            if (($expected[(int) $row['bookingItemId']] ?? null) !== (int) $row['roomTypeId']) {
                throw new DomainException('Loại phòng giữ tồn không khớp hạng mục booking.');
            }
        }
    }

    public function insertActiveHold(
        int $bookingItemId,
        int $roomTypeId,
        string $stayDate,
        ?DateTimeImmutable $expiresAt,
        DateTimeImmutable $heldAt,
    ): void {
        $statement = $this->pdo->prepare(
            "INSERT INTO GiuTonPhongDem
                (HangMucDatChoID, LoaiPhongID, NgayLuuTru, TrangThai, HetHanLuc, GiuLuc)
             VALUES (:bookingItemId, :roomTypeId, :stayDate, 'ACTIVE', :expiresAt, :heldAt)"
        );
        $statement->execute([
            'bookingItemId' => $bookingItemId,
            'roomTypeId' => $roomTypeId,
            'stayDate' => $stayDate,
            'expiresAt' => self::databaseTime($expiresAt),
            'heldAt' => self::databaseTime($heldAt),
        ]);
    }

    /** @return list<array<string, mixed>> */
    public function lockActiveHoldsForBooking(int $bookingId): array
    {
        $statement = $this->pdo->prepare(
            "SELECT gt.GiuTonPhongDemID AS id, gt.HangMucDatChoID AS bookingItemId,
                    gt.LoaiPhongID AS roomTypeId, gt.NgayLuuTru AS stayDate
             FROM GiuTonPhongDem gt
             INNER JOIN HangMucDatCho hm ON hm.HangMucDatChoID = gt.HangMucDatChoID
             WHERE hm.DatChoID = :bookingId AND gt.TrangThai = 'ACTIVE'
             ORDER BY gt.GiuTonPhongDemID ASC
             FOR UPDATE"
        );
        $statement->execute(['bookingId' => $bookingId]);
        return $statement->fetchAll(PDO::FETCH_ASSOC);
    }

    /** @return list<array<string, mixed>> */
    public function lockAllHoldsForBooking(int $bookingId): array
    {
        $statement = $this->pdo->prepare(
            "SELECT gt.GiuTonPhongDemID AS id, gt.HangMucDatChoID AS bookingItemId,
                    gt.LoaiPhongID AS roomTypeId, gt.NgayLuuTru AS stayDate,
                    gt.TrangThai AS status
             FROM GiuTonPhongDem gt
             INNER JOIN HangMucDatCho hm ON hm.HangMucDatChoID = gt.HangMucDatChoID
             WHERE hm.DatChoID = :bookingId
             ORDER BY gt.GiuTonPhongDemID ASC
             FOR UPDATE"
        );
        $statement->execute(['bookingId' => $bookingId]);
        return $statement->fetchAll(PDO::FETCH_ASSOC);
    }

    public function reactivateHold(int $holdId, DateTimeImmutable $heldAt): void
    {
        $statement = $this->pdo->prepare(
            "UPDATE GiuTonPhongDem
             SET TrangThai='ACTIVE', HetHanLuc=NULL, GiuLuc=:heldAt,
                 NhaLuc=NULL, LyDoNha=NULL
             WHERE GiuTonPhongDemID=:holdId AND TrangThai='RELEASED'"
        );
        $statement->execute(['heldAt' => self::databaseTime($heldAt), 'holdId' => $holdId]);
        if ($statement->rowCount() !== 1) {
            throw new DomainException('Ledger cũ không thể được kích hoạt lại an toàn.');
        }
    }

    /** @param list<int> $holdIds */
    public function releaseHolds(array $holdIds, string $reason, DateTimeImmutable $releasedAt): int
    {
        $placeholders = implode(',', array_fill(0, count($holdIds), '?'));
        $statement = $this->pdo->prepare(
            "UPDATE GiuTonPhongDem
             SET TrangThai = 'RELEASED', NhaLuc = ?, LyDoNha = ?
             WHERE GiuTonPhongDemID IN (" . $placeholders . ") AND TrangThai = 'ACTIVE'"
        );
        $statement->execute([self::databaseTime($releasedAt), $reason, ...$holdIds]);
        return $statement->rowCount();
    }

    /** @param list<int> $holdIds */
    public function clearHoldExpiry(array $holdIds): int
    {
        if ($holdIds === []) {
            throw new InvalidArgumentException('Danh sách ledger xác nhận không được rỗng.');
        }
        $placeholders = implode(',', array_fill(0, count($holdIds), '?'));
        $statement = $this->pdo->prepare(
            "UPDATE GiuTonPhongDem SET HetHanLuc = NULL
             WHERE GiuTonPhongDemID IN (" . $placeholders . ")
                 AND TrangThai = 'ACTIVE' AND HetHanLuc IS NOT NULL"
        );
        $statement->execute($holdIds);
        return $statement->rowCount();
    }

    /** @return list<int> */
    public function findExpiredBookingIds(DateTimeImmutable $at, int $limit): array
    {
        $statement = $this->pdo->prepare(
            "SELECT DISTINCT hm.DatChoID
             FROM GiuTonPhongDem gt
             INNER JOIN HangMucDatCho hm ON hm.HangMucDatChoID = gt.HangMucDatChoID
             INNER JOIN DatCho dc ON dc.DatChoID = hm.DatChoID
             WHERE gt.TrangThai = 'ACTIVE' AND gt.HetHanLuc IS NOT NULL
                 AND gt.HetHanLuc <= :expiredAt AND dc.TrangThai = 'PENDING_PAYMENT'
             ORDER BY hm.DatChoID ASC
             LIMIT " . $limit
        );
        $statement->execute(['expiredAt' => self::databaseTime($at)]);
        return array_map('intval', $statement->fetchAll(PDO::FETCH_COLUMN));
    }

    /** @return list<array<string, mixed>> */
    public function findReconciliationIssues(
        string $fromDate,
        string $toDate,
        ?int $roomTypeId = null,
    ): array {
        self::assertDateRange($fromDate, $toDate);
        $roomFilter = $roomTypeId === null ? '' : ' AND tp.LoaiPhongID = :roomTypeId';
        $missingCounterFilter = $roomTypeId === null ? '' : ' AND gt.LoaiPhongID = :missingRoomTypeId';
        $statement = $this->pdo->prepare(
            "SELECT tp.LoaiPhongID AS roomTypeId, tp.NgayLuuTru AS stayDate,
                    tp.TongSoLuong AS totalQuantity, tp.SoLuongDaGiu AS counterHeldQuantity,
                    COUNT(gt.GiuTonPhongDemID) AS ledgerHeldQuantity
             FROM TonPhongNgay tp
             LEFT JOIN GiuTonPhongDem gt ON gt.LoaiPhongID = tp.LoaiPhongID
                 AND gt.NgayLuuTru = tp.NgayLuuTru AND gt.TrangThai = 'ACTIVE'
             WHERE tp.NgayLuuTru BETWEEN :fromDate AND :toDate" . $roomFilter . "
             GROUP BY tp.LoaiPhongID, tp.NgayLuuTru, tp.TongSoLuong, tp.SoLuongDaGiu
             HAVING tp.SoLuongDaGiu <> COUNT(gt.GiuTonPhongDemID)
             UNION ALL
             SELECT gt.LoaiPhongID AS roomTypeId, gt.NgayLuuTru AS stayDate,
                    NULL AS totalQuantity, NULL AS counterHeldQuantity,
                    COUNT(gt.GiuTonPhongDemID) AS ledgerHeldQuantity
             FROM GiuTonPhongDem gt
             LEFT JOIN TonPhongNgay tp ON tp.LoaiPhongID = gt.LoaiPhongID
                 AND tp.NgayLuuTru = gt.NgayLuuTru
             WHERE gt.TrangThai = 'ACTIVE' AND tp.LoaiPhongID IS NULL
                 AND gt.NgayLuuTru BETWEEN :missingFromDate AND :missingToDate" . $missingCounterFilter . "
             GROUP BY gt.LoaiPhongID, gt.NgayLuuTru
             ORDER BY roomTypeId ASC, stayDate ASC"
        );
        $parameters = [
            'fromDate' => $fromDate,
            'toDate' => $toDate,
            'missingFromDate' => $fromDate,
            'missingToDate' => $toDate,
        ];
        if ($roomTypeId !== null) {
            $parameters['roomTypeId'] = $roomTypeId;
            $parameters['missingRoomTypeId'] = $roomTypeId;
        }
        $statement->execute($parameters);
        return $statement->fetchAll(PDO::FETCH_ASSOC);
    }

    private static function databaseTime(?DateTimeImmutable $time): ?string
    {
        return $time?->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s.u');
    }

    private static function assertIds(int $roomTypeId, int $organizationId): void
    {
        if ($roomTypeId < 1 || $organizationId < 1) {
            throw new InvalidArgumentException('ID loại phòng và tổ chức phải là số dương.');
        }
    }

    private static function assertDateRange(string $fromDate, string $toDate): void
    {
        $from = DateTimeImmutable::createFromFormat('!Y-m-d', $fromDate);
        $to = DateTimeImmutable::createFromFormat('!Y-m-d', $toDate);
        if ($from === false || $to === false
            || $from->format('Y-m-d') !== $fromDate || $to->format('Y-m-d') !== $toDate
            || $from > $to) {
            throw new InvalidArgumentException('Khoảng ngày tồn phòng không hợp lệ.');
        }
    }
}

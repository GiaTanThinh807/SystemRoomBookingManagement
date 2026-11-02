<?php

declare(strict_types=1);

namespace App\Modules\Bookings\Infrastructure;

use App\Modules\Commercial\Domain\ProductPricingSnapshot;
use App\Shared\Contract\StayPeriod;
use App\Shared\Json\CanonicalJson;
use App\Shared\Money\Money;
use DateTimeImmutable;
use JsonException;
use PDO;
use RuntimeException;

final readonly class DateChangeRepository
{
    public function __construct(private PDO $pdo)
    {
    }

    /** @return array<string, mixed>|null */
    public function findForCustomer(string $code, int $customerId): ?array
    {
        return $this->context($code, $customerId);
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
        return $this->context($code, $customerId);
    }

    public function pricingSnapshot(
        int $propertyId,
        int $productId,
        StayPeriod $stay,
        DateTimeImmutable $at,
        bool $lock,
    ): ?ProductPricingSnapshot {
        if ($lock) {
            $this->assertTransaction();
        }
        $statement = $this->pdo->prepare(
            'SELECT sp.ThoiDiemThanhToan AS paymentTiming, cs.MuiGio AS timezone,
                    cs.TyLeThue AS taxRate, cs.ThueDaBaoGom AS taxIncluded,
                    cs.TyLePhiDichVu AS serviceFeeRate, cs.PhiDichVuDaBaoGom AS serviceFeeIncluded,
                    csh.ChinhSachHuyID AS policyId, csh.TenChinhSach AS policyName,
                    csh.LoaiChinhSach AS policyType, csh.SoGioHuyMienPhi AS freeHours,
                    csh.LoaiPhat AS penaltyType, csh.GiaTriPhat AS penaltyValue,
                    csh.TyLePhatNoShow AS noShowRate
             FROM SanPhamPhong sp
             INNER JOIN LoaiPhong lp ON lp.LoaiPhongID=sp.LoaiPhongID
             INNER JOIN CoSoLuuTru cs ON cs.CoSoLuuTruID=lp.CoSoLuuTruID
             INNER JOIN ChinhSachHuy csh ON csh.ChinhSachHuyID=sp.ChinhSachHuyID
             WHERE sp.SanPhamPhongID=:productId AND lp.CoSoLuuTruID=:propertyId'
             . ($lock ? ' FOR UPDATE' : '')
        );
        $statement->execute(['productId' => $productId, 'propertyId' => $propertyId]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row) || (string) $row['paymentTiming'] !== 'PAY_AT_PROPERTY') {
            return null;
        }
        $rateStatement = $this->pdo->prepare(
            'SELECT NgayLuuTru AS stayDate, GiaCoBan AS basePrice, DongBan AS closed,
                    SoDemToiThieu AS minimumStay, SoDemToiDa AS maximumStay,
                    SoNgayDatTruoc AS minimumLeadDays, PhienBan AS version
             FROM GiaPhongNgay
             WHERE SanPhamPhongID=:productId AND NgayLuuTru>=:checkIn AND NgayLuuTru<:checkOut
             ORDER BY NgayLuuTru' . ($lock ? ' FOR UPDATE' : '')
        );
        $rateStatement->execute([
            'productId' => $productId,
            'checkIn' => $stay->checkIn,
            'checkOut' => $stay->checkOut,
        ]);
        $rates = array_map(static fn (array $rate): array => [
            'stayDate' => (string) $rate['stayDate'],
            'basePrice' => (string) $rate['basePrice'],
            'closed' => (bool) $rate['closed'],
            'minimumStay' => (int) $rate['minimumStay'],
            'maximumStay' => $rate['maximumStay'] === null ? null : (int) $rate['maximumStay'],
            'minimumLeadDays' => (int) $rate['minimumLeadDays'],
            'version' => (int) $rate['version'],
        ], $rateStatement->fetchAll(PDO::FETCH_ASSOC));

        $lastNight = $stay->nights()[count($stay->nights()) - 1];
        $promotion = $this->pdo->prepare(
            "SELECT KhuyenMaiID AS id, PhanTramGiam AS discountRate
             FROM KhuyenMai
             WHERE CoSoLuuTruID=:propertyId AND TrangThai='ACTIVE'
               AND DatTu<=:atFrom AND DatDen>=:atUntil
               AND LuuTruTu<=:checkIn AND LuuTruDen>=:lastNight
             ORDER BY PhanTramGiam DESC, KhuyenMaiID" . ($lock ? ' FOR UPDATE' : '')
        );
        $databaseAt = $at->format('Y-m-d H:i:s.u');
        $promotion->execute([
            'propertyId' => $propertyId,
            'atFrom' => $databaseAt,
            'atUntil' => $databaseAt,
            'checkIn' => $stay->checkIn,
            'lastNight' => $lastNight,
        ]);
        $promotions = array_map(static fn (array $promotion): array => [
            'id' => (int) $promotion['id'],
            'discountRate' => (string) $promotion['discountRate'],
        ], $promotion->fetchAll(PDO::FETCH_ASSOC));
        return new ProductPricingSnapshot(
            (string) $row['paymentTiming'],
            (string) $row['timezone'],
            (string) $row['taxRate'],
            (bool) $row['taxIncluded'],
            (string) $row['serviceFeeRate'],
            (bool) $row['serviceFeeIncluded'],
            $rates,
            $promotions,
            [
                'id' => (int) $row['policyId'],
                'name' => (string) $row['policyName'],
                'policyType' => (string) $row['policyType'],
                'freeCancellationHours' => $row['freeHours'] === null ? null : (int) $row['freeHours'],
                'penaltyType' => (string) $row['penaltyType'],
                'penaltyValue' => (string) $row['penaltyValue'],
                'noShowPenaltyRate' => (string) $row['noShowRate'],
            ],
        );
    }

    /** @param list<array<string, mixed>> $items */
    public function hasCapacity(int $bookingId, array $items, StayPeriod $stay): bool
    {
        $demands = [];
        foreach ($items as $item) {
            foreach ($stay->nights() as $date) {
                $key = (int) $item['roomTypeId'] . ':' . $date;
                $demands[$key] = ['roomTypeId' => (int) $item['roomTypeId'], 'date' => $date, 'quantity' => ($demands[$key]['quantity'] ?? 0) + 1];
            }
        }
        $statement = $this->pdo->prepare(
            "SELECT tp.TongSoLuong-tp.SoLuongDaGiu AS available,
                    (SELECT COUNT(*) FROM GiuTonPhongDem gt
                     INNER JOIN HangMucDatCho hm ON hm.HangMucDatChoID=gt.HangMucDatChoID
                     WHERE hm.DatChoID=:bookingId AND gt.LoaiPhongID=tp.LoaiPhongID
                       AND gt.NgayLuuTru=tp.NgayLuuTru AND gt.TrangThai='ACTIVE') AS ownActive
             FROM TonPhongNgay tp WHERE tp.LoaiPhongID=:roomTypeId AND tp.NgayLuuTru=:stayDate"
        );
        foreach ($demands as $demand) {
            $statement->execute([
                'bookingId' => $bookingId,
                'roomTypeId' => $demand['roomTypeId'],
                'stayDate' => $demand['date'],
            ]);
            $row = $statement->fetch(PDO::FETCH_ASSOC);
            if (!is_array($row) || (int) $row['available'] + (int) $row['ownActive'] < $demand['quantity']) {
                return false;
            }
        }
        return true;
    }

    public function updateBooking(int $bookingId, StayPeriod $stay, Money $total): void
    {
        $statement = $this->pdo->prepare(
            "UPDATE DatCho SET NgayNhanPhong=:checkIn, NgayTraPhong=:checkOut, TongTien=:total
             WHERE DatChoID=:bookingId AND TrangThai='CONFIRMED' AND ThoiDiemThanhToan='PAY_AT_PROPERTY'"
        );
        $statement->execute([
            'checkIn' => $stay->checkIn,
            'checkOut' => $stay->checkOut,
            'total' => $total->decimal(),
            'bookingId' => $bookingId,
        ]);
        if ($statement->rowCount() !== 1) {
            throw new RuntimeException('Không thể cập nhật ngày và tổng booking.');
        }
    }

    /** @param array<string, mixed> $item */
    public function updateItemPricing(int $itemId, array $item): void
    {
        $statement = $this->pdo->prepare(
            'UPDATE HangMucDatCho SET TongTien=:total, SnapshotDieuKienJSON=:conditions,
                    SnapshotHuyJSON=:cancellation WHERE HangMucDatChoID=:itemId'
        );
        $statement->execute([
            'total' => (string) $item['grandTotal'],
            'conditions' => CanonicalJson::encode($item['paymentSnapshot']),
            'cancellation' => CanonicalJson::encode($item['cancellationSnapshot']),
            'itemId' => $itemId,
        ]);
        $this->syncNights($itemId, $item['nights']);
    }

    /** @param list<array<string, mixed>> $nights */
    private function syncNights(int $itemId, array $nights): void
    {
        $existing = $this->pdo->prepare('SELECT NgayLuuTru FROM GiaDemDatCho WHERE HangMucDatChoID=? FOR UPDATE');
        $existing->execute([$itemId]);
        $oldDates = array_map('strval', $existing->fetchAll(PDO::FETCH_COLUMN));
        $newDates = array_column($nights, 'stayDate');
        $deleteDates = array_values(array_diff($oldDates, $newDates));
        $delete = $this->pdo->prepare('DELETE FROM GiaDemDatCho WHERE HangMucDatChoID=? AND NgayLuuTru=?');
        foreach ($deleteDates as $date) {
            $delete->execute([$itemId, $date]);
        }
        $write = $this->pdo->prepare(
            'INSERT INTO GiaDemDatCho
                (HangMucDatChoID, NgayLuuTru, GiaCoBan, TienGiam, ThuePhiDaGom,
                 ThuePhiTaiCoSo, ThanhTien, KhuyenMaiID, SnapshotJSON)
             VALUES (:itemId,:stayDate,:basePrice,:discount,:includedTaxFee,
                     :taxFeeAtProperty,:total,:promotionId,:snapshot)
             ON DUPLICATE KEY UPDATE GiaCoBan=VALUES(GiaCoBan), TienGiam=VALUES(TienGiam),
                 ThuePhiDaGom=VALUES(ThuePhiDaGom), ThuePhiTaiCoSo=VALUES(ThuePhiTaiCoSo),
                 ThanhTien=VALUES(ThanhTien), KhuyenMaiID=VALUES(KhuyenMaiID), SnapshotJSON=VALUES(SnapshotJSON)'
        );
        foreach ($nights as $night) {
            $write->execute([
                'itemId' => $itemId,
                'stayDate' => $night['stayDate'],
                'basePrice' => $night['basePrice'],
                'discount' => $night['discount'],
                'includedTaxFee' => $night['includedTaxFee'],
                'taxFeeAtProperty' => $night['taxFeeAtProperty'],
                'total' => $night['total'],
                'promotionId' => $night['promotionId'],
                'snapshot' => CanonicalJson::encode($night),
            ]);
        }
    }

    /** @return array<string, mixed>|null */
    private function context(string $code, int $customerId): ?array
    {
        $statement = $this->pdo->prepare(
            'SELECT dc.DatChoID AS id, dc.MaDatCho AS code, dc.KhachHangID AS customerId,
                    dc.CoSoLuuTruID AS propertyId, dc.TrangThai AS status,
                    dc.ThoiDiemThanhToan AS paymentTiming, dc.NgayNhanPhong AS checkIn,
                    dc.NgayTraPhong AS checkOut, dc.TongTien AS grandTotal,
                    cs.GioNhanPhong AS checkInTime, cs.MuiGio AS timezone,
                    tt.TrangThai AS paymentStatus
             FROM DatCho dc
             INNER JOIN CoSoLuuTru cs ON cs.CoSoLuuTruID=dc.CoSoLuuTruID
             INNER JOIN ThanhToan tt ON tt.DatChoID=dc.DatChoID
             WHERE dc.MaDatCho=:code AND dc.KhachHangID=:customerId'
        );
        $statement->execute(['code' => $code, 'customerId' => $customerId]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            return null;
        }
        $row['id'] = (int) $row['id'];
        $row['customerId'] = (int) $row['customerId'];
        $row['propertyId'] = (int) $row['propertyId'];
        $row['items'] = $this->items((int) $row['id']);
        return $row;
    }

    /** @return list<array<string, mixed>> */
    private function items(int $bookingId): array
    {
        $statement = $this->pdo->prepare(
            'SELECT HangMucDatChoID AS id, SanPhamPhongID AS productId,
                    LoaiPhongID AS roomTypeId, ThuTuPhong AS roomOrder,
                    KhachJSON AS partyJson, TongTien AS grandTotal
             FROM HangMucDatCho WHERE DatChoID=? ORDER BY HangMucDatChoID'
        );
        $statement->execute([$bookingId]);
        return array_map(fn (array $row): array => [
            'id' => (int) $row['id'],
            'productId' => (int) $row['productId'],
            'roomTypeId' => (int) $row['roomTypeId'],
            'roomOrder' => (int) $row['roomOrder'],
            'party' => $this->decode((string) $row['partyJson']),
            'grandTotal' => (string) $row['grandTotal'],
        ], $statement->fetchAll(PDO::FETCH_ASSOC));
    }

    /** @return array<string, mixed> */
    private function decode(string $json): array
    {
        try {
            $value = json_decode($json, true, 64, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException('Snapshot khách của booking không hợp lệ.', 0, $exception);
        }
        if (!is_array($value)) {
            throw new RuntimeException('Snapshot khách của booking phải là array.');
        }
        return $value;
    }

    private function assertTransaction(): void
    {
        if (!$this->pdo->inTransaction()) {
            throw new RuntimeException('Đổi ngày chỉ được khóa dữ liệu trong transaction.');
        }
    }
}

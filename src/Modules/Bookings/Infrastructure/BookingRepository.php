<?php

declare(strict_types=1);

namespace App\Modules\Bookings\Infrastructure;

use App\Modules\Bookings\Domain\Booking;
use App\Shared\Json\CanonicalJson;
use App\Shared\Money\Money;
use DateTimeImmutable;
use DateTimeZone;
use JsonException;
use PDO;
use RuntimeException;

final readonly class BookingRepository
{
    private DateTimeZone $utc;

    public function __construct(private PDO $pdo)
    {
        $this->utc = new DateTimeZone('UTC');
    }

    /** @return array<string, mixed>|null */
    public function findIdempotencyRecord(string $clientRequestId): ?array
    {
        $statement = $this->pdo->prepare(
            'SELECT dc.DatChoID AS bookingId, dc.ClientRequestID AS clientRequestId,
                    dc.XemTruocDatChoID AS previewId, dc.KhachHangID AS customerId,
                    dc.TenNguoiDat AS bookerName, dc.EmailNguoiDat AS bookerEmail,
                    dc.DienThoaiNguoiDat AS bookerPhone, dc.YeuCauDacBiet AS specialRequest,
                    xt.Fingerprint AS previewFingerprint
             FROM DatCho dc
             INNER JOIN XemTruocDatCho xt ON xt.XemTruocDatChoID = dc.XemTruocDatChoID
             WHERE dc.ClientRequestID = :clientRequestId'
        );
        $statement->execute(['clientRequestId' => $clientRequestId]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : null;
    }

    /** @param array<string, mixed> $values */
    public function create(array $values): int
    {
        $statement = $this->pdo->prepare(
            "INSERT INTO DatCho
                (MaDatCho, ClientRequestID, XemTruocDatChoID, KhachHangID, CoSoLuuTruID,
                 NgayNhanPhong, NgayTraPhong, TenNguoiDat, EmailNguoiDat, DienThoaiNguoiDat,
                 YeuCauDacBiet, ThoiDiemThanhToan, TienTe, TongTien, TrangThai,
                 HetHanThanhToanLuc, XacNhanLuc, HoanThanhLuc, TaoLuc, CapNhatLuc)
             VALUES
                (:code, :clientRequestId, :previewId, :customerId, :propertyId,
                 :checkIn, :checkOut, :bookerName, :bookerEmail, :bookerPhone,
                 :specialRequest, 'PAY_AT_PROPERTY', 'VND', :grandTotal, 'CONFIRMED',
                 NULL, :confirmedAt, NULL, :createdAt, :updatedAt)"
        );
        $statement->execute($values);
        return (int) $this->pdo->lastInsertId();
    }

    /** @param array<string, mixed> $room */
    public function createItem(int $bookingId, array $room): int
    {
        $price = $this->array($room['priceBreakdown'] ?? null, 'priceBreakdown');
        $grandTotal = $this->array($price['grandTotal'] ?? null, 'grandTotal');
        $statement = $this->pdo->prepare(
            'INSERT INTO HangMucDatCho
                (DatChoID, SanPhamPhongID, LoaiPhongID, ThuTuPhong,
                 TenLoaiPhongSnapshot, TenSanPhamSnapshot, LoaiBuaAnSnapshot,
                 KhachJSON, TongTien, SnapshotDieuKienJSON, SnapshotHuyJSON)
             VALUES
                (:bookingId, :productId, :roomTypeId, :roomOrder,
                 :roomTypeName, :productName, :mealType,
                 :partyJson, :grandTotal, :conditionsJson, :cancellationJson)'
        );
        $statement->execute([
            'bookingId' => $bookingId,
            'productId' => (int) ($room['productId'] ?? 0),
            'roomTypeId' => (int) ($room['roomTypeId'] ?? 0),
            'roomOrder' => (int) ($room['roomOrder'] ?? 0),
            'roomTypeName' => (string) ($room['roomTypeName'] ?? ''),
            'productName' => (string) ($room['productName'] ?? ''),
            'mealType' => (string) ($room['mealType'] ?? ''),
            'partyJson' => CanonicalJson::encode($this->array($room['party'] ?? null, 'party')),
            'grandTotal' => (string) ($grandTotal['amount'] ?? ''),
            'conditionsJson' => CanonicalJson::encode($this->array($room['paymentSnapshot'] ?? null, 'paymentSnapshot')),
            'cancellationJson' => CanonicalJson::encode($this->array($room['cancellationSnapshot'] ?? null, 'cancellationSnapshot')),
        ]);
        return (int) $this->pdo->lastInsertId();
    }

    /** @param array<string, mixed> $night */
    public function createNight(int $bookingItemId, array $night): void
    {
        $statement = $this->pdo->prepare(
            'INSERT INTO GiaDemDatCho
                (HangMucDatChoID, NgayLuuTru, GiaCoBan, TienGiam, ThuePhiDaGom,
                 ThuePhiTaiCoSo, ThanhTien, KhuyenMaiID, SnapshotJSON)
             VALUES
                (:bookingItemId, :stayDate, :basePrice, :discount, :includedTaxFee,
                 :taxFeeAtProperty, :total, :promotionId, :snapshotJson)'
        );
        $statement->execute([
            'bookingItemId' => $bookingItemId,
            'stayDate' => (string) ($night['stayDate'] ?? ''),
            'basePrice' => (string) ($night['basePrice'] ?? ''),
            'discount' => (string) ($night['discount'] ?? ''),
            'includedTaxFee' => (string) ($night['includedTaxFee'] ?? ''),
            'taxFeeAtProperty' => (string) ($night['taxFeeAtProperty'] ?? ''),
            'total' => (string) ($night['total'] ?? ''),
            'promotionId' => $night['promotionId'] ?? null,
            'snapshotJson' => CanonicalJson::encode($night),
        ]);
    }

    public function createPayAtPropertyPayment(int $bookingId, Money $grandTotal): void
    {
        $statement = $this->pdo->prepare(
            "INSERT INTO ThanhToan
                (DatChoID, ThoiDiemThanhToan, TrangThai, TongPhaiTra,
                 DaThanhToan, DaHoan, TienTe, SoLanThu, TrangThaiHoanTien)
             VALUES
                (:bookingId, 'PAY_AT_PROPERTY', 'NOT_TRACKED', :grandTotal,
                 0, 0, 'VND', 0, 'NONE')"
        );
        $statement->execute(['bookingId' => $bookingId, 'grandTotal' => $grandTotal->decimal()]);
    }

    public function findById(int $bookingId): ?Booking
    {
        $statement = $this->pdo->prepare($this->bookingSql() . ' WHERE dc.DatChoID = :bookingId');
        $statement->execute(['bookingId' => $bookingId]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $this->hydrate($row) : null;
    }

    public function findForCustomerByCode(string $code, int $customerId): ?Booking
    {
        $statement = $this->pdo->prepare(
            $this->bookingSql() . ' WHERE dc.MaDatCho = :code AND dc.KhachHangID = :customerId'
        );
        $statement->execute(['code' => $code, 'customerId' => $customerId]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $this->hydrate($row) : null;
    }

    private function bookingSql(): string
    {
        return 'SELECT dc.DatChoID AS id, dc.MaDatCho AS code,
                       dc.ClientRequestID AS clientRequestId, dc.XemTruocDatChoID AS previewId,
                       dc.KhachHangID AS customerId, dc.CoSoLuuTruID AS propertyId,
                       cs.TenCoSo AS propertyName, cs.DiaChi AS propertyAddress,
                       dc.NgayNhanPhong AS checkIn, dc.NgayTraPhong AS checkOut,
                       dc.TenNguoiDat AS bookerName, dc.EmailNguoiDat AS bookerEmail,
                       dc.DienThoaiNguoiDat AS bookerPhone, dc.YeuCauDacBiet AS specialRequest,
                       dc.ThoiDiemThanhToan AS paymentTiming, dc.TongTien AS grandTotal,
                       dc.TrangThai AS status, dc.XacNhanLuc AS confirmedAt, dc.TaoLuc AS createdAt
                FROM DatCho dc
                INNER JOIN CoSoLuuTru cs ON cs.CoSoLuuTruID = dc.CoSoLuuTruID';
    }

    /** @param array<string, mixed> $row */
    private function hydrate(array $row): Booking
    {
        return new Booking(
            (int) $row['id'],
            (string) $row['code'],
            (string) $row['clientRequestId'],
            (int) $row['previewId'],
            (int) $row['customerId'],
            (int) $row['propertyId'],
            (string) $row['propertyName'],
            (string) $row['propertyAddress'],
            (string) $row['checkIn'],
            (string) $row['checkOut'],
            (string) $row['bookerName'],
            (string) $row['bookerEmail'],
            (string) $row['bookerPhone'],
            $row['specialRequest'] === null ? null : (string) $row['specialRequest'],
            (string) $row['paymentTiming'],
            Money::vnd((string) $row['grandTotal']),
            (string) $row['status'],
            $this->dateTime((string) $row['confirmedAt']),
            $this->dateTime((string) $row['createdAt']),
            $this->items((int) $row['id']),
        );
    }

    /** @return list<array<string, mixed>> */
    private function items(int $bookingId): array
    {
        $statement = $this->pdo->prepare(
            'SELECT HangMucDatChoID AS id, SanPhamPhongID AS productId,
                    LoaiPhongID AS roomTypeId, ThuTuPhong AS roomOrder,
                    TenLoaiPhongSnapshot AS roomTypeName, TenSanPhamSnapshot AS productName,
                    LoaiBuaAnSnapshot AS mealType, KhachJSON AS partyJson,
                    TongTien AS grandTotal, SnapshotDieuKienJSON AS conditionsJson,
                    SnapshotHuyJSON AS cancellationJson
             FROM HangMucDatCho WHERE DatChoID = :bookingId ORDER BY ThuTuPhong ASC'
        );
        $statement->execute(['bookingId' => $bookingId]);
        $items = [];
        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $row['id'] = (int) $row['id'];
            $row['productId'] = (int) $row['productId'];
            $row['roomTypeId'] = (int) $row['roomTypeId'];
            $row['roomOrder'] = (int) $row['roomOrder'];
            $row['party'] = $this->decode((string) $row['partyJson']);
            $row['conditions'] = $this->decode((string) $row['conditionsJson']);
            $row['cancellation'] = $this->decode((string) $row['cancellationJson']);
            $row['nights'] = $this->nights((int) $row['id']);
            unset($row['partyJson'], $row['conditionsJson'], $row['cancellationJson']);
            $items[] = $row;
        }
        return $items;
    }

    /** @return list<array<string, mixed>> */
    private function nights(int $bookingItemId): array
    {
        $statement = $this->pdo->prepare(
            'SELECT NgayLuuTru AS stayDate, GiaCoBan AS basePrice, TienGiam AS discount,
                    ThuePhiDaGom AS includedTaxFee, ThuePhiTaiCoSo AS taxFeeAtProperty,
                    ThanhTien AS total, KhuyenMaiID AS promotionId, SnapshotJSON AS snapshotJson
             FROM GiaDemDatCho WHERE HangMucDatChoID = :bookingItemId ORDER BY NgayLuuTru ASC'
        );
        $statement->execute(['bookingItemId' => $bookingItemId]);
        $nights = [];
        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $row['promotionId'] = $row['promotionId'] === null ? null : (int) $row['promotionId'];
            $row['snapshot'] = $this->decode((string) $row['snapshotJson']);
            unset($row['snapshotJson']);
            $nights[] = $row;
        }
        return $nights;
    }

    /** @return array<string, mixed> */
    private function decode(string $json): array
    {
        try {
            $value = json_decode($json, true, 64, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException('Snapshot booking trong database không hợp lệ.', 0, $exception);
        }
        if (!is_array($value)) {
            throw new RuntimeException('Snapshot booking phải là object hoặc array.');
        }
        return $value;
    }

    /** @return array<string, mixed> */
    private function array(mixed $value, string $field): array
    {
        if (!is_array($value)) {
            throw new RuntimeException('Snapshot preview thiếu ' . $field . '.');
        }
        return $value;
    }

    private function dateTime(string $value): DateTimeImmutable
    {
        return new DateTimeImmutable($value, $this->utc);
    }
}

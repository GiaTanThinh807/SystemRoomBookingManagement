<?php

declare(strict_types=1);

namespace App\Modules\Bookings\Infrastructure;

use App\Modules\Bookings\Domain\BookingPreview;
use App\Modules\Bookings\Domain\PreviewDraft;
use App\Shared\Json\CanonicalJson;
use App\Shared\Money\Money;
use DateTimeImmutable;
use DateTimeZone;
use JsonException;
use PDO;
use RuntimeException;

final readonly class PreviewRepository
{
    private DateTimeZone $utc;

    public function __construct(private PDO $pdo)
    {
        $this->utc = new DateTimeZone('UTC');
    }

    public function create(PreviewDraft $draft): BookingPreview
    {
        $statement = $this->pdo->prepare(
            'INSERT INTO XemTruocDatCho
                (TokenHash, KhachHangID, CoSoLuuTruID, NgayNhanPhong, NgayTraPhong,
                 TienTe, TongTien, SoTienTraNgay, SoTienTraTaiCoSo, PhanBoJSON,
                 Fingerprint, TrangThai, HetHanLuc, TaoLuc, DaDungLuc)
             VALUES
                (:tokenHash, :customerId, :propertyId, :checkIn, :checkOut,
                 \'VND\', :grandTotal, :payNowTotal, :payAtPropertyTotal, :allocationJson,
                 :fingerprint, \'VALID\', :expiresAt, :createdAt, NULL)'
        );
        $statement->execute([
            'tokenHash' => $draft->tokenHash,
            'customerId' => $draft->customerId,
            'propertyId' => $draft->propertyId,
            'checkIn' => $draft->checkIn,
            'checkOut' => $draft->checkOut,
            'grandTotal' => $draft->grandTotal->decimal(),
            'payNowTotal' => $draft->payNowTotal->decimal(),
            'payAtPropertyTotal' => $draft->payAtPropertyTotal->decimal(),
            'allocationJson' => CanonicalJson::encode($draft->allocationSnapshot),
            'fingerprint' => $draft->fingerprint,
            'expiresAt' => $this->format($draft->expiresAt),
            'createdAt' => $this->format($draft->createdAt),
        ]);

        $preview = $this->findById((int) $this->pdo->lastInsertId());
        if ($preview === null) {
            throw new RuntimeException('Không thể đọc lại preview vừa tạo.');
        }
        return $preview;
    }

    public function findByTokenHash(string $tokenHash): ?BookingPreview
    {
        $statement = $this->pdo->prepare($this->selectSql() . ' WHERE TokenHash = :tokenHash');
        $statement->execute(['tokenHash' => $tokenHash]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $this->hydrate($row) : null;
    }

    public function invalidate(int $previewId): void
    {
        $statement = $this->pdo->prepare(
            "UPDATE XemTruocDatCho SET TrangThai = 'INVALIDATED'
             WHERE XemTruocDatChoID = :previewId AND TrangThai = 'VALID'"
        );
        $statement->execute(['previewId' => $previewId]);
    }

    private function findById(int $previewId): ?BookingPreview
    {
        $statement = $this->pdo->prepare($this->selectSql() . ' WHERE XemTruocDatChoID = :previewId');
        $statement->execute(['previewId' => $previewId]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $this->hydrate($row) : null;
    }

    private function selectSql(): string
    {
        return 'SELECT XemTruocDatChoID AS id, KhachHangID AS customerId,
                       CoSoLuuTruID AS propertyId, NgayNhanPhong AS checkIn,
                       NgayTraPhong AS checkOut, TongTien AS grandTotal,
                       SoTienTraNgay AS payNowTotal, SoTienTraTaiCoSo AS payAtPropertyTotal,
                       PhanBoJSON AS allocationJson, Fingerprint AS fingerprint,
                       TrangThai AS status, HetHanLuc AS expiresAt, TaoLuc AS createdAt,
                       DaDungLuc AS usedAt
                FROM XemTruocDatCho';
    }

    /** @param array<string, mixed> $row */
    private function hydrate(array $row): BookingPreview
    {
        try {
            $snapshot = json_decode((string) $row['allocationJson'], true, 64, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException('PhanBoJSON của preview không hợp lệ.', 0, $exception);
        }
        if (!is_array($snapshot)) {
            throw new RuntimeException('PhanBoJSON của preview phải là object.');
        }

        return new BookingPreview(
            (int) $row['id'],
            (int) $row['customerId'],
            (int) $row['propertyId'],
            (string) $row['checkIn'],
            (string) $row['checkOut'],
            Money::vnd((string) $row['grandTotal']),
            Money::vnd((string) $row['payNowTotal']),
            Money::vnd((string) $row['payAtPropertyTotal']),
            $snapshot,
            (string) $row['fingerprint'],
            (string) $row['status'],
            $this->dateTime((string) $row['expiresAt']),
            $this->dateTime((string) $row['createdAt']),
            $row['usedAt'] === null ? null : $this->dateTime((string) $row['usedAt']),
        );
    }

    private function dateTime(string $value): DateTimeImmutable
    {
        return new DateTimeImmutable($value, $this->utc);
    }

    private function format(DateTimeImmutable $value): string
    {
        return $value->setTimezone($this->utc)->format('Y-m-d H:i:s.u');
    }
}

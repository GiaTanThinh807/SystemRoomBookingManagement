<?php

declare(strict_types=1);

namespace App\Modules\Bookings\Infrastructure;

use App\Modules\Bookings\Domain\Payment;
use App\Shared\Money\Money;
use DateTimeImmutable;
use DateTimeZone;
use PDO;
use RuntimeException;

final readonly class PaymentRepository
{
    public function __construct(private PDO $pdo)
    {
    }

    public function createOnline(int $bookingId, Money $total, string $idempotencyKey): void
    {
        $statement = $this->pdo->prepare(
            "INSERT INTO ThanhToan
                (DatChoID, ThoiDiemThanhToan, TrangThai, TongPhaiTra, DaThanhToan,
                 DaHoan, TienTe, Provider, IdempotencyKey, SoLanThu, TrangThaiHoanTien)
             VALUES (:bookingId, 'PAY_ONLINE', 'PROCESSING', :total, 0, 0, 'VND',
                     'MOCK', :idempotencyKey, 1, 'NONE')"
        );
        $statement->execute(['bookingId' => $bookingId, 'total' => $total->decimal(), 'idempotencyKey' => $idempotencyKey]);
    }

    public function findByBookingId(int $bookingId): ?Payment
    {
        return $this->select($bookingId, false);
    }

    public function lockByBookingId(int $bookingId): ?Payment
    {
        if (!$this->pdo->inTransaction()) {
            throw new RuntimeException('Payment chỉ được khóa trong transaction.');
        }
        return $this->select($bookingId, true);
    }

    public function incrementAttempt(int $paymentId): void
    {
        $statement = $this->pdo->prepare('UPDATE ThanhToan SET SoLanThu = SoLanThu + 1 WHERE ThanhToanID = ? AND TrangThai = \'PROCESSING\'');
        $statement->execute([$paymentId]);
        if ($statement->rowCount() !== 1) {
            throw new RuntimeException('Không thể tăng số lần thử payment.');
        }
    }

    public function markPaid(int $paymentId, Money $amount, string $reference, DateTimeImmutable $at): void
    {
        $statement = $this->pdo->prepare(
            "UPDATE ThanhToan SET TrangThai='PAID', DaThanhToan=:amount,
                    ProviderReference=:reference, MaLoiCuoi=NULL, XuLyLuc=:processedAt
             WHERE ThanhToanID=:id AND TrangThai='PROCESSING'"
        );
        $statement->execute(['amount' => $amount->decimal(), 'reference' => $reference, 'processedAt' => self::time($at), 'id' => $paymentId]);
        if ($statement->rowCount() !== 1) {
            throw new RuntimeException('Payment không thể chuyển sang PAID.');
        }
    }

    public function markFailed(int $paymentId, string $errorCode, DateTimeImmutable $at): void
    {
        $statement = $this->pdo->prepare(
            "UPDATE ThanhToan SET TrangThai='FAILED', MaLoiCuoi=:errorCode, XuLyLuc=:processedAt
             WHERE ThanhToanID=:id AND TrangThai='PROCESSING'"
        );
        $statement->execute(['errorCode' => $errorCode, 'processedAt' => self::time($at), 'id' => $paymentId]);
        if ($statement->rowCount() !== 1) {
            throw new RuntimeException('Payment không thể chuyển sang FAILED.');
        }
    }

    public function markTimeout(int $paymentId, string $errorCode): void
    {
        $statement = $this->pdo->prepare("UPDATE ThanhToan SET MaLoiCuoi=? WHERE ThanhToanID=? AND TrangThai='PROCESSING'");
        $statement->execute([$errorCode, $paymentId]);
    }

    public function recordLateCapture(int $paymentId, Money $amount, string $reference, string $refundKey): bool
    {
        $statement = $this->pdo->prepare(
            "UPDATE ThanhToan SET DaThanhToan=:amount, ProviderReference=:reference,
                    HoanTienIdempotencyKey=:refundKey, TrangThaiHoanTien='PENDING',
                    LyDoHoanTien='LATE_PAYMENT_SUCCESS'
             WHERE ThanhToanID=:id AND TrangThai='FAILED' AND DaThanhToan=0"
        );
        $statement->execute(['amount' => $amount->decimal(), 'reference' => $reference, 'refundKey' => $refundKey, 'id' => $paymentId]);
        return $statement->rowCount() === 1;
    }

    public function startRefund(int $paymentId, Money $amount, string $refundKey, string $reason): void
    {
        $statement = $this->pdo->prepare(
            "UPDATE ThanhToan SET HoanTienIdempotencyKey=:refundKey,
                    TrangThaiHoanTien='PENDING', LyDoHoanTien=:reason
             WHERE ThanhToanID=:id AND TrangThai='PAID' AND DaThanhToan>=:amount
               AND ((TrangThaiHoanTien='NONE' AND HoanTienIdempotencyKey IS NULL)
                    OR (TrangThaiHoanTien='FAILED' AND HoanTienIdempotencyKey=:retryKey))"
        );
        $statement->execute([
            'refundKey' => $refundKey,
            'reason' => $reason,
            'id' => $paymentId,
            'amount' => $amount->decimal(),
            'retryKey' => $refundKey,
        ]);
        if ($statement->rowCount() !== 1) {
            throw new RuntimeException('Không thể khởi tạo hoặc retry refund của payment.');
        }
    }

    public function markRefundSucceeded(int $paymentId, Money $amount, string $reference, DateTimeImmutable $at): void
    {
        $statement = $this->pdo->prepare(
            "UPDATE ThanhToan SET DaHoan=:amount, TrangThaiHoanTien='SUCCEEDED',
                    ProviderRefundReference=:reference, HoanTienHoanTatLuc=:completedAt
             WHERE ThanhToanID=:id AND TrangThaiHoanTien='PENDING'"
        );
        $statement->execute(['amount' => $amount->decimal(), 'reference' => $reference, 'completedAt' => self::time($at), 'id' => $paymentId]);
        if ($statement->rowCount() !== 1) {
            throw new RuntimeException('Không thể hoàn tất refund của payment.');
        }
    }

    public function markRefundFailed(int $paymentId): void
    {
        $statement = $this->pdo->prepare("UPDATE ThanhToan SET TrangThaiHoanTien='FAILED' WHERE ThanhToanID=? AND TrangThaiHoanTien='PENDING'");
        $statement->execute([$paymentId]);
        if ($statement->rowCount() !== 1) {
            throw new RuntimeException('Không thể đánh dấu refund thất bại.');
        }
    }

    private function select(int $bookingId, bool $lock): ?Payment
    {
        $statement = $this->pdo->prepare(
            'SELECT ThanhToanID AS id, DatChoID AS bookingId, ThoiDiemThanhToan AS timing,
                    TrangThai AS status,
                    TongPhaiTra AS total, DaThanhToan AS paid, DaHoan AS refunded,
                    IdempotencyKey AS idempotencyKey, ProviderReference AS providerReference,
                    SoLanThu AS attempts, MaLoiCuoi AS lastError,
                    TrangThaiHoanTien AS refundStatus,
                    HoanTienIdempotencyKey AS refundIdempotencyKey,
                    ProviderRefundReference AS refundReference, LyDoHoanTien AS refundReason,
                    XuLyLuc AS processedAt
             FROM ThanhToan WHERE DatChoID=:bookingId' . ($lock ? ' FOR UPDATE' : '')
        );
        $statement->execute(['bookingId' => $bookingId]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            return null;
        }
        return new Payment(
            (int) $row['id'], (int) $row['bookingId'], (string) $row['timing'], (string) $row['status'],
            Money::vnd((string) $row['total']), Money::vnd((string) $row['paid']), Money::vnd((string) $row['refunded']),
            $row['idempotencyKey'] === null ? null : (string) $row['idempotencyKey'],
            $row['providerReference'] === null ? null : (string) $row['providerReference'],
            (int) $row['attempts'], $row['lastError'] === null ? null : (string) $row['lastError'],
            (string) $row['refundStatus'],
            $row['refundIdempotencyKey'] === null ? null : (string) $row['refundIdempotencyKey'],
            $row['refundReference'] === null ? null : (string) $row['refundReference'],
            $row['refundReason'] === null ? null : (string) $row['refundReason'],
            $row['processedAt'] === null ? null : new DateTimeImmutable((string) $row['processedAt'], new DateTimeZone('UTC')),
        );
    }

    private static function time(DateTimeImmutable $at): string
    {
        return $at->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s.u');
    }
}

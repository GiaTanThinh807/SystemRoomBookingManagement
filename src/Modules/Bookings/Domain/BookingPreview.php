<?php

declare(strict_types=1);

namespace App\Modules\Bookings\Domain;

use App\Shared\Money\Money;
use DateTimeImmutable;
use InvalidArgumentException;

final readonly class BookingPreview
{
    public const STATUS_VALID = 'VALID';
    public const STATUS_USED = 'USED';
    public const STATUS_INVALIDATED = 'INVALIDATED';

    /** @param array<string, mixed> $allocationSnapshot */
    public function __construct(
        public int $id,
        public int $customerId,
        public int $propertyId,
        public string $checkIn,
        public string $checkOut,
        public Money $grandTotal,
        public Money $payNowTotal,
        public Money $payAtPropertyTotal,
        public array $allocationSnapshot,
        public string $fingerprint,
        public string $status,
        public DateTimeImmutable $expiresAt,
        public DateTimeImmutable $createdAt,
        public ?DateTimeImmutable $usedAt,
    ) {
        if ($this->id < 1 || $this->customerId < 1 || $this->propertyId < 1) {
            throw new InvalidArgumentException('Preview đã lưu phải có các ID dương.');
        }
        if (!in_array($this->status, self::statuses(), true)) {
            throw new InvalidArgumentException('Trạng thái preview không hợp lệ.');
        }
        if (($this->status === self::STATUS_USED) !== ($this->usedAt !== null)) {
            throw new InvalidArgumentException('Thời điểm sử dụng phải khớp trạng thái USED.');
        }
        if (preg_match('/^[a-f0-9]{64}$/', $this->fingerprint) !== 1
            || ($this->allocationSnapshot['version'] ?? null) !== 1) {
            throw new InvalidArgumentException('Snapshot hoặc fingerprint preview đã lưu không hợp lệ.');
        }
        if (!$this->payNowTotal->add($this->payAtPropertyTotal)->equals($this->grandTotal)) {
            throw new InvalidArgumentException('Các tổng tiền preview đã lưu không khớp.');
        }
    }

    public function isExpiredAt(DateTimeImmutable $now): bool
    {
        return $this->expiresAt <= $now;
    }

    /** @return list<string> */
    public static function statuses(): array
    {
        return [self::STATUS_VALID, self::STATUS_USED, self::STATUS_INVALIDATED];
    }
}

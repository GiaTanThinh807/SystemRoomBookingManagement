<?php

declare(strict_types=1);

namespace App\Modules\Bookings\Domain;

use App\Shared\Money\Money;
use DateTimeImmutable;
use InvalidArgumentException;

final readonly class PreviewDraft
{
    /** @param array<string, mixed> $allocationSnapshot */
    public function __construct(
        public string $tokenHash,
        public int $customerId,
        public int $propertyId,
        public string $checkIn,
        public string $checkOut,
        public Money $grandTotal,
        public Money $payNowTotal,
        public Money $payAtPropertyTotal,
        public array $allocationSnapshot,
        public string $fingerprint,
        public DateTimeImmutable $expiresAt,
        public DateTimeImmutable $createdAt,
    ) {
        if (preg_match('/^[a-f0-9]{64}$/', $this->tokenHash) !== 1
            || preg_match('/^[a-f0-9]{64}$/', $this->fingerprint) !== 1) {
            throw new InvalidArgumentException('Token hash và fingerprint preview phải là SHA-256 dạng hex.');
        }
        if ($this->customerId < 1 || $this->propertyId < 1) {
            throw new InvalidArgumentException('Khách hàng và cơ sở của preview phải có ID dương.');
        }
        $checkIn = DateTimeImmutable::createFromFormat('!Y-m-d', $this->checkIn);
        $checkOut = DateTimeImmutable::createFromFormat('!Y-m-d', $this->checkOut);
        if ($checkIn === false || $checkOut === false
            || $checkIn->format('Y-m-d') !== $this->checkIn
            || $checkOut->format('Y-m-d') !== $this->checkOut
            || $checkIn >= $checkOut) {
            throw new InvalidArgumentException('Khoảng ngày preview không hợp lệ.');
        }
        if (($this->allocationSnapshot['version'] ?? null) !== 1) {
            throw new InvalidArgumentException('PhanBoJSON phải dùng schema version 1.');
        }
        if (!$this->payNowTotal->add($this->payAtPropertyTotal)->equals($this->grandTotal)) {
            throw new InvalidArgumentException('Tổng trả ngay và tại cơ sở phải bằng tổng preview.');
        }
        if ($this->expiresAt <= $this->createdAt || $this->expiresAt > $this->createdAt->modify('+15 minutes')) {
            throw new InvalidArgumentException('Preview phải hết hạn sau khi tạo và không quá 15 phút.');
        }
    }
}

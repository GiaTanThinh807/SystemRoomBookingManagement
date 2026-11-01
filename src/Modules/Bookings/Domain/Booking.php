<?php

declare(strict_types=1);

namespace App\Modules\Bookings\Domain;

use App\Shared\Money\Money;
use DateTimeImmutable;
use InvalidArgumentException;

final readonly class Booking
{
    /** @param list<array<string, mixed>> $items */
    public function __construct(
        public int $id,
        public string $code,
        public string $clientRequestId,
        public int $previewId,
        public int $customerId,
        public int $propertyId,
        public string $propertyName,
        public string $propertyAddress,
        public string $checkIn,
        public string $checkOut,
        public string $bookerName,
        public string $bookerEmail,
        public string $bookerPhone,
        public ?string $specialRequest,
        public string $paymentTiming,
        public Money $grandTotal,
        public string $status,
        public ?DateTimeImmutable $confirmedAt,
        public DateTimeImmutable $createdAt,
        public array $items,
    ) {
        if ($this->id < 1 || $this->previewId < 1 || $this->customerId < 1 || $this->propertyId < 1) {
            throw new InvalidArgumentException('Booking phải có các ID dương.');
        }
        if ($this->code === '' || $this->items === [] || !array_is_list($this->items)) {
            throw new InvalidArgumentException('Booking phải có mã và ít nhất một hạng mục.');
        }
        if (!in_array($this->paymentTiming, ['PAY_AT_PROPERTY', 'PAY_ONLINE'], true)
            || !in_array($this->status, ['PENDING_PAYMENT', 'CONFIRMED', 'PAYMENT_FAILED'], true)) {
            throw new InvalidArgumentException('Timing hoặc trạng thái booking chưa hợp lệ.');
        }
        if (($this->status === 'CONFIRMED') !== ($this->confirmedAt !== null)) {
            throw new InvalidArgumentException('Thời điểm xác nhận phải khớp trạng thái booking.');
        }
    }
}

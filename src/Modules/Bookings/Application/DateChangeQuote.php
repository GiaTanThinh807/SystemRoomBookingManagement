<?php

declare(strict_types=1);

namespace App\Modules\Bookings\Application;

use App\Shared\Contract\StayPeriod;
use App\Shared\Money\Money;
use DateTimeImmutable;

final readonly class DateChangeQuote
{
    /** @param list<array<string, mixed>> $items */
    public function __construct(
        public string $bookingCode,
        public StayPeriod $stay,
        public Money $oldTotal,
        public Money $newTotal,
        public string $fingerprint,
        public DateTimeImmutable $calculatedAt,
        public DateTimeImmutable $validUntil,
        public array $items,
    ) {
    }
}

<?php

declare(strict_types=1);

namespace App\Modules\Bookings\Application;

use App\Shared\Money\Money;
use DateTimeImmutable;

final readonly class CancellationQuote
{
    /** @param list<array<string, mixed>> $items */
    public function __construct(
        public string $bookingCode,
        public DateTimeImmutable $calculatedAt,
        public DateTimeImmutable $validUntil,
        public Money $fee,
        public Money $expectedRefund,
        public array $items,
    ) {
    }
}

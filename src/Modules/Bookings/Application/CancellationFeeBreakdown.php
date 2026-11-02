<?php

declare(strict_types=1);

namespace App\Modules\Bookings\Application;

use App\Shared\Money\Money;

final readonly class CancellationFeeBreakdown
{
    /** @param list<array<string, mixed>> $items */
    public function __construct(public Money $fee, public array $items)
    {
    }
}

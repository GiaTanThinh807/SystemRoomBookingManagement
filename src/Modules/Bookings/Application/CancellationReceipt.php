<?php

declare(strict_types=1);

namespace App\Modules\Bookings\Application;

use App\Shared\Money\Money;

final readonly class CancellationReceipt
{
    public function __construct(
        public string $bookingCode,
        public Money $fee,
        public Money $expectedRefund,
        public Money $refunded,
        public string $refundStatus,
    ) {
    }
}

<?php

declare(strict_types=1);

namespace App\Modules\Bookings\Application;

use App\Shared\Money\Money;
use InvalidArgumentException;

final readonly class PaymentGatewayRequest
{
    public function __construct(
        public int $bookingId,
        public Money $amount,
        public string $idempotencyKey,
        public ?string $providerReference = null,
    ) {
        if ($this->bookingId < 1 || $this->amount->amount() <= 0
            || preg_match('/^[0-9a-f-]{36}$/i', $this->idempotencyKey) !== 1) {
            throw new InvalidArgumentException('Yêu cầu payment gateway không hợp lệ.');
        }
    }
}

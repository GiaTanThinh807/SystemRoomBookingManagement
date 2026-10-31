<?php

declare(strict_types=1);

namespace App\Modules\Bookings\Application;

use App\Modules\Bookings\Domain\BookingPreview;
use InvalidArgumentException;

final readonly class CreatedPreview
{
    public function __construct(
        public BookingPreview $preview,
        public string $token,
    ) {
        if (preg_match('/^[a-f0-9]{64,}$/', $this->token) !== 1) {
            throw new InvalidArgumentException('Token preview thô không hợp lệ.');
        }
    }
}

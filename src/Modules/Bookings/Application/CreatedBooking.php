<?php

declare(strict_types=1);

namespace App\Modules\Bookings\Application;

use App\Modules\Bookings\Domain\Booking;

final readonly class CreatedBooking
{
    public function __construct(
        public Booking $booking,
        public bool $replayed,
    ) {
    }
}

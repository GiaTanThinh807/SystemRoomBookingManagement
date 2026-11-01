<?php

declare(strict_types=1);

namespace App\Modules\Bookings\Infrastructure;

final class BookingCodeGenerator
{
    public function generate(): string
    {
        return 'SB' . strtoupper(bin2hex(random_bytes(10)));
    }
}

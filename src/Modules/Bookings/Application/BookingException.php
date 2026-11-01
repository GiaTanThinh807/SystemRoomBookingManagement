<?php

declare(strict_types=1);

namespace App\Modules\Bookings\Application;

use RuntimeException;

final class BookingException extends RuntimeException
{
    public function __construct(
        public readonly string $errorCode,
        string $message,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }
}

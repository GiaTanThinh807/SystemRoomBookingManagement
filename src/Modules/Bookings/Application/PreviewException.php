<?php

declare(strict_types=1);

namespace App\Modules\Bookings\Application;

use RuntimeException;

final class PreviewException extends RuntimeException
{
    public function __construct(
        public readonly string $errorCode,
        string $message,
    ) {
        parent::__construct($message);
    }
}

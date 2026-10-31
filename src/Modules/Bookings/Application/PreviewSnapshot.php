<?php

declare(strict_types=1);

namespace App\Modules\Bookings\Application;

use InvalidArgumentException;

final readonly class PreviewSnapshot
{
    /** @param array<string, mixed> $data */
    public function __construct(
        public array $data,
        public string $fingerprint,
    ) {
        if (($this->data['version'] ?? null) !== 1
            || preg_match('/^[a-f0-9]{64}$/', $this->fingerprint) !== 1) {
            throw new InvalidArgumentException('Snapshot preview hoặc fingerprint không hợp lệ.');
        }
    }
}

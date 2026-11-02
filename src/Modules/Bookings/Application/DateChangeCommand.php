<?php

declare(strict_types=1);

namespace App\Modules\Bookings\Application;

use App\Shared\Contract\StayPeriod;
use InvalidArgumentException;

final readonly class DateChangeCommand
{
    public function __construct(public StayPeriod $stay, public string $quoteFingerprint)
    {
        if (preg_match('/^[a-f0-9]{64}$/', $this->quoteFingerprint) !== 1) {
            throw new InvalidArgumentException('Fingerprint báo giá đổi ngày không hợp lệ.');
        }
    }

    /** @param array<string, mixed> $input */
    public static function fromInput(array $input): self
    {
        return new self(
            new StayPeriod(
                trim(is_string($input['checkIn'] ?? null) ? $input['checkIn'] : ''),
                trim(is_string($input['checkOut'] ?? null) ? $input['checkOut'] : ''),
            ),
            strtolower(trim(is_string($input['quoteFingerprint'] ?? null) ? $input['quoteFingerprint'] : '')),
        );
    }
}

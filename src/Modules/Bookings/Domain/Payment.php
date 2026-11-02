<?php

declare(strict_types=1);

namespace App\Modules\Bookings\Domain;

use App\Shared\Money\Money;
use DateTimeImmutable;

final readonly class Payment
{
    public function __construct(
        public int $id,
        public int $bookingId,
        public string $timing,
        public string $status,
        public Money $total,
        public Money $paid,
        public Money $refunded,
        public ?string $idempotencyKey,
        public ?string $providerReference,
        public int $attempts,
        public ?string $lastError,
        public string $refundStatus,
        public ?string $refundIdempotencyKey,
        public ?string $refundReference,
        public ?string $refundReason,
        public ?DateTimeImmutable $processedAt,
    ) {
    }
}

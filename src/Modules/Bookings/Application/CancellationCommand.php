<?php

declare(strict_types=1);

namespace App\Modules\Bookings\Application;

use App\Shared\Json\CanonicalJson;
use App\Shared\Money\Money;
use InvalidArgumentException;

final readonly class CancellationCommand
{
    public function __construct(
        public string $idempotencyKey,
        public string $reason,
        public Money $expectedFee,
    ) {
        if (preg_match('/^[a-f0-9]{8}-[a-f0-9]{4}-[1-5][a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}$/i', $this->idempotencyKey) !== 1) {
            throw new InvalidArgumentException('Khóa idempotency hủy phải là UUID hợp lệ.');
        }
        $length = function_exists('mb_strlen') ? mb_strlen($this->reason) : strlen($this->reason);
        if ($length < 5 || $length > 1000) {
            throw new InvalidArgumentException('Lý do hủy phải có từ 5 đến 1000 ký tự.');
        }
        if ($this->expectedFee->amount() < 0) {
            throw new InvalidArgumentException('Phí hủy dự kiến không được âm.');
        }
    }

    /** @param array<string, mixed> $input */
    public static function fromInput(array $input): self
    {
        return new self(
            strtolower(trim(is_string($input['cancellationKey'] ?? null) ? $input['cancellationKey'] : '')),
            trim(is_string($input['reason'] ?? null) ? $input['reason'] : ''),
            Money::vnd(is_scalar($input['expectedFee'] ?? null) ? (string) $input['expectedFee'] : ''),
        );
    }

    public function fingerprint(int $bookingId, int $customerId): string
    {
        return CanonicalJson::hash([
            'bookingId' => $bookingId,
            'customerId' => $customerId,
            'idempotencyKey' => $this->idempotencyKey,
            'reason' => $this->reason,
            'expectedFee' => $this->expectedFee->decimal(),
        ]);
    }
}

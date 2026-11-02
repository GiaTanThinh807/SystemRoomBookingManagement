<?php

declare(strict_types=1);

namespace App\Modules\Bookings\Application;

use InvalidArgumentException;

final readonly class WaiverDecision
{
    public function __construct(public string $status, public string $response)
    {
        if (!in_array($this->status, ['APPROVED', 'REJECTED'], true)) {
            throw new InvalidArgumentException('Quyết định waiver chỉ có thể là APPROVED hoặc REJECTED.');
        }
        $length = function_exists('mb_strlen') ? mb_strlen($this->response) : strlen($this->response);
        if ($length < 2 || $length > 1000) {
            throw new InvalidArgumentException('Phản hồi waiver phải có từ 2 đến 1000 ký tự.');
        }
    }

    /** @param array<string, mixed> $input */
    public static function fromInput(array $input): self
    {
        return new self(
            strtoupper(trim(is_string($input['decision'] ?? null) ? $input['decision'] : '')),
            trim(is_string($input['response'] ?? null) ? $input['response'] : ''),
        );
    }
}

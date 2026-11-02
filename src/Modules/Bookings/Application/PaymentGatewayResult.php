<?php

declare(strict_types=1);

namespace App\Modules\Bookings\Application;

use App\Shared\Money\Money;
use InvalidArgumentException;

final readonly class PaymentGatewayResult
{
    public const SUCCESS = 'SUCCESS';
    public const FAILED = 'FAILED';
    public const TIMEOUT = 'TIMEOUT';

    public function __construct(
        public string $status,
        public ?string $providerReference = null,
        public ?string $errorCode = null,
        public ?Money $amount = null,
    ) {
        if (!in_array($this->status, [self::SUCCESS, self::FAILED, self::TIMEOUT], true)) {
            throw new InvalidArgumentException('Kết quả gateway không hợp lệ.');
        }
        if ($this->status === self::SUCCESS && trim((string) $this->providerReference) === '') {
            throw new InvalidArgumentException('Gateway success phải có provider reference.');
        }
        if ($this->status !== self::SUCCESS && trim((string) $this->errorCode) === '') {
            throw new InvalidArgumentException('Gateway failure/timeout phải có mã lỗi.');
        }
    }
}

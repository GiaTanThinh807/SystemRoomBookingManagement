<?php

declare(strict_types=1);

namespace App\Modules\Bookings\Application;

use App\Shared\Json\CanonicalJson;
use InvalidArgumentException;

final readonly class BookingRequest
{
    public function __construct(
        public string $clientRequestId,
        public string $bookerName,
        public string $bookerEmail,
        public string $bookerPhone,
        public ?string $specialRequest,
        public bool $termsAccepted,
    ) {
        if (preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i', $this->clientRequestId) !== 1) {
            throw new InvalidArgumentException('ClientRequestID phải là UUID hợp lệ.');
        }
        if ($this->textLength($this->bookerName) < 2 || $this->textLength($this->bookerName) > 150) {
            throw new InvalidArgumentException('Họ tên người đặt phải có từ 2 đến 150 ký tự.');
        }
        if (filter_var($this->bookerEmail, FILTER_VALIDATE_EMAIL) === false
            || strlen($this->bookerEmail) > 190) {
            throw new InvalidArgumentException('Email người đặt không hợp lệ.');
        }
        if (preg_match('/^\+?[0-9][0-9 .-]{7,18}$/', $this->bookerPhone) !== 1) {
            throw new InvalidArgumentException('Số điện thoại người đặt không hợp lệ.');
        }
        if ($this->specialRequest !== null && $this->textLength($this->specialRequest) > 1000) {
            throw new InvalidArgumentException('Yêu cầu đặc biệt không được vượt quá 1000 ký tự.');
        }
        if (!$this->termsAccepted) {
            throw new InvalidArgumentException('Bạn cần xác nhận đã xem giá và chính sách.');
        }
    }

    public static function fromInput(
        string $clientRequestId,
        string $bookerName,
        string $bookerEmail,
        string $bookerPhone,
        ?string $specialRequest,
        bool $termsAccepted,
    ): self {
        $specialRequest = trim((string) $specialRequest);
        return new self(
            strtolower(trim($clientRequestId)),
            trim($bookerName),
            strtolower(trim($bookerEmail)),
            trim($bookerPhone),
            $specialRequest === '' ? null : $specialRequest,
            $termsAccepted,
        );
    }

    public function payloadFingerprint(int $customerId, int $previewId, string $previewFingerprint): string
    {
        return CanonicalJson::hash([
            'customerId' => $customerId,
            'previewId' => $previewId,
            'previewFingerprint' => $previewFingerprint,
            'clientRequestId' => $this->clientRequestId,
            'bookerName' => $this->bookerName,
            'bookerEmail' => $this->bookerEmail,
            'bookerPhone' => $this->bookerPhone,
            'specialRequest' => $this->specialRequest,
            'termsAccepted' => true,
        ]);
    }

    private function textLength(string $value): int
    {
        return function_exists('mb_strlen') ? mb_strlen($value) : strlen($value);
    }
}

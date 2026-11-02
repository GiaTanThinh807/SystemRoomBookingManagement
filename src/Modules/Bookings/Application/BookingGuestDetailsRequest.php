<?php

declare(strict_types=1);

namespace App\Modules\Bookings\Application;

use App\Shared\Json\CanonicalJson;
use InvalidArgumentException;

final readonly class BookingGuestDetailsRequest
{
    /** @param array<int, list<string>> $guestNames */
    public function __construct(
        public string $bookerName,
        public string $bookerEmail,
        public string $bookerPhone,
        public ?string $specialRequest,
        public array $guestNames,
    ) {
        if ($this->length($this->bookerName) < 2 || $this->length($this->bookerName) > 150) {
            throw new InvalidArgumentException('Họ tên người đặt phải có từ 2 đến 150 ký tự.');
        }
        if (filter_var($this->bookerEmail, FILTER_VALIDATE_EMAIL) === false || strlen($this->bookerEmail) > 190) {
            throw new InvalidArgumentException('Email người đặt không hợp lệ.');
        }
        if (preg_match('/^\+?[0-9][0-9 .-]{7,18}$/', $this->bookerPhone) !== 1) {
            throw new InvalidArgumentException('Số điện thoại người đặt không hợp lệ.');
        }
        if ($this->specialRequest !== null && $this->length($this->specialRequest) > 1000) {
            throw new InvalidArgumentException('Yêu cầu đặc biệt không được vượt quá 1000 ký tự.');
        }
        foreach ($this->guestNames as $itemId => $names) {
            if (!is_int($itemId) || $itemId < 1 || !array_is_list($names) || $names === []) {
                throw new InvalidArgumentException('Danh sách tên khách không hợp lệ.');
            }
            foreach ($names as $name) {
                if (!is_string($name) || $this->length($name) < 1 || $this->length($name) > 150) {
                    throw new InvalidArgumentException('Mỗi tên khách phải có từ 1 đến 150 ký tự.');
                }
            }
        }
    }

    /** @param array<string, mixed> $input */
    public static function fromInput(array $input): self
    {
        $rawGuestNames = $input['guestNames'] ?? [];
        if (!is_array($rawGuestNames)) {
            throw new InvalidArgumentException('Danh sách tên khách phải là một object.');
        }
        $guestNames = [];
        foreach ($rawGuestNames as $itemId => $names) {
            if (filter_var($itemId, FILTER_VALIDATE_INT) === false || (int) $itemId < 1 || !is_array($names)) {
                throw new InvalidArgumentException('Hạng mục tên khách không hợp lệ.');
            }
            $guestNames[(int) $itemId] = array_map(
                static fn (mixed $name): string => is_string($name) ? trim($name) : '',
                array_values($names),
            );
        }
        ksort($guestNames, SORT_NUMERIC);
        $specialRequest = trim(is_string($input['specialRequest'] ?? null) ? $input['specialRequest'] : '');
        return new self(
            trim(is_string($input['bookerName'] ?? null) ? $input['bookerName'] : ''),
            strtolower(trim(is_string($input['bookerEmail'] ?? null) ? $input['bookerEmail'] : '')),
            trim(is_string($input['bookerPhone'] ?? null) ? $input['bookerPhone'] : ''),
            $specialRequest === '' ? null : $specialRequest,
            $guestNames,
        );
    }

    public function fingerprint(int $bookingId, int $customerId): string
    {
        return CanonicalJson::hash([
            'bookingId' => $bookingId,
            'customerId' => $customerId,
            'bookerName' => $this->bookerName,
            'bookerEmail' => $this->bookerEmail,
            'bookerPhone' => $this->bookerPhone,
            'specialRequest' => $this->specialRequest,
            'guestNames' => $this->guestNames,
        ]);
    }

    private function length(string $value): int
    {
        return function_exists('mb_strlen') ? mb_strlen($value) : strlen($value);
    }
}

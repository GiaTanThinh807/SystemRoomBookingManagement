<?php

declare(strict_types=1);

namespace App\Modules\Bookings\Infrastructure;

use InvalidArgumentException;
use RuntimeException;

final class PreviewTokenSession
{
    private const KEY = '_checkout_preview_token';

    public function store(string $token): void
    {
        $this->assertActive();
        if (preg_match('/^[a-f0-9]{64,}$/', $token) !== 1) {
            throw new InvalidArgumentException('Token preview không hợp lệ.');
        }
        $_SESSION[self::KEY] = $token;
    }

    public function current(): ?string
    {
        $this->assertActive();
        $token = $_SESSION[self::KEY] ?? null;
        return is_string($token) && preg_match('/^[a-f0-9]{64,}$/', $token) === 1 ? $token : null;
    }

    public function clear(): void
    {
        $this->assertActive();
        unset($_SESSION[self::KEY]);
    }

    private function assertActive(): void
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            throw new RuntimeException('Session checkout chưa được khởi tạo.');
        }
    }
}

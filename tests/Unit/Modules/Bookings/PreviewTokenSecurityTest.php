<?php

declare(strict_types=1);

namespace Tests\Unit\Modules\Bookings;

use App\Config\AppConfig;
use App\Http\Request;
use App\Modules\Bookings\Infrastructure\PreviewTokenSession;
use App\Shared\Http\ConflictHttpException;
use App\Shared\Http\ExceptionHandler;
use App\Shared\Logging\Logger;
use DateTimeZone;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Tests\Support\TestCase;

#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class PreviewTokenSecurityTest extends TestCase
{
    public function test_exception_logging_does_not_copy_preview_token_from_body_or_header(): void
    {
        $token = str_repeat('a1', 32);
        $logger = new class implements Logger {
            /** @var list<array<string, mixed>> */
            public array $records = [];

            public function log(string $level, string $message, array $context = []): void
            {
                $this->records[] = compact('level', 'message', 'context');
            }
        };
        $handler = new ExceptionHandler(
            new AppConfig('test', true, 'http://localhost', new DateTimeZone('UTC')),
            $logger,
        );

        $handler->handle(
            new ConflictHttpException('PREVIEW_STALE', 'Preview đã thay đổi.'),
            new Request(
                'GET',
                '/checkout',
                body: ['token' => $token],
                headers: ['x-preview-token' => $token],
                requestId: 'preview-token-log-test',
            ),
        );

        self::assertCount(1, $logger->records);
        self::assertStringNotContainsString($token, json_encode($logger->records, JSON_THROW_ON_ERROR));
        self::assertSame('/checkout', $logger->records[0]['context']['path']);
    }

    public function test_session_holder_never_places_token_in_a_url_shape(): void
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            session_save_path(sys_get_temp_dir());
            session_start();
        }
        $token = str_repeat('b2', 32);
        $session = new PreviewTokenSession();

        $session->store($token);

        self::assertSame($token, $session->current());
        self::assertArrayHasKey('_checkout_preview_token', $_SESSION);
        self::assertArrayNotHasKey('checkout_url', $_SESSION);
        $session->clear();
        self::assertNull($session->current());
    }
}

<?php

declare(strict_types=1);

namespace Tests\Unit\Modules\Bookings;

use App\Modules\Bookings\Application\BookingRequest;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class BookingRequestTest extends TestCase
{
    public function test_normalizes_contact_and_creates_stable_payload_fingerprint(): void
    {
        $request = BookingRequest::fromInput(
            'A1B2C3D4-1234-4ABC-8DEF-1234567890AB',
            '  Nguyễn Văn A  ',
            '  USER@Example.Test ',
            '0901 234 567',
            '  Phòng yên tĩnh  ',
            true,
        );

        self::assertSame('a1b2c3d4-1234-4abc-8def-1234567890ab', $request->clientRequestId);
        self::assertSame('Nguyễn Văn A', $request->bookerName);
        self::assertSame('user@example.test', $request->bookerEmail);
        self::assertSame('Phòng yên tĩnh', $request->specialRequest);
        self::assertMatchesRegularExpression(
            '/^[a-f0-9]{64}$/',
            $request->payloadFingerprint(10, 20, str_repeat('a', 64)),
        );
        self::assertSame(
            $request->payloadFingerprint(10, 20, str_repeat('a', 64)),
            $request->payloadFingerprint(10, 20, str_repeat('a', 64)),
        );
    }

    #[DataProvider('invalidInputProvider')]
    public function test_rejects_invalid_or_unconfirmed_input(array $input): void
    {
        $this->expectException(InvalidArgumentException::class);
        BookingRequest::fromInput(...$input);
    }

    /** @return iterable<string, array{array{string, string, string, string, ?string, bool}}> */
    public static function invalidInputProvider(): iterable
    {
        $valid = [
            'a1b2c3d4-1234-4abc-8def-1234567890ab',
            'Nguyễn Văn A',
            'user@example.test',
            '0901234567',
            null,
            true,
        ];
        yield 'UUID sai' => [[...$valid, 0 => 'not-a-uuid']];
        yield 'email sai' => [[...$valid, 2 => 'invalid-email']];
        yield 'điện thoại sai' => [[...$valid, 3 => '123']];
        yield 'chưa xác nhận' => [[...$valid, 5 => false]];
    }
}

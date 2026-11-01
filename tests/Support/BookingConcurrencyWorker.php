<?php

declare(strict_types=1);

use App\Bootstrap\App;
use App\Modules\Bookings\Application\BookingException;
use App\Modules\Bookings\Application\BookingRequest;
use App\Modules\Bookings\Application\BookingService;

require dirname(__DIR__, 2) . '/vendor/autoload.php';

try {
    $payload = json_decode(base64_decode((string) ($argv[1] ?? ''), true), true, 32, JSON_THROW_ON_ERROR);
    if (!is_array($payload)) {
        throw new RuntimeException('Payload worker không hợp lệ.');
    }
    $service = App::boot(dirname(__DIR__, 2))->container()->get(BookingService::class);
    $created = $service->createPayAtProperty(
        (string) ($payload['previewToken'] ?? ''),
        (int) ($payload['customerId'] ?? 0),
        BookingRequest::fromInput(
            (string) ($payload['clientRequestId'] ?? ''),
            'Khách kiểm thử concurrency',
            'phase9-concurrency@example.test',
            '0901234567',
            null,
            true,
        ),
        (string) ($payload['requestId'] ?? ''),
        '127.0.0.1',
    );
    echo json_encode([
        'success' => true,
        'bookingId' => $created->booking->id,
        'code' => $created->booking->code,
    ], JSON_THROW_ON_ERROR);
} catch (BookingException $exception) {
    echo json_encode([
        'success' => false,
        'error' => $exception->errorCode,
    ], JSON_THROW_ON_ERROR);
} catch (Throwable $exception) {
    fwrite(STDERR, $exception::class . ': ' . $exception->getMessage());
    exit(1);
}

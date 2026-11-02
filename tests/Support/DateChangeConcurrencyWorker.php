<?php

declare(strict_types=1);

use App\Config\DatabaseConfig;
use App\Config\Env;
use App\Modules\Inventory\Application\InventoryDateChangeService;
use App\Modules\Inventory\Infrastructure\InventoryRepository;
use App\Shared\Clock\SystemClock;
use App\Shared\Contract\StayPeriod;
use App\Shared\Database\Connection;

require dirname(__DIR__, 2) . '/vendor/autoload.php';

Env::load(dirname(__DIR__, 2) . '/.env');
$payload = json_decode(base64_decode((string) ($argv[1] ?? ''), true) ?: '', true);
if (!is_array($payload)) {
    fwrite(STDERR, 'Payload worker đổi ngày không hợp lệ.');
    exit(2);
}

$pdo = Connection::create(DatabaseConfig::fromEnv());
try {
    $pdo->beginTransaction();
    $result = (new InventoryDateChangeService(
        $pdo,
        new InventoryRepository($pdo),
        new SystemClock(),
    ))->reschedule(
        (int) $payload['bookingId'],
        $payload['items'],
        new StayPeriod((string) $payload['oldCheckIn'], (string) $payload['oldCheckOut']),
        new StayPeriod((string) $payload['newCheckIn'], (string) $payload['newCheckOut']),
    );
    $pdo->commit();
    echo json_encode(['success' => true, 'result' => $result], JSON_THROW_ON_ERROR);
} catch (Throwable $exception) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    echo json_encode([
        'success' => false,
        'error' => $exception::class,
        'message' => $exception->getMessage(),
    ], JSON_THROW_ON_ERROR);
}

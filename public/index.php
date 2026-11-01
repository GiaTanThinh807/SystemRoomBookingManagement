<?php

declare(strict_types=1);

use App\Bootstrap\App;

$projectRoot = dirname(__DIR__);
// Resolve the Composer autoloader from the project root.
$composerAutoload = $projectRoot . '/vendor/autoload.php';

if (is_file($composerAutoload)) {
    require $composerAutoload;
} else {
    spl_autoload_register(static function (string $class) use ($projectRoot): void {
        $prefix = 'App\\';
        if (!str_starts_with($class, $prefix)) {
            return;
        }

        $relative = str_replace('\\', DIRECTORY_SEPARATOR, substr($class, strlen($prefix)));
        $file = $projectRoot . '/src/' . $relative . '.php';
        if (is_file($file)) {
            require $file;
        }
    });
}

try {
    App::boot($projectRoot)->run();
} catch (Throwable $exception) {
    $requestId = bin2hex(random_bytes(16));
    error_log('APP_BOOT_FAILED requestId=' . $requestId . ' exception=' . $exception::class);

    http_response_code(500);
    header('Content-Type: text/html; charset=UTF-8');
    header('X-Request-ID: ' . $requestId);
    echo '<!doctype html><html lang="vi"><meta charset="utf-8">'
        . '<title>Hệ thống tạm thời gặp sự cố</title>'
        . '<h1>Hệ thống tạm thời gặp sự cố</h1>'
        . '<p>Vui lòng thử lại sau.</p><small>Mã yêu cầu: '
        . htmlspecialchars($requestId, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')
        . '</small></html>';
}


<?php

declare(strict_types=1);

use App\Bootstrap\App;

$projectRoot = dirname(__DIR__);
require $projectRoot . '/vendor/autoload.php';

try {
    $app = App::boot($projectRoot);
    $register = require $projectRoot . '/src/routes/console.php';
    $commands = $register($app->container());
    $name = $argv[1] ?? '';
    if ($name === '' || !isset($commands[$name])) {
        fwrite(STDERR, 'Usage: php bin/console.php <command> [arguments]' . PHP_EOL);
        fwrite(STDERR, 'Commands: ' . implode(', ', array_keys($commands)) . PHP_EOL);
        exit(2);
    }
    exit($commands[$name](array_slice($argv, 2)));
} catch (Throwable $exception) {
    fwrite(STDERR, $exception->getMessage() . PHP_EOL);
    exit(1);
}

<?php

declare(strict_types=1);

use App\Bootstrap\Container;
use App\Modules\Bookings\Application\PaymentExpiryService;

return static function (Container $container): array {
    return [
        'payment:expire' => static function (array $arguments) use ($container): int {
            $limit = isset($arguments[0]) ? filter_var($arguments[0], FILTER_VALIDATE_INT) : 100;
            if (!is_int($limit) || $limit < 1 || $limit > 1_000) {
                throw new InvalidArgumentException('Batch size phải là số nguyên từ 1 đến 1000.');
            }
            $expired = $container->get(PaymentExpiryService::class)->expireDue($limit);
            fwrite(STDOUT, "Expired payments: {$expired}" . PHP_EOL);
            return 0;
        },
    ];
};

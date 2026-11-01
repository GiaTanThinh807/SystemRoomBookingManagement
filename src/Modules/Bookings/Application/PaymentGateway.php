<?php

declare(strict_types=1);

namespace App\Modules\Bookings\Application;

interface PaymentGateway
{
    public function charge(PaymentGatewayRequest $request): PaymentGatewayResult;

    public function refund(PaymentGatewayRequest $request): PaymentGatewayResult;

    public function void(PaymentGatewayRequest $request): PaymentGatewayResult;
}

<?php

declare(strict_types=1);

namespace App\Modules\Bookings\Infrastructure;

use App\Modules\Bookings\Application\PaymentGateway;
use App\Modules\Bookings\Application\PaymentGatewayRequest;
use App\Modules\Bookings\Application\PaymentGatewayResult;

final readonly class MockPaymentGateway implements PaymentGateway
{
    public function __construct(private string $outcome = PaymentGatewayResult::SUCCESS)
    {
    }

    public function charge(PaymentGatewayRequest $request): PaymentGatewayResult
    {
        return $this->result('PAY', $request);
    }

    public function refund(PaymentGatewayRequest $request): PaymentGatewayResult
    {
        return new PaymentGatewayResult(
            PaymentGatewayResult::SUCCESS,
            'MOCK-REFUND-' . strtoupper(substr(hash('sha256', $request->idempotencyKey), 0, 20)),
            null,
            $request->amount,
        );
    }

    public function void(PaymentGatewayRequest $request): PaymentGatewayResult
    {
        return new PaymentGatewayResult(
            PaymentGatewayResult::SUCCESS,
            'MOCK-VOID-' . strtoupper(substr(hash('sha256', $request->idempotencyKey), 0, 20)),
        );
    }

    private function result(string $prefix, PaymentGatewayRequest $request): PaymentGatewayResult
    {
        return match ($this->outcome) {
            PaymentGatewayResult::FAILED => new PaymentGatewayResult(PaymentGatewayResult::FAILED, null, 'MOCK_DECLINED'),
            PaymentGatewayResult::TIMEOUT => new PaymentGatewayResult(PaymentGatewayResult::TIMEOUT, null, 'MOCK_TIMEOUT'),
            default => new PaymentGatewayResult(
                PaymentGatewayResult::SUCCESS,
                'MOCK-' . $prefix . '-' . strtoupper(substr(hash('sha256', $request->idempotencyKey), 0, 20)),
            ),
        };
    }
}

<?php

declare(strict_types=1);

namespace App\Modules\Bookings\Application;

use App\Modules\Bookings\Infrastructure\CancellationRepository;
use App\Shared\Clock\ClockInterface;
use App\Shared\Money\Money;
use DateTimeImmutable;
use DateTimeZone;

final readonly class CancellationQuoteService
{
    public function __construct(
        private CancellationRepository $cancellations,
        private CancellationFeeService $fees,
        private ClockInterface $clock,
    ) {
    }

    public function preview(string $code, int $customerId): CancellationQuote
    {
        $context = $this->cancellations->findForCustomer($this->code($code), $customerId);
        if ($context === null) {
            throw new BookingException('BOOKING_NOT_FOUND', 'Không tìm thấy booking thuộc tài khoản hiện tại.');
        }
        if (($context['status'] ?? null) !== 'CONFIRMED') {
            throw new BookingException('BOOKING_NOT_CANCELLABLE', 'Chỉ booking đã xác nhận mới được hủy trực tiếp.');
        }
        $now = $this->clock->now();
        $cutoff = $this->cutoff($context);
        if ($now >= $cutoff) {
            throw new BookingException('BOOKING_CUTOFF_PASSED', 'Đã đến hoặc qua thời điểm nhận phòng của cơ sở.');
        }
        $breakdown = $this->fees->calculate($context, $now);
        $paid = Money::vnd((string) ($context['paid'] ?? '0'));
        $expectedRefund = $paid->compareTo($breakdown->fee) > 0
            ? $paid->subtract($breakdown->fee)
            : Money::vnd(0);
        $validUntil = $now->modify('+5 minutes');
        if ($validUntil > $cutoff) {
            $validUntil = $cutoff;
        }
        return new CancellationQuote(
            (string) $context['code'],
            $now,
            $validUntil,
            $breakdown->fee,
            $expectedRefund,
            $breakdown->items,
        );
    }

    /** @param array<string, mixed> $context */
    private function cutoff(array $context): DateTimeImmutable
    {
        return new DateTimeImmutable(
            (string) $context['checkIn'] . ' ' . (string) $context['checkInTime'],
            new DateTimeZone((string) $context['timezone']),
        );
    }

    private function code(string $code): string
    {
        $code = strtoupper(trim($code));
        if ($code === '' || strlen($code) > 24 || preg_match('/^[A-Z0-9-]+$/', $code) !== 1) {
            throw new BookingException('BOOKING_NOT_FOUND', 'Không tìm thấy booking thuộc tài khoản hiện tại.');
        }
        return $code;
    }
}

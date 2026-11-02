<?php

declare(strict_types=1);

namespace App\Modules\Bookings\Application;

use App\Modules\Commercial\Application\CancellationCalculator;
use App\Shared\Money\Money;
use DateTimeImmutable;
use InvalidArgumentException;

final readonly class CancellationFeeService
{
    public function __construct(private CancellationCalculator $calculator)
    {
    }

    /** @param array<string, mixed> $context */
    public function calculate(array $context, DateTimeImmutable $at): CancellationFeeBreakdown
    {
        $total = Money::vnd((string) ($context['grandTotal'] ?? ''));
        $items = $context['items'] ?? null;
        if (!is_array($items) || $items === [] || !array_is_list($items)) {
            throw new InvalidArgumentException('Booking không có snapshot hạng mục để tính phí hủy.');
        }

        $fee = Money::vnd(0);
        $breakdown = [];
        foreach ($items as $item) {
            if (!is_array($item)) {
                throw new InvalidArgumentException('Snapshot hạng mục hủy không hợp lệ.');
            }
            $snapshot = $item['cancellationSnapshot'] ?? null;
            if (!is_array($snapshot)) {
                throw new InvalidArgumentException('Snapshot chính sách hủy không hợp lệ.');
            }
            $itemTotal = Money::vnd((string) ($item['total'] ?? ''));
            if (isset($snapshot['policyType'])) {
                $itemFee = $this->calculator->calculate(
                    $itemTotal,
                    [[
                        'total' => $itemTotal,
                        'nights' => $item['nights'] ?? [],
                        'cancellationSnapshot' => $snapshot,
                    ]],
                    (string) ($context['checkIn'] ?? ''),
                    (string) ($context['checkInTime'] ?? ''),
                    (string) ($context['timezone'] ?? ''),
                    $at,
                );
                $explanation = $this->explanation($snapshot, $itemFee);
                $source = 'BOOKING_POLICY_SNAPSHOT';
            } elseif (array_key_exists('absoluteFeeAtBooking', $snapshot)) {
                $legacy = Money::vnd((string) $snapshot['absoluteFeeAtBooking']);
                $itemFee = $legacy->compareTo($itemTotal) > 0 ? $itemTotal : $legacy;
                $explanation = 'Phí cố định từ snapshot booking legacy.';
                $source = 'LEGACY_ABSOLUTE_SNAPSHOT';
            } else {
                throw new InvalidArgumentException('Snapshot hủy thiếu quy tắc tính phí.');
            }
            $fee = $fee->add($itemFee);
            $breakdown[] = [
                'bookingItemId' => (int) ($item['id'] ?? 0),
                'roomTypeName' => (string) ($item['roomTypeName'] ?? ''),
                'fee' => $itemFee->decimal(),
                'source' => $source,
                'explanation' => $explanation,
            ];
        }
        if ($fee->compareTo($total) > 0) {
            $fee = $total;
        }
        return new CancellationFeeBreakdown($fee, $breakdown);
    }

    /** @param array<string, mixed> $snapshot */
    private function explanation(array $snapshot, Money $fee): string
    {
        if ($fee->isZero()) {
            return 'Đang trong thời hạn hủy miễn phí của snapshot.';
        }
        return match ((string) ($snapshot['penaltyType'] ?? '')) {
            'NONE' => 'Snapshot không áp dụng phí hủy.',
            'PERCENT' => 'Phí bằng ' . (string) $snapshot['penaltyValue'] . '% giá hạng mục.',
            'FIRST_NIGHT' => 'Phí bằng giá đêm đầu tiên.',
            'FULL_STAY' => 'Phí bằng toàn bộ giá trị hạng mục.',
            default => 'Phí được tính từ snapshot chính sách lúc đặt.',
        };
    }
}

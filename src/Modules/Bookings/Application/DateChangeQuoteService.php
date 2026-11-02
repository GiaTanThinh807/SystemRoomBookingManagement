<?php

declare(strict_types=1);

namespace App\Modules\Bookings\Application;

use App\Modules\Bookings\Infrastructure\DateChangeRepository;
use App\Modules\Commercial\Application\PriceCalculator;
use App\Shared\Clock\ClockInterface;
use App\Shared\Contract\RoomParty;
use App\Shared\Contract\StayPeriod;
use App\Shared\Json\CanonicalJson;
use App\Shared\Money\Money;
use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;

final readonly class DateChangeQuoteService
{
    public function __construct(
        private DateChangeRepository $dates,
        private PriceCalculator $prices,
        private ClockInterface $clock,
    ) {
    }

    public function preview(string $code, int $customerId, StayPeriod $stay): DateChangeQuote
    {
        $context = $this->dates->findForCustomer($this->code($code), $customerId);
        if ($context === null) {
            throw new BookingException('BOOKING_NOT_FOUND', 'Không tìm thấy booking thuộc tài khoản hiện tại.');
        }
        $quote = $this->calculate($context, $stay, false);
        if (!$this->dates->hasCapacity((int) $context['id'], $context['items'], $stay)) {
            throw new BookingException('INVENTORY_UNAVAILABLE', 'Không đủ phòng cho toàn bộ kỳ lưu trú mới.');
        }
        return $quote;
    }

    /** @param array<string, mixed> $context */
    public function calculate(array $context, StayPeriod $stay, bool $lockPricing): DateChangeQuote
    {
        $this->assertEligible($context);
        $now = $this->clock->now();
        if ($stay->checkIn === $context['checkIn'] && $stay->checkOut === $context['checkOut']) {
            throw new BookingException('DATE_CHANGE_UNCHANGED', 'Ngày lưu trú mới phải khác booking hiện tại.');
        }
        $items = [];
        $total = Money::vnd(0);
        foreach ($context['items'] as $item) {
            $pricing = $this->dates->pricingSnapshot(
                (int) $context['propertyId'],
                (int) $item['productId'],
                $stay,
                $now,
                $lockPricing,
            );
            if ($pricing === null) {
                throw new BookingException('DATE_CHANGE_UNAVAILABLE', 'Sản phẩm phòng hiện không hỗ trợ đổi ngày.');
            }
            try {
                $breakdown = $this->prices->calculate($pricing, $stay, $this->party($item), $now);
            } catch (InvalidArgumentException $exception) {
                throw new BookingException('DATE_CHANGE_UNAVAILABLE', $exception->getMessage(), $exception);
            }
            $items[] = [
                'id' => (int) $item['id'],
                'roomTypeId' => (int) $item['roomTypeId'],
                'productId' => (int) $item['productId'],
                'roomOrder' => (int) $item['roomOrder'],
                'grandTotal' => $breakdown->grandTotal->decimal(),
                'nights' => $breakdown->nights,
                'paymentSnapshot' => $breakdown->paymentSnapshot,
                'cancellationSnapshot' => [
                    ...$breakdown->cancellationSnapshot,
                    'bookedAt' => $now->format(DATE_ATOM),
                    'timezone' => $pricing->timezone,
                    'localCheckIn' => $stay->checkIn . ' ' . (string) $context['checkInTime'],
                    'absoluteFeeAtBooking' => '0',
                ],
                'rateVersions' => $breakdown->rateVersions,
            ];
            $total = $total->add($breakdown->grandTotal);
        }
        $fingerprintItems = array_map(static fn (array $item): array => [
            'id' => $item['id'],
            'roomTypeId' => $item['roomTypeId'],
            'productId' => $item['productId'],
            'roomOrder' => $item['roomOrder'],
            'grandTotal' => $item['grandTotal'],
            'nights' => $item['nights'],
            'paymentSnapshot' => $item['paymentSnapshot'],
            'cancellationPolicy' => [
                'id' => $item['cancellationSnapshot']['id'] ?? null,
                'policyType' => $item['cancellationSnapshot']['policyType'] ?? null,
                'freeCancellationHours' => $item['cancellationSnapshot']['freeCancellationHours'] ?? null,
                'penaltyType' => $item['cancellationSnapshot']['penaltyType'] ?? null,
                'penaltyValue' => $item['cancellationSnapshot']['penaltyValue'] ?? null,
                'noShowPenaltyRate' => $item['cancellationSnapshot']['noShowPenaltyRate'] ?? null,
            ],
            'rateVersions' => $item['rateVersions'],
        ], $items);
        $fingerprint = CanonicalJson::hash([
            'bookingId' => (int) $context['id'],
            'customerId' => (int) $context['customerId'],
            'stay' => $stay,
            'items' => $fingerprintItems,
            'newTotal' => $total->decimal(),
        ]);
        $cutoff = $this->cutoff($context);
        $validUntil = $now->modify('+5 minutes');
        if ($validUntil > $cutoff) {
            $validUntil = $cutoff;
        }
        return new DateChangeQuote(
            (string) $context['code'],
            $stay,
            Money::vnd((string) $context['grandTotal']),
            $total,
            $fingerprint,
            $now,
            $validUntil,
            $items,
        );
    }

    /** @param array<string, mixed> $context */
    public function assertEligible(array $context): void
    {
        if ($context['status'] !== 'CONFIRMED'
            || $context['paymentTiming'] !== 'PAY_AT_PROPERTY'
            || $context['paymentStatus'] !== 'NOT_TRACKED') {
            throw new BookingException('DATE_CHANGE_NOT_ALLOWED', 'Chỉ booking PAY_AT_PROPERTY đã xác nhận mới được đổi ngày.');
        }
        if ($this->clock->now() >= $this->cutoff($context)) {
            throw new BookingException('BOOKING_CUTOFF_PASSED', 'Đã đến hoặc qua thời điểm nhận phòng của cơ sở.');
        }
    }

    /** @param array<string, mixed> $item */
    private function party(array $item): RoomParty
    {
        $party = $item['party'];
        if (!array_is_list($party)) {
            return new RoomParty(
                (int) $item['roomOrder'],
                (int) ($party['adults'] ?? 0),
                array_map('intval', is_array($party['childAges'] ?? null) ? $party['childAges'] : []),
            );
        }
        $adults = 0;
        $children = [];
        foreach ($party as $guest) {
            if (is_array($guest) && ($guest['type'] ?? 'ADULT') === 'CHILD') {
                $children[] = (int) ($guest['age'] ?? 0);
            } else {
                ++$adults;
            }
        }
        return new RoomParty((int) $item['roomOrder'], max(1, $adults), $children);
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

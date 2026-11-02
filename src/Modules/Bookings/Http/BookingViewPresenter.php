<?php

declare(strict_types=1);

namespace App\Modules\Bookings\Http;

use App\Modules\Bookings\Application\BookingListPage;
use App\Modules\Bookings\Domain\Booking;

final class BookingViewPresenter
{
    /** @return array<string, mixed> */
    public function page(BookingListPage $page): array
    {
        return [
            'items' => $page->items,
            'page' => $page->page,
            'perPage' => $page->perPage,
            'total' => $page->total,
            'pageCount' => $page->pageCount(),
        ];
    }

    /** @return array<string, mixed> */
    public function booking(Booking $booking): array
    {
        return [
            'id' => $booking->id,
            'code' => $booking->code,
            'customerId' => $booking->customerId,
            'property' => ['name' => $booking->propertyName, 'address' => $booking->propertyAddress],
            'checkIn' => $booking->checkIn,
            'checkOut' => $booking->checkOut,
            'booker' => [
                'name' => $booking->bookerName,
                'email' => $booking->bookerEmail,
                'phone' => $booking->bookerPhone,
                'specialRequest' => $booking->specialRequest,
            ],
            'paymentTiming' => $booking->paymentTiming,
            'grandTotal' => $booking->grandTotal->decimal(),
            'status' => $booking->status,
            'confirmedAt' => $booking->confirmedAt?->format(DATE_ATOM),
            'createdAt' => $booking->createdAt->format(DATE_ATOM),
            'items' => array_map(fn (array $item): array => [
                ...$item,
                'guests' => $this->guests($item['party']),
            ], $booking->items),
        ];
    }

    /** @param array<mixed> $party @return list<array{name: string, type: string, age: int|null}> */
    private function guests(array $party): array
    {
        if (array_is_list($party)) {
            return array_map(static fn (mixed $guest): array => is_array($guest) ? [
                'name' => (string) ($guest['name'] ?? ''),
                'type' => (string) ($guest['type'] ?? 'GUEST'),
                'age' => isset($guest['age']) ? (int) $guest['age'] : null,
            ] : ['name' => '', 'type' => 'GUEST', 'age' => null], $party);
        }

        $adults = max(0, (int) ($party['adults'] ?? 0));
        $childAges = is_array($party['childAges'] ?? null) ? array_values($party['childAges']) : [];
        $names = is_array($party['guestNames'] ?? null) ? array_values($party['guestNames']) : [];
        $guests = [];
        for ($index = 0; $index < $adults; ++$index) {
            $guests[] = ['name' => (string) ($names[$index] ?? ''), 'type' => 'ADULT', 'age' => null];
        }
        foreach ($childAges as $childIndex => $age) {
            $guests[] = [
                'name' => (string) ($names[$adults + $childIndex] ?? ''),
                'type' => 'CHILD',
                'age' => (int) $age,
            ];
        }
        return $guests;
    }
}

<?php

declare(strict_types=1);

namespace App\Modules\Bookings\Application;

use App\Modules\Search\Application\PropertySearchSnapshot;
use App\Modules\Search\Application\RoomProductSearchSnapshot;
use App\Shared\Contract\RoomAllocation;
use App\Shared\Contract\RoomParty;
use App\Shared\Contract\SearchCriteria;
use App\Shared\Contract\StayPeriod;
use App\Shared\Json\CanonicalJson;
use InvalidArgumentException;

final readonly class PreviewSnapshotFactory
{
    /**
     * @param list<RoomAllocation> $rooms
     * @param list<string> $warnings
     */
    public function create(
        PropertySearchSnapshot $property,
        SearchCriteria $criteria,
        array $rooms,
        string $paymentTiming,
        string $selectionFingerprint,
        array $warnings = [],
    ): PreviewSnapshot {
        if (!in_array($paymentTiming, ['PAY_AT_PROPERTY', 'PAY_ONLINE'], true)) {
            throw new PreviewException('MIXED_PAYMENT_TIMING', 'Thời điểm thanh toán của preview không hợp lệ.');
        }
        if ($rooms === [] || !array_is_list($rooms)) {
            throw new PreviewException('PREVIEW_UNAVAILABLE', 'Không có phân bổ phòng để tạo preview.');
        }
        if (preg_match('/^[a-f0-9]{64}$/', $selectionFingerprint) !== 1) {
            throw new InvalidArgumentException('Selection fingerprint không hợp lệ.');
        }

        $products = [];
        foreach ($property->roomProducts as $product) {
            $products[$product->productId] = $product;
        }

        $roomSnapshots = [];
        foreach ($rooms as $room) {
            if (!$room instanceof RoomAllocation
                || $room->paymentTiming !== $paymentTiming
                || $room->priceBreakdown->paymentTiming !== $paymentTiming) {
                throw new PreviewException(
                    'MIXED_PAYMENT_TIMING',
                    'Mọi phòng trong preview phải dùng cùng một thời điểm thanh toán.',
                );
            }
            $product = $products[$room->productId] ?? null;
            if (!$product instanceof RoomProductSearchSnapshot
                || $product->roomTypeId !== $room->roomTypeId
                || $product->paymentTiming !== $paymentTiming) {
                throw new PreviewException(
                    'SELECTION_CHANGED',
                    'Sản phẩm đã chọn không còn khớp với phân bổ hiện tại.',
                );
            }
            $roomSnapshots[] = [
                'roomOrder' => $room->roomOrder,
                'roomTypeId' => $room->roomTypeId,
                'roomTypeName' => $product->roomTypeName,
                'productId' => $room->productId,
                'productName' => $product->productName,
                'mealType' => $product->mealType,
                'party' => $room->party,
                'nightPrices' => $room->priceBreakdown->nights,
                'cancellationSnapshot' => $room->priceBreakdown->cancellationSnapshot,
                'paymentSnapshot' => $room->priceBreakdown->paymentSnapshot,
                'priceBreakdown' => $room->priceBreakdown,
                'inventoryVersions' => $product->inventoryVersions,
            ];
        }

        $data = [
            'version' => 1,
            'property' => [
                'id' => $property->propertyId,
                'slug' => $property->slug,
                'name' => $property->name,
                'address' => $property->address,
                'checkInTime' => $property->checkInTime,
                'checkOutTime' => $property->checkOutTime,
                'timezone' => $property->timezone,
                'taxRate' => $property->taxRate,
                'taxIncluded' => $property->taxIncluded,
                'serviceFeeRate' => $property->serviceFeeRate,
                'serviceFeeIncluded' => $property->serviceFeeIncluded,
            ],
            'criteria' => $criteria,
            'paymentTiming' => $paymentTiming,
            'selectionFingerprint' => $selectionFingerprint,
            'rooms' => $roomSnapshots,
            'warnings' => array_values($warnings),
        ];

        $fingerprintData = $data;
        unset($fingerprintData['warnings']);
        return new PreviewSnapshot($data, CanonicalJson::hash($fingerprintData));
    }

    public function criteriaFromSnapshot(array $snapshot): SearchCriteria
    {
        $criteria = $snapshot['criteria'] ?? null;
        if (!is_array($criteria)) {
            throw new InvalidArgumentException('Snapshot preview thiếu criteria.');
        }
        $stay = $criteria['stayPeriod'] ?? null;
        $parties = $criteria['parties'] ?? null;
        if (!is_array($stay) || !is_array($parties) || !array_is_list($parties)) {
            throw new InvalidArgumentException('Criteria trong preview sai cấu trúc.');
        }
        $roomParties = [];
        foreach ($parties as $party) {
            if (!is_array($party)) {
                throw new InvalidArgumentException('Nhóm khách trong preview sai cấu trúc.');
            }
            $roomParties[] = new RoomParty(
                (int) ($party['roomOrder'] ?? 0),
                (int) ($party['adults'] ?? 0),
                is_array($party['childAges'] ?? null) ? array_map('intval', $party['childAges']) : [],
            );
        }

        return new SearchCriteria(
            (string) ($criteria['destination'] ?? ''),
            new StayPeriod((string) ($stay['checkIn'] ?? ''), (string) ($stay['checkOut'] ?? '')),
            $roomParties,
            is_array($criteria['filters'] ?? null) ? $criteria['filters'] : [],
            (string) ($criteria['sort'] ?? 'RECOMMENDED'),
            (int) ($criteria['page'] ?? 1),
        );
    }

    /** @return array<int, array{roomTypeId: int, productId: int}> */
    public function selectedFromSnapshot(array $snapshot): array
    {
        $rooms = $snapshot['rooms'] ?? null;
        if (!is_array($rooms) || !array_is_list($rooms)) {
            throw new InvalidArgumentException('Snapshot preview thiếu danh sách phòng.');
        }
        $selected = [];
        foreach ($rooms as $room) {
            if (!is_array($room)) {
                throw new InvalidArgumentException('Phòng trong preview sai cấu trúc.');
            }
            $order = (int) ($room['roomOrder'] ?? 0);
            $selected[$order] = [
                'roomTypeId' => (int) ($room['roomTypeId'] ?? 0),
                'productId' => (int) ($room['productId'] ?? 0),
            ];
        }
        ksort($selected, SORT_NUMERIC);
        return $selected;
    }
}

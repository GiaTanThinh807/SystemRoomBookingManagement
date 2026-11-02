<?php

declare(strict_types=1);

namespace App\Modules\Bookings\Application;

use App\Modules\Bookings\Domain\Booking;
use App\Modules\Bookings\Infrastructure\BookingRepository;
use App\Shared\Audit\AuditTrail;
use App\Shared\Clock\ClockInterface;
use App\Shared\Database\TransactionManager;
use DateTimeImmutable;
use DateTimeZone;
use RuntimeException;

final readonly class BookingModificationService
{
    public function __construct(
        private BookingRepository $bookings,
        private TransactionManager $transactions,
        private AuditTrail $audit,
        private ClockInterface $clock,
    ) {
    }

    public function updateGuestDetails(
        string $code,
        int $customerId,
        BookingGuestDetailsRequest $request,
        string $requestId,
        string $clientIp,
    ): Booking {
        $this->assertRequestId($requestId);
        $code = strtoupper(trim($code));
        if ($customerId < 1 || $code === '' || strlen($code) > 24 || preg_match('/^[A-Z0-9-]+$/', $code) !== 1) {
            throw new BookingException('BOOKING_NOT_FOUND', 'Không tìm thấy booking thuộc tài khoản hiện tại.');
        }
        $bookingId = $this->transactions->run(function () use (
            $code,
            $customerId,
            $request,
            $requestId,
            $clientIp,
        ): int {
            $state = $this->bookings->lockForCustomerByCode($code, $customerId);
            if ($state === null) {
                throw new BookingException('BOOKING_NOT_FOUND', 'Không tìm thấy booking thuộc tài khoản hiện tại.');
            }
            $bookingId = (int) $state['id'];
            $fingerprint = $request->fingerprint($bookingId, $customerId);
            $existing = $this->audit->findByRequestId($requestId);
            if ($existing !== null) {
                if ((int) ($existing['actorId'] ?? 0) !== $customerId
                    || ($existing['action'] ?? null) !== 'BOOKING_GUEST_DETAILS_UPDATED'
                    || ($existing['entityId'] ?? null) !== (string) $bookingId
                    || ($existing['after']['requestFingerprint'] ?? null) !== $fingerprint) {
                    throw new BookingException('IDEMPOTENCY_CONFLICT', 'RequestID đã được dùng cho thao tác khác.');
                }
                return $bookingId;
            }
            if (($state['status'] ?? null) !== 'CONFIRMED') {
                throw new BookingException('BOOKING_NOT_MODIFIABLE', 'Chỉ booking đã xác nhận mới được sửa thông tin khách.');
            }
            $cutoff = new DateTimeImmutable(
                (string) $state['checkIn'] . ' ' . (string) $state['checkInTime'],
                new DateTimeZone((string) $state['timezone']),
            );
            if ($this->clock->now() >= $cutoff) {
                throw new BookingException('BOOKING_CUTOFF_PASSED', 'Đã đến hoặc qua thời điểm nhận phòng của cơ sở.');
            }

            $items = $this->bookings->lockItemParties($bookingId);
            $parties = [];
            foreach ($items as $item) {
                $party = $item['party'];
                $parties[(int) $item['id']] = $party;
            }
            if ($request->guestNames !== []) {
                if (array_keys($request->guestNames) !== array_keys($parties)) {
                    throw new BookingException('GUEST_DETAILS_INVALID', 'Phải gửi tên khách cho đúng toàn bộ hạng mục phòng.');
                }
                foreach ($request->guestNames as $itemId => $names) {
                    $party = $parties[$itemId];
                    if (count($names) !== $this->guestCount($party)) {
                        throw new BookingException('GUEST_DETAILS_INVALID', 'Số tên khách không khớp snapshot số khách của phòng.');
                    }
                    $parties[$itemId] = $this->withGuestNames($party, $names);
                }
            }

            $before = $this->snapshot($state, $items);
            $this->bookings->updateContact(
                $bookingId,
                $request->bookerName,
                $request->bookerEmail,
                $request->bookerPhone,
                $request->specialRequest,
            );
            foreach ($parties as $itemId => $party) {
                if (isset($request->guestNames[$itemId])) {
                    $this->bookings->updateItemParty($itemId, $party);
                }
            }
            $afterItems = array_map(
                static fn (int $itemId, array $party): array => ['id' => $itemId, 'party' => $party],
                array_keys($parties),
                array_values($parties),
            );
            $after = $this->snapshot([
                ...$state,
                'bookerName' => $request->bookerName,
                'bookerEmail' => $request->bookerEmail,
                'bookerPhone' => $request->bookerPhone,
                'specialRequest' => $request->specialRequest,
            ], $afterItems);
            $after['requestFingerprint'] = $fingerprint;
            $this->audit->record(
                $customerId,
                'BOOKING_GUEST_DETAILS_UPDATED',
                'DatCho',
                (string) $bookingId,
                $requestId,
                $before,
                $after,
                $clientIp,
            );
            return $bookingId;
        });

        $booking = $this->bookings->findById($bookingId);
        if (!$booking instanceof Booking) {
            throw new RuntimeException('Không thể đọc booking sau khi sửa thông tin khách.');
        }
        return $booking;
    }

    /** @param array<string, mixed> $state @param list<array<string, mixed>> $items */
    private function snapshot(array $state, array $items): array
    {
        $guestNames = [];
        foreach ($items as $item) {
            $party = $item['party'];
            $guestNames[(string) $item['id']] = array_is_list($party)
                ? array_map(static fn (array $guest): string => (string) ($guest['name'] ?? ''), $party)
                : ($party['guestNames'] ?? []);
        }
        return [
            'bookerName' => (string) $state['bookerName'],
            'bookerEmail' => (string) $state['bookerEmail'],
            'bookerPhone' => (string) $state['bookerPhone'],
            'specialRequest' => $state['specialRequest'] === null ? null : (string) $state['specialRequest'],
            'guestNames' => $guestNames,
        ];
    }

    /** @param array<mixed> $party */
    private function guestCount(array $party): int
    {
        if (array_is_list($party)) {
            foreach ($party as $guest) {
                if (!is_array($guest)) {
                    throw new BookingException('GUEST_DETAILS_INVALID', 'Snapshot danh sách khách không hợp lệ.');
                }
            }
            return count($party);
        }

        $adults = $party['adults'] ?? null;
        $childAges = $party['childAges'] ?? null;
        if (!is_int($adults) || $adults < 1 || !is_array($childAges) || !array_is_list($childAges)) {
            throw new BookingException('GUEST_DETAILS_INVALID', 'Snapshot số khách của phòng không hợp lệ.');
        }
        return $adults + count($childAges);
    }

    /** @param array<mixed> $party @param list<string> $names @return array<mixed> */
    private function withGuestNames(array $party, array $names): array
    {
        if (array_is_list($party)) {
            foreach ($party as $index => $guest) {
                if (!is_array($guest)) {
                    throw new BookingException('GUEST_DETAILS_INVALID', 'Snapshot danh sách khách không hợp lệ.');
                }
                $party[$index] = [...$guest, 'name' => $names[$index]];
            }
            return $party;
        }

        $party['guestNames'] = $names;
        return $party;
    }

    private function assertRequestId(string $requestId): void
    {
        if (preg_match('/^[A-Za-z0-9._-]{8,80}$/', $requestId) !== 1) {
            throw new BookingException('REQUEST_ID_INVALID', 'RequestID của thao tác sửa booking không hợp lệ.');
        }
    }
}

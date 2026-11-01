<?php

declare(strict_types=1);

namespace App\Modules\Bookings\Application;

use App\Modules\Bookings\Domain\Booking;
use App\Modules\Bookings\Domain\BookingPreview;
use App\Modules\Bookings\Infrastructure\BookingCodeGenerator;
use App\Modules\Bookings\Infrastructure\BookingRepository;
use App\Modules\Bookings\Infrastructure\PaymentRepository;
use App\Modules\Bookings\Infrastructure\PreviewRepository;
use App\Modules\Inventory\Application\BookingInventoryItem;
use App\Modules\Inventory\Application\InventoryReservationService;
use App\Modules\Search\Application\PropertyAvailabilityService;
use App\Shared\Audit\AuditTrail;
use App\Shared\Clock\ClockInterface;
use App\Shared\Contract\InventoryDemand;
use App\Shared\Database\TransactionManager;
use App\Shared\Json\CanonicalJson;
use App\Shared\Security\Hasher;
use DomainException;
use PDOException;
use RuntimeException;

final readonly class BookingService
{
    public function __construct(
        private PreviewRepository $previews,
        private BookingRepository $bookings,
        private PaymentRepository $payments,
        private PropertyAvailabilityService $availability,
        private PreviewSnapshotFactory $snapshots,
        private InventoryReservationService $inventory,
        private TransactionManager $transactions,
        private AuditTrail $audit,
        private BookingCodeGenerator $codes,
        private Hasher $hasher,
        private ClockInterface $clock,
    ) {
    }

    public function createPayAtProperty(
        string $previewToken,
        int $customerId,
        BookingRequest $request,
        string $requestId,
        string $clientIp,
    ): CreatedBooking {
        return $this->createInternal($previewToken, $customerId, $request, $requestId, $clientIp, 'PAY_AT_PROPERTY');
    }

    public function createFromPreview(
        string $previewToken,
        int $customerId,
        BookingRequest $request,
        string $requestId,
        string $clientIp,
    ): CreatedBooking {
        return $this->createInternal($previewToken, $customerId, $request, $requestId, $clientIp, null);
    }

    private function createInternal(
        string $previewToken,
        int $customerId,
        BookingRequest $request,
        string $requestId,
        string $clientIp,
        ?string $expectedTiming,
    ): CreatedBooking {
        if (preg_match('/^[a-f0-9]{64,}$/', $previewToken) !== 1 || $customerId < 1) {
            throw $this->previewNotUsable();
        }

        try {
            return $this->transactions->run(function () use (
                $previewToken,
                $customerId,
                $request,
                $requestId,
                $clientIp,
                $expectedTiming,
            ): CreatedBooking {
                $preview = $this->previews->lockByTokenHash($this->hasher->hashToken($previewToken));
                if ($preview === null || $preview->customerId !== $customerId) {
                    throw $this->previewNotUsable();
                }

                // Dữ liệu lõi của booking đã tạo là bất biến. Không khóa một preview khác
                // qua JOIN ở đây để giữ thứ tự khóa preview → inventory và tránh lock inversion.
                $existing = $this->bookings->findIdempotencyRecord($request->clientRequestId);
                if ($existing !== null) {
                    return $this->replayOrConflict($existing, $preview, $customerId, $request);
                }

                $now = $this->clock->now();
                if ($preview->status !== BookingPreview::STATUS_VALID || $preview->isExpiredAt($now)) {
                    throw $this->previewNotUsable();
                }
                $timing = (string) ($preview->allocationSnapshot['paymentTiming'] ?? '');
                if (!in_array($timing, ['PAY_AT_PROPERTY', 'PAY_ONLINE'], true)
                    || ($expectedTiming !== null && $timing !== $expectedTiming)
                    || ($timing === 'PAY_AT_PROPERTY' && (!$preview->payNowTotal->isZero() || !$preview->payAtPropertyTotal->equals($preview->grandTotal)))
                    || ($timing === 'PAY_ONLINE' && (!$preview->payAtPropertyTotal->isZero() || !$preview->payNowTotal->equals($preview->grandTotal)))) {
                    throw new BookingException(
                        'PAYMENT_TIMING_UNSUPPORTED',
                        'Thời điểm hoặc phân rã thanh toán của preview không hợp lệ.',
                    );
                }

                $snapshot = $this->revalidate($preview, $timing);
                $snapshotData = json_decode(CanonicalJson::encode($snapshot->data), true, 64, JSON_THROW_ON_ERROR);
                $rooms = is_array($snapshotData) ? ($snapshotData['rooms'] ?? null) : null;
                if (!is_array($rooms) || !array_is_list($rooms) || $rooms === []) {
                    throw new BookingException('PREVIEW_STALE', 'Preview không còn đủ dữ liệu để tạo booking.');
                }

                $demands = $this->inventoryDemands($rooms);
                $this->inventory->lockAndAssertAvailable($demands);

                try {
                    $expiresAt = $timing === 'PAY_ONLINE' ? $now->modify('+10 minutes') : null;
                    $bookingId = $this->bookings->create([
                        'code' => $this->codes->generate(),
                        'clientRequestId' => $request->clientRequestId,
                        'previewId' => $preview->id,
                        'customerId' => $customerId,
                        'propertyId' => $preview->propertyId,
                        'checkIn' => $preview->checkIn,
                        'checkOut' => $preview->checkOut,
                        'bookerName' => $request->bookerName,
                        'bookerEmail' => $request->bookerEmail,
                        'bookerPhone' => $request->bookerPhone,
                        'specialRequest' => $request->specialRequest,
                        'grandTotal' => $preview->grandTotal->decimal(),
                        'paymentTiming' => $timing,
                        'status' => $timing === 'PAY_ONLINE' ? 'PENDING_PAYMENT' : 'CONFIRMED',
                        'paymentExpiresAt' => $expiresAt === null ? null : $this->databaseTime($expiresAt),
                        'confirmedAt' => $timing === 'PAY_ONLINE' ? null : $this->databaseTime($now),
                        'createdAt' => $this->databaseTime($now),
                        'updatedAt' => $this->databaseTime($now),
                    ]);
                } catch (PDOException $exception) {
                    if ((string) ($exception->errorInfo[0] ?? $exception->getCode()) === '23000') {
                        throw new BookingException(
                            'IDEMPOTENCY_CONFLICT',
                            'Yêu cầu đặt phòng xung đột với một thao tác đã được xử lý.',
                            $exception,
                        );
                    }
                    throw $exception;
                }

                $inventoryItems = [];
                foreach ($rooms as $room) {
                    if (!is_array($room)) {
                        throw new BookingException('PREVIEW_STALE', 'Hạng mục preview không hợp lệ.');
                    }
                    $bookingItemId = $this->bookings->createItem($bookingId, $room);
                    $nightPrices = $room['nightPrices'] ?? null;
                    if (!is_array($nightPrices) || !array_is_list($nightPrices) || $nightPrices === []) {
                        throw new BookingException('PREVIEW_STALE', 'Hạng mục preview thiếu giá theo đêm.');
                    }
                    $stayDates = [];
                    foreach ($nightPrices as $night) {
                        if (!is_array($night)) {
                            throw new BookingException('PREVIEW_STALE', 'Snapshot giá đêm không hợp lệ.');
                        }
                        $this->bookings->createNight($bookingItemId, $night);
                        $stayDates[] = (string) ($night['stayDate'] ?? '');
                    }
                    $inventoryItems[] = new BookingInventoryItem(
                        $bookingItemId,
                        (int) ($room['roomTypeId'] ?? 0),
                        $stayDates,
                    );
                }

                $this->inventory->holdForBooking($bookingId, $inventoryItems, $expiresAt);
                if ($timing === 'PAY_ONLINE') {
                    $this->payments->createOnline($bookingId, $preview->grandTotal, $request->clientRequestId);
                } else {
                    $this->bookings->createPayAtPropertyPayment($bookingId, $preview->grandTotal);
                }
                $this->previews->markUsed($preview->id, $now);
                $this->audit->record(
                    $customerId,
                    'BOOKING_CREATED',
                    'DatCho',
                    (string) $bookingId,
                    $requestId,
                    null,
                    [
                        'status' => $timing === 'PAY_ONLINE' ? 'PENDING_PAYMENT' : 'CONFIRMED',
                        'paymentTiming' => $timing,
                        'previewId' => $preview->id,
                        'roomCount' => count($inventoryItems),
                        'grandTotal' => $preview->grandTotal->decimal(),
                    ],
                    $clientIp,
                );

                $booking = $this->bookings->findById($bookingId);
                if (!$booking instanceof Booking) {
                    throw new RuntimeException('Không thể đọc lại booking vừa tạo.');
                }
                return new CreatedBooking($booking, false);
            });
        } catch (DomainException $exception) {
            throw new BookingException(
                'INVENTORY_UNAVAILABLE',
                'Không còn đủ phòng cho toàn bộ kỳ lưu trú; không có booking nào được tạo.',
                $exception,
            );
        }
    }

    public function findConfirmation(string $code, int $customerId): Booking
    {
        $booking = $this->bookings->findForCustomerByCode(trim($code), $customerId);
        if (!$booking instanceof Booking) {
            throw new BookingException('BOOKING_NOT_FOUND', 'Không tìm thấy booking thuộc tài khoản hiện tại.');
        }
        return $booking;
    }

    /** @param array<string, mixed> $existing */
    private function replayOrConflict(
        array $existing,
        BookingPreview $preview,
        int $customerId,
        BookingRequest $request,
    ): CreatedBooking {
        $existingCustomerId = (int) ($existing['customerId'] ?? 0);
        $existingPreviewId = (int) ($existing['previewId'] ?? 0);
        $existingPreviewFingerprint = (string) ($existing['previewFingerprint'] ?? '');
        $incomingFingerprint = $request->payloadFingerprint($customerId, $preview->id, $preview->fingerprint);
        $storedRequest = BookingRequest::fromInput(
            (string) ($existing['clientRequestId'] ?? ''),
            (string) ($existing['bookerName'] ?? ''),
            (string) ($existing['bookerEmail'] ?? ''),
            (string) ($existing['bookerPhone'] ?? ''),
            $existing['specialRequest'] === null ? null : (string) $existing['specialRequest'],
            true,
        );
        $storedFingerprint = $storedRequest->payloadFingerprint(
            $existingCustomerId,
            $existingPreviewId,
            $existingPreviewFingerprint,
        );
        if ($existingCustomerId !== $customerId
            || $existingPreviewId !== $preview->id
            || !hash_equals($storedFingerprint, $incomingFingerprint)) {
            throw new BookingException(
                'IDEMPOTENCY_CONFLICT',
                'Yêu cầu đặt phòng xung đột với một thao tác đã được xử lý.',
            );
        }
        $booking = $this->bookings->findById((int) ($existing['bookingId'] ?? 0));
        if (!$booking instanceof Booking) {
            throw new RuntimeException('Không thể đọc booking idempotent đã tồn tại.');
        }
        return new CreatedBooking($booking, true);
    }

    private function revalidate(BookingPreview $preview, string $timing): PreviewSnapshot
    {
        $data = $preview->allocationSnapshot;
        $criteria = $this->snapshots->criteriaFromSnapshot($data);
        $selected = $this->snapshots->selectedFromSnapshot($data);
        $slug = (string) ($data['property']['slug'] ?? '');
        $selectionFingerprint = (string) ($data['selectionFingerprint'] ?? '');
        $checked = $this->availability->check(
            $slug,
            $criteria,
            $timing,
            $selectionFingerprint,
            $selected,
        );
        if ($checked === null || $checked->allocation === null || $checked->selectionChanged) {
            throw new BookingException('PREVIEW_STALE', 'Giá, sản phẩm hoặc tồn đã thay đổi; vui lòng tạo preview mới.');
        }
        $current = $this->snapshots->create(
            $checked->property,
            $criteria,
            $checked->allocation->rooms,
            $timing,
            $checked->allocation->selectionFingerprint,
        );
        if (!hash_equals($preview->fingerprint, $current->fingerprint)) {
            throw new BookingException('PREVIEW_STALE', 'Giá, sản phẩm hoặc tồn đã thay đổi; vui lòng tạo preview mới.');
        }
        return $current;
    }

    /** @param list<array<string, mixed>> $rooms @return list<InventoryDemand> */
    private function inventoryDemands(array $rooms): array
    {
        $demands = [];
        foreach ($rooms as $room) {
            if (!is_array($room) || !is_array($room['nightPrices'] ?? null)) {
                throw new BookingException('PREVIEW_STALE', 'Preview thiếu dữ liệu tồn theo đêm.');
            }
            foreach ($room['nightPrices'] as $night) {
                if (!is_array($night)) {
                    throw new BookingException('PREVIEW_STALE', 'Preview có dữ liệu đêm không hợp lệ.');
                }
                $demands[] = new InventoryDemand(
                    (int) ($room['roomTypeId'] ?? 0),
                    (string) ($night['stayDate'] ?? ''),
                    1,
                );
            }
        }
        return $demands;
    }

    private function previewNotUsable(): BookingException
    {
        return new BookingException(
            'PREVIEW_NOT_USABLE',
            'Preview không tồn tại, đã hết hạn, đã dùng hoặc không thuộc tài khoản hiện tại.',
        );
    }

    private function databaseTime(\DateTimeImmutable $value): string
    {
        return $value->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s.u');
    }
}

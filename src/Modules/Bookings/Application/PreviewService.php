<?php

declare(strict_types=1);

namespace App\Modules\Bookings\Application;

use App\Modules\Bookings\Domain\BookingPreview;
use App\Modules\Bookings\Domain\PreviewDraft;
use App\Modules\Bookings\Infrastructure\PreviewRepository;
use App\Modules\Search\Application\PropertyAvailabilityService;
use App\Shared\Clock\ClockInterface;
use App\Shared\Contract\AllocationResult;
use App\Shared\Contract\SearchCriteria;
use App\Shared\Money\Money;
use App\Shared\Security\Hasher;
use App\Shared\Security\TokenGenerator;
use InvalidArgumentException;

final readonly class PreviewService
{
    public function __construct(
        private PropertyAvailabilityService $availability,
        private PreviewSnapshotFactory $snapshots,
        private PreviewRepository $repository,
        private TokenGenerator $tokens,
        private Hasher $hasher,
        private ClockInterface $clock,
    ) {
    }

    /** @param array<int, array{roomTypeId: int, productId: int}> $selected */
    public function create(
        int $customerId,
        string $propertySlug,
        SearchCriteria $criteria,
        string $paymentTiming,
        string $previousFingerprint,
        array $selected,
    ): CreatedPreview {
        if ($customerId < 1 || trim($propertySlug) === '') {
            throw new InvalidArgumentException('Khách hàng hoặc cơ sở tạo preview không hợp lệ.');
        }
        if (preg_match('/^[a-f0-9]{64}$/', $previousFingerprint) !== 1 || $selected === []) {
            throw new InvalidArgumentException('Preview cần allocation và fingerprint đã chọn.');
        }

        $checked = $this->availability->check(
            $propertySlug,
            $criteria,
            $paymentTiming,
            $previousFingerprint,
            $selected,
        );
        if ($checked === null || $checked->allocation === null) {
            throw new PreviewException('PREVIEW_UNAVAILABLE', 'Lựa chọn không còn đủ giá hoặc tồn để tạo preview.');
        }
        $allocation = $checked->allocation;
        if ($allocation->paymentTiming !== $paymentTiming) {
            throw new PreviewException('MIXED_PAYMENT_TIMING', 'Allocation không cùng thời điểm thanh toán đã chọn.');
        }
        if ($this->allocationMapping($allocation) !== $selected) {
            throw new PreviewException('SELECTION_CHANGED', 'Phân bổ phòng đã thay đổi; vui lòng xác nhận lại ở trang chi tiết.');
        }

        $warnings = [];
        if ($allocation->selectionFingerprint !== $previousFingerprint) {
            $warnings[] = 'Giá hoặc phiên bản dữ liệu đã thay đổi từ màn hình trước; đây là báo giá hiện tại.';
        }
        $snapshot = $this->snapshots->create(
            $checked->property,
            $criteria,
            $allocation->rooms,
            $paymentTiming,
            $allocation->selectionFingerprint,
            $warnings,
        );
        [$payNow, $payAtProperty] = $this->paymentTotals($allocation);
        $now = $this->clock->now();
        $token = $this->tokens->generate();
        $preview = $this->repository->create(new PreviewDraft(
            $this->hasher->hashToken($token),
            $customerId,
            $checked->property->propertyId,
            $criteria->stayPeriod->checkIn,
            $criteria->stayPeriod->checkOut,
            $allocation->grandTotal,
            $payNow,
            $payAtProperty,
            $snapshot->data,
            $snapshot->fingerprint,
            $now->modify('+15 minutes'),
            $now,
        ));

        return new CreatedPreview($preview, $token);
    }

    public function loadForCheckout(string $token, int $customerId): BookingPreview
    {
        if (preg_match('/^[a-f0-9]{64,}$/', $token) !== 1 || $customerId < 1) {
            throw $this->notUsable();
        }
        $preview = $this->repository->findByTokenHash($this->hasher->hashToken($token));
        if ($preview === null
            || $preview->customerId !== $customerId
            || $preview->status !== BookingPreview::STATUS_VALID
            || $preview->isExpiredAt($this->clock->now())) {
            throw $this->notUsable();
        }

        $snapshot = $preview->allocationSnapshot;
        $criteria = $this->snapshots->criteriaFromSnapshot($snapshot);
        $selected = $this->snapshots->selectedFromSnapshot($snapshot);
        $slug = (string) ($snapshot['property']['slug'] ?? '');
        $timing = (string) ($snapshot['paymentTiming'] ?? '');
        $selectionFingerprint = (string) ($snapshot['selectionFingerprint'] ?? '');
        $checked = $this->availability->check(
            $slug,
            $criteria,
            $timing,
            $selectionFingerprint,
            $selected,
        );
        if ($checked === null || $checked->allocation === null || $checked->selectionChanged) {
            $this->repository->invalidate($preview->id);
            throw new PreviewException('PREVIEW_STALE', 'Giá, sản phẩm hoặc tồn đã thay đổi; vui lòng tạo preview mới.');
        }
        $current = $this->snapshots->create(
            $checked->property,
            $criteria,
            $checked->allocation->rooms,
            $timing,
            $checked->allocation->selectionFingerprint,
        );
        if ($current->fingerprint !== $preview->fingerprint) {
            $this->repository->invalidate($preview->id);
            throw new PreviewException('PREVIEW_STALE', 'Giá, sản phẩm hoặc tồn đã thay đổi; vui lòng tạo preview mới.');
        }

        return $preview;
    }

    /** @return array{Money, Money} */
    private function paymentTotals(AllocationResult $allocation): array
    {
        $payNow = Money::vnd(0);
        $payAtProperty = Money::vnd(0);
        foreach ($allocation->rooms as $room) {
            $payNow = $payNow->add($room->priceBreakdown->payNowTotal);
            $payAtProperty = $payAtProperty->add($room->priceBreakdown->payAtPropertyTotal);
        }
        if (!$payNow->add($payAtProperty)->equals($allocation->grandTotal)) {
            throw new PreviewException('MIXED_PAYMENT_TIMING', 'Phân rã thanh toán không khớp tổng preview.');
        }
        return [$payNow, $payAtProperty];
    }

    /** @return array<int, array{roomTypeId: int, productId: int}> */
    private function allocationMapping(AllocationResult $allocation): array
    {
        $mapping = [];
        foreach ($allocation->rooms as $room) {
            $mapping[$room->roomOrder] = [
                'roomTypeId' => $room->roomTypeId,
                'productId' => $room->productId,
            ];
        }
        ksort($mapping, SORT_NUMERIC);
        return $mapping;
    }

    private function notUsable(): PreviewException
    {
        return new PreviewException(
            'PREVIEW_NOT_USABLE',
            'Preview không tồn tại, đã hết hạn, đã dùng hoặc không thuộc tài khoản hiện tại.',
        );
    }
}

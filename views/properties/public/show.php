<?php

declare(strict_types=1);

/** @var array<string, mixed> $availability */
/** @var string $baseUrl */
/** @var array<string, mixed> $query */
/** @var string $csrfToken */
/** @var string|null $currentUserRole */
$currentUserRole = $currentUserRole ?? null;
$property = $availability['property'];
$allocation = $availability['allocation'];
$images = $property['images'];
$coverPath = $images[0]['path'] ?? '/assets/images/accommodation/shared/accommodation-cover.png';
$asset = static fn (string $path): string => $baseUrl . '/' . ltrim($path, '/');
$productMap = [];
foreach ($property['roomProducts'] as $product) { $productMap[$product['productId']] = $product; }
$typeLabels = ['HOTEL' => 'Khách sạn', 'RESORT' => 'Khu nghỉ dưỡng', 'APARTMENT' => 'Căn hộ', 'VILLA' => 'Biệt thự', 'HOSTEL' => 'Hostel', 'GUEST_HOUSE' => 'Nhà khách'];
$searchQuery = $query;
unset($searchQuery['paymentTiming'], $searchQuery['selectionFingerprint'], $searchQuery['selected']);
$searchUrl = $baseUrl . '/search?' . htmlspecialchars(http_build_query($searchQuery), ENT_QUOTES, 'UTF-8');
$criteriaJson = htmlspecialchars(json_encode($availability['criteria'], JSON_THROW_ON_ERROR), ENT_QUOTES, 'UTF-8');
$selectedValue = $allocation === null ? '' : implode(',', array_map(
    static fn (array $room): string => $room['roomOrder'] . ':' . $room['roomTypeId'] . ':' . $room['productId'],
    $allocation['rooms'],
));
?>
<nav class="public-breadcrumb"><a href="<?= $baseUrl ?>/">Trang chủ</a><span>›</span><a href="<?= $searchUrl ?>">Kết quả tìm kiếm</a><span>›</span><span><?= $property['name'] ?></span></nav>

<header class="stay-detail-header">
    <div><div class="stay-detail-header__meta"><span><?= $typeLabels[$property['type']] ?? $property['type'] ?></span><?php if ($property['starRating'] !== null): ?><b><?= str_repeat('★', (int) $property['starRating']) ?></b><?php endif; ?></div><h1><?= $property['name'] ?></h1><p><svg class="app-icon"><use href="#icon-pin"/></svg><?= $property['address'] ?></p></div>
    <div class="stay-detail-score"><strong><?= $property['reviewScore'] ?></strong><span>/10<small><?= number_format($property['reviewCount']) ?> đánh giá</small></span></div>
</header>

<section class="stay-gallery">
    <img class="stay-gallery__cover" src="<?= $asset((string) $coverPath) ?>" alt="Ảnh bìa <?= $property['name'] ?>">
    <?php foreach (array_slice($images, 1, 4) as $image): ?><img src="<?= $asset((string) $image['path']) ?>" alt="<?= $image['alt'] ?? $property['name'] ?>"><?php endforeach; ?>
</section>

<div class="stay-detail-layout">
    <main>
        <section class="stay-overview"><h2>Về chỗ nghỉ này</h2><p><?= $property['description'] ?: 'Thông tin mô tả đang được cơ sở cập nhật.' ?></p><div class="facility-chips"><?php foreach ($property['facilities'] as $facility): ?><span>✓ <?= $facility['name'] ?></span><?php endforeach; ?></div></section>
        <section class="availability-panel" data-availability-root data-endpoint="<?= $baseUrl ?>/properties/<?= $property['slug'] ?>/availability" data-login-url="<?= $baseUrl ?>/auth/login">
            <div class="availability-panel__heading"><div><p class="eyebrow eyebrow--blue">Giá và phòng hiện tại</p><h2>Chọn cách thanh toán</h2></div><div class="timing-toggle" role="group" aria-label="Cách thanh toán"><?php foreach (['PAY_AT_PROPERTY' => 'Trả tại cơ sở', 'PAY_ONLINE' => 'Trả trực tuyến'] as $timing => $label): ?><?php $summary = $availability['timingSummary'][$timing]; ?><button type="button" class="<?= $availability['paymentTiming'] === $timing ? 'is-active' : '' ?>" data-payment-timing="<?= $timing ?>" data-available="<?= $summary['available'] ? 'true' : 'false' ?>" <?= !$summary['available'] ? 'disabled' : '' ?>><?= $label ?><?php if ($summary['minPrice'] !== null): ?><small>từ <?= number_format((float) $summary['minPrice'], 0, ',', '.') ?> ₫</small><?php endif; ?></button><?php endforeach; ?></div></div>
            <div class="availability-status" data-availability-status aria-live="polite"><?php if ($availability['selectionChanged']): ?><div class="selection-changed"><strong>Lựa chọn đã được cập nhật</strong><span><?= $availability['changeReason'] ?></span></div><?php endif; ?></div>
            <div class="allocation-list" data-allocation-list>
                <?php if ($allocation === null): ?><div class="search-empty"><h3>Timing này hiện không còn tổ hợp phù hợp</h3><p>Hãy thử nhánh thanh toán còn lại hoặc đổi ngày.</p></div><?php else: ?>
                    <?php foreach ($allocation['rooms'] as $room): ?><?php $product = $productMap[$room['productId']] ?? null; ?>
                        <article class="allocation-card"><div class="allocation-card__media"><img src="<?= $asset((string) ($product['images'][0]['path'] ?? '/assets/images/accommodation/shared/room-placeholder.png')) ?>" alt="<?= $product['roomTypeName'] ?? 'Phòng lưu trú' ?>"></div><div class="allocation-card__info"><span>Phòng <?= $room['roomOrder'] ?></span><h3><?= $product['roomTypeName'] ?? 'Phòng phù hợp' ?></h3><p><?= $product['maximumAdults'] ?? $room['party']['adults'] ?> người lớn tối đa · <?= $product['areaSquareMeters'] ?? '—' ?> m²</p><div><?php foreach (array_slice($product['facilities'] ?? [], 0, 3) as $facility): ?><small><?= $facility['name'] ?></small><?php endforeach; ?></div></div><div class="allocation-card__product"><strong><?= $product['productName'] ?? 'Lựa chọn phòng' ?></strong><span><?= ($product['mealType'] ?? '') === 'BREAKFAST_INCLUDED' ? 'Có bữa sáng' : 'Phòng không kèm bữa' ?></span><span><?= ($product['cancellationPolicy']['policyType'] ?? '') === 'FLEXIBLE' ? 'Hủy theo chính sách linh hoạt' : 'Không hoàn tiền' ?></span></div><div class="allocation-card__price"><span><?= count($room['priceBreakdown']['nights']) ?> đêm</span><strong><?= number_format((float) $room['priceBreakdown']['grandTotal']['amount'], 0, ',', '.') ?> ₫</strong><small>Đã gồm thuế và phí</small></div></article>
                    <?php endforeach; ?>
                    <div class="allocation-total"><div><span>Tổng cho <?= count($allocation['rooms']) ?> phòng</span><small>Giá sẽ được kiểm tra lại trước khi xác nhận</small></div><strong><?= number_format((float) $allocation['grandTotal']['amount'], 0, ',', '.') ?> ₫</strong><?php if ($currentUserRole === 'CUSTOMER'): ?><button class="button button--primary" type="submit" form="preview-create-form">Tiếp tục xác nhận</button><?php elseif ($currentUserRole === null): ?><a class="button button--primary" href="<?= $baseUrl ?>/auth/login">Đăng nhập để tiếp tục</a><?php else: ?><button class="button button--primary" type="button" disabled>Chỉ dành cho khách hàng</button><?php endif; ?></div>
                <?php endif; ?>
            </div>
            <?php if ($currentUserRole === 'CUSTOMER' && $allocation !== null): ?>
                <form id="preview-create-form" data-preview-form method="post" action="<?= $baseUrl ?>/previews" hidden>
                    <input type="hidden" name="_token" value="<?= $csrfToken ?>">
                    <input type="hidden" name="propertySlug" value="<?= $property['slug'] ?>">
                    <input type="hidden" name="criteria" value="<?= $criteriaJson ?>">
                    <input type="hidden" name="paymentTiming" value="<?= $availability['paymentTiming'] ?>">
                    <input type="hidden" name="selectionFingerprint" value="<?= $allocation['selectionFingerprint'] ?>">
                    <input type="hidden" name="selected" value="<?= $selectedValue ?>">
                </form>
            <?php endif; ?>
        </section>
    </main>
    <aside class="stay-detail-aside"><div class="detail-fact"><svg class="app-icon"><use href="#icon-clock"/></svg><div><span>Nhận / trả phòng</span><strong><?= substr($property['checkInTime'], 0, 5) ?> · <?= substr($property['checkOutTime'], 0, 5) ?></strong></div></div><div class="detail-fact"><svg class="app-icon"><use href="#icon-check-circle"/></svg><div><span>Tình trạng</span><strong>Đã xác minh trên hệ thống</strong></div></div><?php if ($property['mapUrl'] !== null): ?><a class="button button--secondary button--block" href="<?= $property['mapUrl'] ?>" target="_blank" rel="noopener">Xem trên bản đồ</a><?php endif; ?><div class="detail-note"><strong>Giá luôn được kiểm tra lại</strong><p>Trang chi tiết không giữ tồn. Nếu dữ liệu đổi, hệ thống sẽ báo rõ trước bước tiếp theo.</p></div></aside>
</div>

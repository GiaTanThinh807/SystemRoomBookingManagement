<?php

declare(strict_types=1);

/** @var array<string, mixed> $preview */
/** @var string $currentUserName */
/** @var string $currentUserEmail */
$allocation = $preview['allocation'];
$property = $allocation['property'];
$rooms = $allocation['rooms'];
$money = static fn (mixed $amount): string => number_format((float) $amount, 0, ',', '.') . ' ₫';
$mealLabels = ['ROOM_ONLY' => 'Không kèm bữa ăn', 'BREAKFAST_INCLUDED' => 'Bao gồm bữa sáng', 'HALF_BOARD' => 'Bao gồm hai bữa'];
$timingLabel = $allocation['paymentTiming'] === 'PAY_ONLINE' ? 'Thanh toán trực tuyến toàn bộ' : 'Thanh toán toàn bộ tại cơ sở';
$expiresAt = new DateTimeImmutable((string) $preview['expiresAt']);
$escape = static fn (mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
?>
<nav class="public-breadcrumb"><a href="<?= $baseUrl ?>/">Trang chủ</a><span>›</span><span>Xác nhận lựa chọn</span></nav>

<header class="checkout-header">
    <div><p class="eyebrow eyebrow--blue">Preview đặt phòng</p><h1>Kiểm tra lại trước khi xác nhận</h1><p>Giá và tình trạng phòng vừa được đọc lại từ hệ thống. Preview chưa giữ phòng.</p></div>
    <div class="preview-expiry"><span>Có hiệu lực đến</span><strong><?= $expiresAt->format('H:i, d/m/Y') ?> UTC</strong><small>Tối đa 15 phút từ lúc tạo</small></div>
</header>

<?php foreach ($allocation['warnings'] as $warning): ?><div class="selection-changed checkout-warning"><strong>Dữ liệu đã được cập nhật</strong><span><?= $escape($warning) ?></span></div><?php endforeach; ?>

<form method="post" action="<?= $baseUrl ?>/bookings" data-booking-form data-preview-fingerprint="<?= $escape($preview['fingerprint']) ?>">
    <input type="hidden" name="_token" value="<?= $escape($csrfToken) ?>">
    <input type="hidden" name="clientRequestId" value="">
<div class="checkout-layout">
    <div class="checkout-main">
        <section class="checkout-property-card">
            <div class="checkout-section-heading"><span>01</span><div><h2><?= $property['name'] ?></h2><p><?= $property['address'] ?></p></div></div>
            <div class="checkout-stay-facts"><div><span>Nhận phòng</span><strong><?= $preview['checkIn'] ?></strong><small>Từ <?= substr((string) $property['checkInTime'], 0, 5) ?></small></div><div><span>Trả phòng</span><strong><?= $preview['checkOut'] ?></strong><small>Trước <?= substr((string) $property['checkOutTime'], 0, 5) ?></small></div><div><span>Thời gian</span><strong><?= count($rooms[0]['nightPrices'] ?? []) ?> đêm</strong><small><?= count($rooms) ?> phòng</small></div></div>
        </section>

        <section class="checkout-rooms">
            <div class="checkout-section-heading"><span>02</span><div><h2>Phòng và nhóm khách</h2><p>Mỗi nhóm khách được giữ nguyên như lựa chọn đã xác nhận.</p></div></div>
            <?php foreach ($rooms as $room): ?>
                <?php $price = $room['priceBreakdown']; $policy = $room['cancellationSnapshot']; ?>
                <article class="checkout-room">
                    <header><div><span>Phòng <?= $room['roomOrder'] ?></span><h3><?= $room['roomTypeName'] ?></h3><p><?= $room['productName'] ?> · <?= $mealLabels[$room['mealType']] ?? $room['mealType'] ?></p></div><strong><?= $money($price['grandTotal']['amount']) ?></strong></header>
                    <div class="checkout-room__party"><b><?= $room['party']['adults'] ?> người lớn</b><span><?= count($room['party']['childAges']) ?> trẻ em<?php if ($room['party']['childAges'] !== []): ?> · tuổi <?= implode(', ', $room['party']['childAges']) ?><?php endif; ?></span></div>
                    <div class="night-price-table"><div class="night-price-table__head"><span>Đêm</span><span>Giá gốc</span><span>Giảm</span><span>Thuế & phí</span><span>Thành tiền</span></div><?php foreach ($room['nightPrices'] as $night): ?><div><span><?= $night['stayDate'] ?></span><span><?= $money($night['basePrice']) ?></span><span>-<?= $money($night['discount']) ?></span><span><?= $money((float) $night['taxAmount'] + (float) $night['serviceFeeAmount']) ?></span><strong><?= $money($night['total']) ?></strong></div><?php endforeach; ?></div>
                    <div class="checkout-policy"><div><span>Chính sách hủy</span><strong><?= $policy['name'] ?? 'Theo điều kiện sản phẩm' ?></strong><small><?= ($policy['policyType'] ?? '') === 'FLEXIBLE' ? 'Có thời gian hủy miễn phí theo chính sách' : 'Có thể không hoàn tiền khi hủy' ?></small></div><div><span>No-show</span><strong><?= $policy['noShowPenaltyRate'] ?? '—' ?>%</strong><small>Tỷ lệ phạt tối đa trên tổng hạng mục</small></div></div>
                </article>
            <?php endforeach; ?>
        </section>

        <section class="checkout-contact">
            <div class="checkout-section-heading"><span>03</span><div><h2>Thông tin người đặt</h2><p>Thông tin liên hệ này sẽ được lưu trong snapshot booking.</p></div></div>
            <div class="checkout-contact-grid"><label><span>Họ tên</span><input name="bookerName" value="<?= $escape($currentUserName) ?>" maxlength="150" required autocomplete="name"></label><label><span>Email</span><input type="email" name="bookerEmail" value="<?= $escape($currentUserEmail) ?>" maxlength="190" required autocomplete="email"></label><label><span>Số điện thoại</span><input name="bookerPhone" maxlength="20" required autocomplete="tel" placeholder="Ví dụ: 0901234567"></label><label class="checkout-contact-grid__wide"><span>Yêu cầu đặc biệt</span><textarea name="specialRequest" maxlength="1000" placeholder="Yêu cầu không phải cam kết phục vụ"></textarea></label></div>
        </section>
    </div>

    <aside class="checkout-summary">
        <span class="checkout-summary__badge"><?= $timingLabel ?></span>
        <h2>Tóm tắt giá</h2>
        <div><span>Giá trước giảm</span><strong><?= $money(array_sum(array_map(static fn (array $room): float => (float) $room['priceBreakdown']['subtotal']['amount'], $rooms))) ?></strong></div>
        <div><span>Khuyến mãi</span><strong class="checkout-discount">-<?= $money(array_sum(array_map(static fn (array $room): float => (float) $room['priceBreakdown']['discountTotal']['amount'], $rooms))) ?></strong></div>
        <div><span>Thuế/phí đã gồm</span><strong><?= $money(array_sum(array_map(static fn (array $room): float => (float) $room['priceBreakdown']['includedTaxFeeTotal']['amount'], $rooms))) ?></strong></div>
        <div class="checkout-summary__total"><span>Tổng cộng</span><strong><?= $money($preview['grandTotal']) ?></strong></div>
        <div><span>Trả ngay</span><strong><?= $money($preview['payNowTotal']) ?></strong></div>
        <div><span>Trả tại cơ sở</span><strong><?= $money($preview['payAtPropertyTotal']) ?></strong></div>
        <p><strong>Chưa giữ phòng.</strong> Giá và tồn sẽ được kiểm tra lần nữa khi tạo booking.</p>
        <label class="checkout-terms"><input type="checkbox" name="termsAccepted" value="1" required><span>Tôi đã xem giá, chính sách hủy/no-show và đồng ý <?= $allocation['paymentTiming'] === 'PAY_ONLINE' ? 'thanh toán trực tuyến toàn bộ' : 'thanh toán toàn bộ tại cơ sở' ?>.</span></label>
        <button class="button button--primary button--block" type="submit">Xác nhận đặt phòng</button>
        <small>Chỉ hiển thị mã đặt chỗ sau khi transaction hoàn tất.</small>
    </aside>
</div>
</form>

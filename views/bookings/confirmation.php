<?php

declare(strict_types=1);

/** @var array<string, mixed> $booking */
$escape = static fn (mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$money = static fn (mixed $amount): string => number_format((float) $amount, 0, ',', '.') . ' ₫';
$mealLabels = ['ROOM_ONLY' => 'Không kèm bữa ăn', 'BREAKFAST_INCLUDED' => 'Bao gồm bữa sáng', 'HALF_BOARD' => 'Bao gồm hai bữa'];
$confirmed = $booking['status'] === 'CONFIRMED';
$pending = $booking['status'] === 'PENDING_PAYMENT';
?>
<nav class="public-breadcrumb"><a href="<?= $baseUrl ?>/">Trang chủ</a><span>›</span><span>Trạng thái đặt phòng</span></nav>

<section class="booking-confirmation-hero">
    <span class="booking-confirmation-hero__icon" aria-hidden="true"><svg class="app-icon"><use href="#<?= $confirmed ? 'icon-check' : 'icon-clock' ?>"/></svg></span>
    <p class="eyebrow"><?= $confirmed ? 'Đã xác nhận' : ($pending ? 'Đang xử lý' : 'Thanh toán thất bại') ?></p>
    <h1><?= $confirmed ? 'Chỗ nghỉ của bạn đã được đặt' : ($pending ? 'Thanh toán đang được đối soát' : 'Booking chưa được xác nhận') ?></h1>
    <p><?php if ($confirmed): ?>Mã đặt chỗ <strong><?= $escape($booking['code']) ?></strong>. Hãy lưu mã này để tra cứu khi cần.<?php elseif ($pending): ?>Hệ thống chưa nhận được kết quả chắc chắn; không nên thanh toán lại bằng mã mới.<?php else: ?>Phòng đã được trả lại vì thanh toán không thành công.<?php endif; ?></p>
</section>

<div class="booking-confirmation-layout">
    <div class="booking-confirmation-main">
        <section class="confirmation-card">
            <div class="checkout-section-heading"><span>01</span><div><h2><?= $escape($booking['property']['name']) ?></h2><p><?= $escape($booking['property']['address']) ?></p></div></div>
            <div class="checkout-stay-facts"><div><span>Nhận phòng</span><strong><?= $escape($booking['checkIn']) ?></strong></div><div><span>Trả phòng</span><strong><?= $escape($booking['checkOut']) ?></strong></div><div><span>Trạng thái</span><strong><?= $escape($booking['status']) ?></strong><small><?= $booking['paymentTiming'] === 'PAY_ONLINE' ? 'Thanh toán trực tuyến' : 'Thanh toán tại cơ sở' ?></small></div></div>
        </section>

        <section class="confirmation-card">
            <div class="checkout-section-heading"><span>02</span><div><h2>Chi tiết phòng đã chụp</h2><p>Thông tin dưới đây là snapshot tại thời điểm đặt, không phụ thuộc catalog về sau.</p></div></div>
            <?php foreach ($booking['items'] as $item): ?>
                <article class="checkout-room">
                    <header><div><span>Phòng <?= $escape($item['roomOrder']) ?></span><h3><?= $escape($item['roomTypeName']) ?></h3><p><?= $escape($item['productName']) ?> · <?= $escape($mealLabels[$item['mealType']] ?? $item['mealType']) ?></p></div><strong><?= $money($item['grandTotal']) ?></strong></header>
                    <div class="checkout-room__party"><b><?= $escape($item['party']['adults'] ?? 0) ?> người lớn</b><span><?= count($item['party']['childAges'] ?? []) ?> trẻ em</span></div>
                    <div class="night-price-table"><div class="night-price-table__head"><span>Đêm</span><span>Giá gốc</span><span>Giảm</span><span>Thuế & phí</span><span>Thành tiền</span></div><?php foreach ($item['nights'] as $night): ?><div><span><?= $escape($night['stayDate']) ?></span><span><?= $money($night['basePrice']) ?></span><span>-<?= $money($night['discount']) ?></span><span><?= $money((float) $night['includedTaxFee'] + (float) $night['taxFeeAtProperty']) ?></span><strong><?= $money($night['total']) ?></strong></div><?php endforeach; ?></div>
                </article>
            <?php endforeach; ?>
        </section>

        <section class="confirmation-card">
            <div class="checkout-section-heading"><span>03</span><div><h2>Người đặt</h2><p>Thông tin liên hệ gắn với booking này.</p></div></div>
            <dl class="confirmation-contact"><div><dt>Họ tên</dt><dd><?= $escape($booking['booker']['name']) ?></dd></div><div><dt>Email</dt><dd><?= $escape($booking['booker']['email']) ?></dd></div><div><dt>Điện thoại</dt><dd><?= $escape($booking['booker']['phone']) ?></dd></div><?php if ($booking['booker']['specialRequest'] !== null): ?><div><dt>Yêu cầu đặc biệt</dt><dd><?= $escape($booking['booker']['specialRequest']) ?></dd></div><?php endif; ?></dl>
        </section>
    </div>

    <aside class="checkout-summary confirmation-summary">
        <span class="checkout-summary__badge"><?= $booking['paymentTiming'] === 'PAY_ONLINE' ? 'Thanh toán trực tuyến' : 'Thanh toán tại cơ sở' ?></span>
        <h2>Tổng đặt phòng</h2>
        <div class="checkout-summary__total"><span>Tổng cộng</span><strong><?= $money($booking['grandTotal']) ?></strong></div>
        <div><span>Trả trực tuyến</span><strong><?= $booking['paymentTiming'] === 'PAY_ONLINE' ? $money($booking['grandTotal']) : '0 ₫' ?></strong></div>
        <div><span>Trả tại cơ sở</span><strong><?= $booking['paymentTiming'] === 'PAY_AT_PROPERTY' ? $money($booking['grandTotal']) : '0 ₫' ?></strong></div>
        <p><?= $confirmed ? 'Booking chỉ được báo thành công sau khi trạng thái thanh toán và booking cùng hợp lệ.' : 'Đây chưa phải xác nhận đặt phòng thành công.' ?></p>
        <a class="button button--primary button--block" href="<?= $baseUrl ?>/">Tiếp tục tìm chỗ nghỉ</a>
    </aside>
</div>

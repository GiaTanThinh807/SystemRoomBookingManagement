<?php

declare(strict_types=1);

/** @var array<string, mixed> $booking */
$statusLabels = ['PENDING_PAYMENT' => 'Chờ thanh toán', 'CONFIRMED' => 'Đã xác nhận', 'PAYMENT_FAILED' => 'Thanh toán thất bại', 'CANCELLED' => 'Đã hủy', 'NO_SHOW' => 'Không đến', 'COMPLETED' => 'Hoàn tất'];
$mealLabels = ['ROOM_ONLY' => 'Không gồm bữa sáng', 'BREAKFAST_INCLUDED' => 'Gồm bữa sáng'];
$guestType = ['ADULT' => 'Người lớn', 'CHILD' => 'Trẻ em', 'GUEST' => 'Khách'];
$money = static fn (mixed $amount): string => number_format((float) $amount, 0, ',', '.') . ' ₫';
$date = static fn (mixed $value): string => date('d/m/Y', strtotime((string) $value));
$escape = static fn (mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
$pendingWaiver = null;
foreach ($waivers as $waiver) {
    if (($waiver['status'] ?? null) === 'PENDING') {
        $pendingWaiver = $waiver;
        break;
    }
}
?>
<nav class="resource-breadcrumb"><a href="<?= $baseUrl . $listPath ?>">Danh sách booking</a><svg class="app-icon"><use href="#icon-chevron"/></svg><span><?= $booking['code'] ?></span></nav>

<header class="booking-detail-hero">
    <div><span class="booking-eyebrow">Mã booking</span><h1><?= $booking['code'] ?></h1><p>Được tạo lúc <?= date('d/m/Y H:i', strtotime((string) $booking['createdAt'])) ?> · Dữ liệu bên dưới là snapshot đã chốt khi đặt.</p></div>
    <span class="booking-status booking-status--<?= strtolower((string) $booking['status']) ?>"><i></i><?= $statusLabels[$booking['status']] ?? $booking['status'] ?></span>
</header>

<div class="booking-detail-layout">
    <main class="booking-detail-main">
        <section class="booking-card">
            <div class="booking-card__heading"><span class="booking-card__icon"><svg class="app-icon"><use href="#icon-building"/></svg></span><div><h2><?= $booking['property']['name'] ?></h2><p><?= $booking['property']['address'] ?></p></div></div>
            <div class="booking-stay-grid"><div><span>Nhận phòng</span><strong><?= $date($booking['checkIn']) ?></strong></div><div><span>Trả phòng</span><strong><?= $date($booking['checkOut']) ?></strong></div><div><span>Thanh toán</span><strong><?= $booking['paymentTiming'] === 'PAY_ONLINE' ? 'Trực tuyến' : 'Tại cơ sở' ?></strong></div></div>
        </section>

        <section class="booking-card">
            <div class="booking-card__heading"><span class="booking-card__icon"><svg class="app-icon"><use href="#icon-bed"/></svg></span><div><h2>Phòng và điều kiện đã đặt</h2><p>Tên phòng, giá và chính sách lấy từ snapshot booking, không lấy lại catalog hiện tại.</p></div></div>
            <div class="booking-room-list">
            <?php foreach ($booking['items'] as $item): ?>
                <article class="booking-room-card">
                    <header><div><span>Phòng <?= (int) $item['roomOrder'] ?></span><h3><?= $item['roomTypeName'] ?></h3><p><?= $item['productName'] ?> · <?= $mealLabels[$item['mealType']] ?? $item['mealType'] ?></p></div><strong><?= $money($item['grandTotal']) ?></strong></header>
                    <div class="booking-snapshot-grid"><div><span>Hình thức trả</span><strong><?= ($item['conditions']['paymentTiming'] ?? $booking['paymentTiming']) === 'PAY_ONLINE' ? 'Trực tuyến' : 'Tại cơ sở' ?></strong></div><div><span>Phí hủy tại lúc đặt</span><strong><?= $money($item['cancellation']['absoluteFeeAtBooking'] ?? 0) ?></strong></div><div><span>Múi giờ chính sách</span><strong><?= $item['cancellation']['timezone'] ?? '—' ?></strong></div></div>
                    <div class="booking-night-list"><div class="booking-night-list__head"><span>Đêm lưu trú</span><span>Giá gốc</span><span>Giảm</span><span>Thành tiền</span></div><?php foreach ($item['nights'] as $night): ?><div><span><?= $date($night['stayDate']) ?></span><span><?= $money($night['basePrice']) ?></span><span>-<?= $money($night['discount']) ?></span><strong><?= $money($night['total']) ?></strong></div><?php endforeach; ?></div>
                </article>
            <?php endforeach; ?>
            </div>
        </section>

        <section class="booking-card">
            <div class="booking-card__heading"><span class="booking-card__icon"><svg class="app-icon"><use href="#icon-users"/></svg></span><div><h2>Khách lưu trú</h2><p>Số khách và độ tuổi là snapshot bất biến trong luồng sửa đơn giản.</p></div></div>
            <?php foreach ($booking['items'] as $item): ?><div class="booking-guests"><h3><?= $item['roomTypeName'] ?> · Phòng <?= (int) $item['roomOrder'] ?></h3><?php foreach ($item['guests'] as $index => $guest): ?><div><span class="booking-guest-index"><?= $index + 1 ?></span><span><strong><?= $guest['name'] !== '' ? $guest['name'] : 'Chưa nhập tên' ?></strong><small><?= $guestType[$guest['type']] ?? $guest['type'] ?><?= $guest['age'] !== null ? ' · ' . (int) $guest['age'] . ' tuổi' : '' ?></small></span></div><?php endforeach; ?></div><?php endforeach; ?>
        </section>

        <?php if ($editable): ?>
        <?php if ($booking['paymentTiming'] === 'PAY_AT_PROPERTY'): ?>
        <section class="booking-card booking-date-change-card">
            <div class="booking-card__heading"><span class="booking-card__icon"><svg class="app-icon"><use href="#icon-calendar"/></svg></span><div><h2>Đổi ngày lưu trú</h2><p>Hệ thống sẽ tính lại giá, chính sách và tồn phòng hiện tại trước khi bạn xác nhận.</p></div></div>
            <form class="booking-date-form" method="post" action="<?= $baseUrl ?>/account/bookings/<?= rawurlencode((string) $booking['code']) ?>/date-change-preview">
                <input type="hidden" name="_token" value="<?= $csrfToken ?>">
                <div class="field-grid"><label class="field"><span>Ngày nhận phòng mới</span><input type="date" name="checkIn" value="<?= $dateChangeQuote['checkIn'] ?? $booking['checkIn'] ?>" required></label><label class="field"><span>Ngày trả phòng mới</span><input type="date" name="checkOut" value="<?= $dateChangeQuote['checkOut'] ?? $booking['checkOut'] ?>" required></label></div>
                <button class="button button--secondary" type="submit">Kiểm tra giá và phòng trống</button>
            </form>
            <?php if ($dateChangeQuote !== null): ?>
                <?php $dateChangeDelta = (float) $dateChangeQuote['newTotal'] - (float) $dateChangeQuote['oldTotal']; ?>
                <div class="date-change-quote">
                    <div><span>Kỳ lưu trú mới</span><strong><?= $date($dateChangeQuote['checkIn']) ?> → <?= $date($dateChangeQuote['checkOut']) ?></strong></div>
                    <div><span>Tổng hiện tại</span><strong><?= $money($dateChangeQuote['oldTotal']) ?></strong></div>
                    <div><span>Tổng mới</span><strong><?= $money($dateChangeQuote['newTotal']) ?></strong></div>
                    <div><span>Chênh lệch</span><strong class="<?= $dateChangeDelta > 0 ? 'is-increase' : 'is-decrease' ?>"><?= $dateChangeDelta > 0 ? '+' : '' ?><?= $money($dateChangeDelta) ?></strong></div>
                </div>
                <p class="date-change-expiry">Báo giá có hiệu lực đến <?= date('d/m/Y H:i', strtotime((string) $dateChangeQuote['validUntil'])) ?> và sẽ được kiểm tra lại khi xác nhận.</p>
                <form method="post" action="<?= $baseUrl ?>/account/bookings/<?= rawurlencode((string) $booking['code']) ?>/date-change">
                    <input type="hidden" name="_token" value="<?= $csrfToken ?>">
                    <input type="hidden" name="checkIn" value="<?= $dateChangeQuote['checkIn'] ?>">
                    <input type="hidden" name="checkOut" value="<?= $dateChangeQuote['checkOut'] ?>">
                    <input type="hidden" name="quoteFingerprint" value="<?= $dateChangeQuote['fingerprint'] ?>">
                    <button class="button button--primary" type="submit">Xác nhận đổi ngày</button>
                </form>
            <?php endif; ?>
        </section>
        <?php endif; ?>

        <section class="booking-card booking-edit-card">
            <div class="booking-card__heading"><span class="booking-card__icon"><svg class="app-icon"><use href="#icon-edit"/></svg></span><div><h2>Sửa thông tin khách</h2><p>Chỉ sửa liên hệ, yêu cầu đặc biệt và tên khách trước giờ nhận phòng.</p></div></div>
            <form class="booking-edit-form" method="post" action="<?= $baseUrl ?>/account/bookings/<?= rawurlencode((string) $booking['code']) ?>/guest-details">
                <input type="hidden" name="_token" value="<?= $csrfToken ?>">
                <div class="field-grid"><label class="field"><span>Họ tên người đặt</span><input name="bookerName" value="<?= $booking['booker']['name'] ?>" maxlength="150" required></label><label class="field"><span>Email</span><input type="email" name="bookerEmail" value="<?= $booking['booker']['email'] ?>" maxlength="190" required></label></div>
                <label class="field"><span>Số điện thoại</span><input name="bookerPhone" value="<?= $booking['booker']['phone'] ?>" maxlength="20" required></label>
                <label class="field"><span>Yêu cầu đặc biệt</span><textarea name="specialRequest" rows="3" maxlength="1000"><?= $booking['booker']['specialRequest'] ?? '' ?></textarea></label>
                <?php foreach ($booking['items'] as $item): ?><fieldset class="booking-guest-fields"><legend><?= $item['roomTypeName'] ?> · Phòng <?= (int) $item['roomOrder'] ?></legend><p>Giữ nguyên <?= count($item['guests']) ?> khách; biểu mẫu không cho đổi số lượng hay độ tuổi.</p><?php foreach ($item['guests'] as $index => $guest): ?><label class="field"><span><?= $guestType[$guest['type']] ?? $guest['type'] ?> <?= $index + 1 ?><?= $guest['age'] !== null ? ' · ' . (int) $guest['age'] . ' tuổi' : '' ?></span><input name="guestNames[<?= (int) $item['id'] ?>][]" value="<?= $guest['name'] ?>" maxlength="150" required></label><?php endforeach; ?></fieldset><?php endforeach; ?>
                <div class="booking-edit-note"><svg class="app-icon"><use href="#icon-review"/></svg><span>Biểu mẫu này chỉ sửa thông tin khách; giá, ngày ở và tồn phòng không thay đổi.</span></div>
                <button class="button button--primary" type="submit">Lưu thông tin khách</button>
            </form>
        </section>
        <?php endif; ?>

        <?php if ($listPath === '/account/bookings'): ?>
        <section class="booking-card booking-cancellation-card">
            <div class="booking-card__heading"><span class="booking-card__icon"><svg class="app-icon"><use href="#icon-review"/></svg></span><div><h2>Hủy đặt chỗ</h2><p>Phí được tính từ chính sách snapshot tại thời điểm đặt và được kiểm tra lại khi bạn xác nhận.</p></div></div>
            <?php if ($cancellationQuote !== null): ?>
                <div class="cancellation-quote">
                    <div><span>Phí hủy hiện tại</span><strong><?= $money($cancellationQuote['fee']) ?></strong></div>
                    <div><span>Tiền dự kiến hoàn</span><strong><?= $money($cancellationQuote['expectedRefund']) ?></strong></div>
                    <div><span>Báo giá có hiệu lực đến</span><strong><?= date('d/m/Y H:i', strtotime((string) $cancellationQuote['validUntil'])) ?></strong></div>
                </div>
                <form class="booking-cancel-form" method="post" action="<?= $baseUrl ?>/account/bookings/<?= rawurlencode((string) $booking['code']) ?>/cancel">
                    <input type="hidden" name="_token" value="<?= $csrfToken ?>">
                    <input type="hidden" name="cancellationKey" value="<?= $cancellationKey ?>">
                    <input type="hidden" name="expectedFee" value="<?= $cancellationQuote['fee'] ?>">
                    <label class="field"><span>Lý do hủy</span><textarea name="reason" rows="3" minlength="5" maxlength="1000" required placeholder="Cho cơ sở biết lý do bạn hủy booking"></textarea></label>
                    <div class="cancellation-actions">
                        <button class="button button--danger" type="submit">Xác nhận hủy booking</button>
                    </div>
                </form>
                <form method="post" action="<?= $baseUrl ?>/account/bookings/<?= rawurlencode((string) $booking['code']) ?>/cancel-preview">
                    <input type="hidden" name="_token" value="<?= $csrfToken ?>">
                    <button class="button button--secondary" type="submit">Tính lại báo giá hủy</button>
                </form>
            <?php elseif ($booking['status'] === 'CONFIRMED'): ?>
                <p class="empty-state">Hiện không thể lập báo giá hủy cho booking này.</p>
            <?php endif; ?>

            <?php if ($booking['status'] === 'CONFIRMED'): ?>
                <div class="waiver-panel">
                    <h3>Yêu cầu miễn phí hủy</h3>
                    <?php if ($pendingWaiver !== null): ?>
                        <p>Yêu cầu #<?= (int) $pendingWaiver['id'] ?> đang chờ cơ sở phản hồi.</p>
                        <form method="post" action="<?= $baseUrl ?>/account/bookings/<?= rawurlencode((string) $booking['code']) ?>/waiver/withdraw">
                            <input type="hidden" name="_token" value="<?= $csrfToken ?>">
                            <input type="hidden" name="waiverId" value="<?= (int) $pendingWaiver['id'] ?>">
                            <button class="button button--secondary" type="submit">Rút yêu cầu</button>
                        </form>
                    <?php else: ?>
                        <form class="booking-cancel-form" method="post" action="<?= $baseUrl ?>/account/bookings/<?= rawurlencode((string) $booking['code']) ?>/waiver">
                            <input type="hidden" name="_token" value="<?= $csrfToken ?>">
                            <label class="field"><span>Lý do xin miễn phí hủy</span><textarea name="reason" rows="3" minlength="5" maxlength="1000" required placeholder="Mô tả hoàn cảnh để cơ sở xem xét"></textarea></label>
                            <button class="button button--primary" type="submit">Gửi yêu cầu xem xét</button>
                        </form>
                    <?php endif; ?>
                </div>
            <?php endif; ?>

            <?php if ($waivers !== []): ?>
                <div class="waiver-history"><h3>Lịch sử yêu cầu</h3><?php foreach ($waivers as $waiver): ?><article><span class="booking-status booking-status--<?= strtolower((string) $waiver['status']) ?>"><i></i><?= $escape($waiver['status']) ?></span><p><?= $escape($waiver['reason']) ?></p><?php if ($waiver['response'] !== null): ?><small>Phản hồi: <?= $escape($waiver['response']) ?></small><?php endif; ?></article><?php endforeach; ?></div>
            <?php endif; ?>
        </section>
        <?php endif; ?>
    </main>

    <aside class="booking-summary-card">
        <span>Tổng giá booking</span><strong><?= $money($booking['grandTotal']) ?></strong><p><?= count($booking['items']) ?> phòng · <?= $date($booking['checkIn']) ?> → <?= $date($booking['checkOut']) ?></p>
        <hr>
        <dl><div><dt>Người đặt</dt><dd><?= $booking['booker']['name'] ?></dd></div><div><dt>Email</dt><dd><?= $booking['booker']['email'] ?></dd></div><div><dt>Điện thoại</dt><dd><?= $booking['booker']['phone'] ?></dd></div><?php if ($booking['booker']['specialRequest'] !== null): ?><div><dt>Yêu cầu đặc biệt</dt><dd><?= $booking['booker']['specialRequest'] ?></dd></div><?php endif; ?></dl>
        <a class="button button--secondary" href="<?= $baseUrl . $listPath ?>">Quay lại danh sách</a>
    </aside>
</div>

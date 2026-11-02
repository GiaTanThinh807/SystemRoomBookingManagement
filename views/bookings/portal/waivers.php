<?php

declare(strict_types=1);

$escape = static fn (mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
$date = static fn (mixed $value): string => date('d/m/Y', strtotime((string) $value));
?>
<header class="resource-header booking-portal-header">
    <div><span class="booking-eyebrow">Booking operations</span><h1>Yêu cầu miễn phí hủy</h1><p>Chỉ hiển thị yêu cầu đang chờ của các cơ sở thuộc tổ chức hiện tại.</p></div>
    <span class="queue-count"><strong><?= count($waivers) ?></strong> đang chờ</span>
</header>

<section class="resource-surface waiver-queue">
    <?php if ($waivers === []): ?>
        <div class="empty-state"><h2>Không có yêu cầu đang chờ</h2><p>Các yêu cầu mới sẽ xuất hiện tại đây.</p></div>
    <?php else: ?>
        <?php foreach ($waivers as $waiver): ?>
            <article class="waiver-review-card">
                <header><div><span>Yêu cầu #<?= (int) $waiver['id'] ?></span><h2><?= $escape($waiver['propertyName']) ?></h2><a href="<?= $baseUrl ?>/partner/bookings/<?= rawurlencode((string) $waiver['bookingCode']) ?>"><?= $escape($waiver['bookingCode']) ?></a></div><span class="booking-status booking-status--pending"><i></i>Đang chờ</span></header>
                <dl><div><dt>Nhận phòng</dt><dd><?= $date($waiver['checkIn']) ?></dd></div><div><dt>Trả phòng</dt><dd><?= $date($waiver['checkOut']) ?></dd></div><div><dt>Gửi lúc</dt><dd><?= date('d/m/Y H:i', strtotime((string) $waiver['createdAt'])) ?></dd></div></dl>
                <blockquote><?= $escape($waiver['reason']) ?></blockquote>
                <form method="post" action="<?= $baseUrl ?>/partner/waivers/<?= (int) $waiver['id'] ?>/decision">
                    <input type="hidden" name="_token" value="<?= $csrfToken ?>">
                    <label class="field"><span>Phản hồi cho khách</span><textarea name="response" rows="3" minlength="2" maxlength="1000" required></textarea></label>
                    <div class="waiver-decision-actions"><button class="button button--secondary" type="submit" name="decision" value="REJECTED">Từ chối</button><button class="button button--primary" type="submit" name="decision" value="APPROVED">Duyệt và hủy miễn phí</button></div>
                </form>
            </article>
        <?php endforeach; ?>
    <?php endif; ?>
</section>

<?php

declare(strict_types=1);

/** @var array<string, mixed> $pageData */
$statusLabels = [
    'PENDING_PAYMENT' => 'Chờ thanh toán',
    'CONFIRMED' => 'Đã xác nhận',
    'PAYMENT_FAILED' => 'Thanh toán thất bại',
    'CANCELLED' => 'Đã hủy',
    'NO_SHOW' => 'Không đến',
    'COMPLETED' => 'Hoàn tất',
];
$money = static fn (mixed $amount): string => number_format((float) $amount, 0, ',', '.') . ' ₫';
$date = static fn (mixed $value): string => date('d/m/Y', strtotime((string) $value));
$pageUrl = static function (int $page) use ($baseUrl, $listPath, $statusFilter, $searchQuery): string {
    $query = array_filter(['page' => $page, 'status' => $statusFilter, 'q' => $searchQuery], static fn (mixed $value): bool => $value !== '');
    return $baseUrl . $listPath . '?' . http_build_query($query);
};
?>
<header class="resource-header booking-portal-header">
    <div><span class="booking-eyebrow">Booking workspace</span><h1><?= $heading ?></h1><p><?= $description ?></p></div>
    <span class="queue-count"><strong><?= (int) $pageData['total'] ?></strong> booking</span>
</header>

<section class="resource-surface booking-portal">
    <form class="resource-toolbar booking-toolbar" method="get" action="<?= $baseUrl . $listPath ?>">
        <?php if ($searchable): ?>
            <label class="toolbar-search"><svg class="app-icon"><use href="#icon-search"/></svg><span class="visually-hidden">Tìm booking</span><input type="search" name="q" value="<?= $searchQuery ?>" placeholder="Mã booking, email hoặc cơ sở"></label>
        <?php endif; ?>
        <label class="toolbar-select"><span class="visually-hidden">Lọc trạng thái</span><select name="status"><option value="">Tất cả trạng thái</option><?php foreach ($statusLabels as $code => $label): ?><option value="<?= $code ?>"<?= $statusFilter === $code ? ' selected' : '' ?>><?= $label ?></option><?php endforeach; ?></select></label>
        <button class="button button--secondary button--small" type="submit">Áp dụng</button>
    </form>

    <?php if ($pageData['items'] === []): ?>
        <div class="resource-empty"><svg class="app-icon"><use href="#icon-bed"/></svg><h2>Không có booking phù hợp</h2><p>Thử đổi bộ lọc hoặc từ khóa tra cứu.</p></div>
    <?php else: ?>
        <div class="resource-table-wrap">
            <table class="resource-table booking-table">
                <thead><tr><th>Booking</th><th>Lưu trú</th><th>Người đặt</th><th>Tổng tiền</th><th>Trạng thái</th><th><span class="visually-hidden">Mở</span></th></tr></thead>
                <tbody>
                <?php foreach ($pageData['items'] as $item): ?>
                    <tr>
                        <td><div class="booking-code"><a href="<?= $baseUrl . $listPath ?>/<?= rawurlencode((string) $item['code']) ?>"><?= $item['code'] ?></a><small><?= $item['propertyName'] ?> · <?= (int) $item['roomCount'] ?> phòng</small></div></td>
                        <td><strong><?= $date($item['checkIn']) ?> → <?= $date($item['checkOut']) ?></strong><small><?= $item['paymentTiming'] === 'PAY_ONLINE' ? 'Thanh toán trực tuyến' : 'Thanh toán tại cơ sở' ?></small></td>
                        <td><?= $item['bookerName'] ?><small><?= $item['bookerEmail'] ?></small></td>
                        <td><strong><?= $money($item['grandTotal']) ?></strong></td>
                        <td><span class="booking-status booking-status--<?= strtolower((string) $item['status']) ?>"><i></i><?= $statusLabels[$item['status']] ?? $item['status'] ?></span></td>
                        <td class="resource-actions"><a class="icon-button" href="<?= $baseUrl . $listPath ?>/<?= rawurlencode((string) $item['code']) ?>" aria-label="Mở booking <?= $item['code'] ?>"><svg class="app-icon"><use href="#icon-chevron"/></svg></a></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <footer class="resource-footer booking-pagination">
            <span>Trang <strong><?= (int) $pageData['page'] ?></strong> / <?= (int) $pageData['pageCount'] ?> · <?= (int) $pageData['total'] ?> kết quả</span>
            <span class="booking-pagination__links">
                <?php if ($pageData['page'] > 1): ?><a class="button button--secondary button--small" href="<?= $pageUrl((int) $pageData['page'] - 1) ?>">Trang trước</a><?php endif; ?>
                <?php if ($pageData['page'] < $pageData['pageCount']): ?><a class="button button--secondary button--small" href="<?= $pageUrl((int) $pageData['page'] + 1) ?>">Trang sau</a><?php endif; ?>
            </span>
        </footer>
    <?php endif; ?>
</section>

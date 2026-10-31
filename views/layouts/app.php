<?php

declare(strict_types=1);

/** @var string $title */
/** @var string $content */
/** @var string $baseUrl */
/** @var string $csrfToken */
/** @var string|null $currentUserName */
/** @var string|null $currentUserRole */
/** @var array<string, list<string>> $flashes */
$currentUserRole = $currentUserRole ?? null;
$authenticated = $currentUserName !== null && $currentUserRole !== null;
$useAppShell = $authenticated && in_array($currentUserRole, ['PARTNER', 'ADMIN'], true);
$workspaceUrl = $currentUserRole === 'ADMIN' ? $baseUrl . '/admin/properties/review' : $baseUrl . '/partner/properties';
$publicPath = dirname(__DIR__, 2) . '/public';
$assetUrl = static function (string $path) use ($baseUrl, $publicPath): string {
    $normalizedPath = '/' . ltrim($path, '/');
    $modifiedAt = filemtime($publicPath . $normalizedPath);

    return $baseUrl . $normalizedPath . ($modifiedAt === false ? '' : '?v=' . $modifiedAt);
};
?>
<!doctype html>
<html lang="vi">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= $title ?></title>
    <meta name="color-scheme" content="light">
    <style>
        /* Giữ SVG an toàn nếu stylesheet ngoài chưa tải xong hoặc bị cache sai phiên bản. */
        .svg-sprite { position: absolute; width: 0; height: 0; overflow: hidden; }
        .app-icon { width: 20px; height: 20px; display: block; fill: none; stroke: currentColor; stroke-width: 1.75; stroke-linecap: round; stroke-linejoin: round; }
    </style>
    <link rel="stylesheet" href="<?= $assetUrl('/assets/css/app.css') ?>">
    <script src="<?= $assetUrl('/assets/js/properties.js') ?>" defer></script>
    <script src="<?= $assetUrl('/assets/js/search.js') ?>" defer></script>
</head>
<body class="<?= $useAppShell ? 'has-app-shell' : 'has-public-shell' ?>">
    <svg class="svg-sprite" width="0" height="0" aria-hidden="true" focusable="false">
        <symbol id="icon-building" viewBox="0 0 24 24"><path d="M4 21V5a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v16M8 7h2M13 7h1M8 11h2M13 11h1M8 15h2M13 15h1M2 21h20M17 9h2a1 1 0 0 1 1 1v11"/></symbol>
        <symbol id="icon-grid" viewBox="0 0 24 24"><rect x="3" y="3" width="7" height="7" rx="1"/><rect x="14" y="3" width="7" height="7" rx="1"/><rect x="3" y="14" width="7" height="7" rx="1"/><rect x="14" y="14" width="7" height="7" rx="1"/></symbol>
        <symbol id="icon-bed" viewBox="0 0 24 24"><path d="M3 20v-8M21 20v-6a2 2 0 0 0-2-2H5a2 2 0 0 0-2 2v2h18M7 12V8a2 2 0 0 1 2-2h2a2 2 0 0 1 2 2v4"/></symbol>
        <symbol id="icon-users" viewBox="0 0 24 24"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2M9 11a4 4 0 1 0 0-8 4 4 0 0 0 0 8ZM22 21v-2a4 4 0 0 0-3-3.87M16 3.13a4 4 0 0 1 0 7.75"/></symbol>
        <symbol id="icon-chart" viewBox="0 0 24 24"><path d="M4 19V9M10 19V5M16 19v-7M22 19V3M2 21h21"/></symbol>
        <symbol id="icon-file" viewBox="0 0 24 24"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8zM14 2v6h6M8 13h8M8 17h8"/></symbol>
        <symbol id="icon-settings" viewBox="0 0 24 24"><circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.7 1.7 0 0 0 .34 1.88l.06.06-2.83 2.83-.06-.06A1.7 1.7 0 0 0 15 19.4a1.7 1.7 0 0 0-1 .6 1.7 1.7 0 0 0-.4 1.1V21h-4v-.09A1.7 1.7 0 0 0 8.6 19.4a1.7 1.7 0 0 0-1.88.34l-.06.06-2.83-2.83.06-.06A1.7 1.7 0 0 0 4.6 15a1.7 1.7 0 0 0-.6-1 1.7 1.7 0 0 0-1.1-.4H3v-4h.09A1.7 1.7 0 0 0 4.6 8.6a1.7 1.7 0 0 0-.34-1.88l-.06-.06 2.83-2.83.06.06A1.7 1.7 0 0 0 9 4.6a1.7 1.7 0 0 0 1-.6 1.7 1.7 0 0 0 .4-1.1V3h4v.09A1.7 1.7 0 0 0 15.4 4.6a1.7 1.7 0 0 0 1.88-.34l.06-.06 2.83 2.83-.06.06A1.7 1.7 0 0 0 19.4 9c.13.37.34.7.6 1 .29.31.68.5 1.1.5h.09v4h-.09a1.7 1.7 0 0 0-1.7.5Z"/></symbol>
        <symbol id="icon-search" viewBox="0 0 24 24"><circle cx="11" cy="11" r="7"/><path d="m16 16 5 5"/></symbol>
        <symbol id="icon-bell" viewBox="0 0 24 24"><path d="M18 8a6 6 0 0 0-12 0c0 7-3 7-3 9h18c0-2-3-2-3-9M10 21h4"/></symbol>
        <symbol id="icon-plus" viewBox="0 0 24 24"><path d="M12 5v14M5 12h14"/></symbol>
        <symbol id="icon-more" viewBox="0 0 24 24"><circle cx="5" cy="12" r="1"/><circle cx="12" cy="12" r="1"/><circle cx="19" cy="12" r="1"/></symbol>
        <symbol id="icon-edit" viewBox="0 0 24 24"><path d="M12 20h9M16.5 3.5a2.1 2.1 0 0 1 3 3L8 18l-4 1 1-4z"/></symbol>
        <symbol id="icon-send" viewBox="0 0 24 24"><path d="m22 2-7 20-4-9-9-4zM22 2 11 13"/></symbol>
        <symbol id="icon-check" viewBox="0 0 24 24"><path d="m5 12 4 4L19 6"/></symbol>
        <symbol id="icon-check-circle" viewBox="0 0 24 24"><circle cx="12" cy="12" r="9"/><path d="m8 12 3 3 5-6"/></symbol>
        <symbol id="icon-circle" viewBox="0 0 24 24"><circle cx="12" cy="12" r="9"/></symbol>
        <symbol id="icon-clock" viewBox="0 0 24 24"><circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/></symbol>
        <symbol id="icon-image" viewBox="0 0 24 24"><rect x="3" y="4" width="18" height="16" rx="2"/><circle cx="8.5" cy="9" r="1.5"/><path d="m21 15-5-5L5 20"/></symbol>
        <symbol id="icon-pin" viewBox="0 0 24 24"><path d="M20 10c0 5-8 12-8 12S4 15 4 10a8 8 0 1 1 16 0Z"/><circle cx="12" cy="10" r="2.5"/></symbol>
        <symbol id="icon-star" viewBox="0 0 24 24"><path d="m12 3 2.8 5.7 6.2.9-4.5 4.4 1.1 6.2-5.6-3-5.6 3 1.1-6.2L3 9.6l6.2-.9z"/></symbol>
        <symbol id="icon-upload" viewBox="0 0 24 24"><path d="M12 16V3M7 8l5-5 5 5M5 14H3v6h18v-6h-2"/></symbol>
        <symbol id="icon-trash" viewBox="0 0 24 24"><path d="M3 6h18M8 6V3h8v3M19 6l-1 15H6L5 6M10 11v5M14 11v5"/></symbol>
        <symbol id="icon-logout" viewBox="0 0 24 24"><path d="M10 5H5v14h5M14 8l4 4-4 4M8 12h10"/></symbol>
        <symbol id="icon-menu" viewBox="0 0 24 24"><path d="M4 7h16M4 12h16M4 17h16"/></symbol>
        <symbol id="icon-chevron" viewBox="0 0 24 24"><path d="m9 18 6-6-6-6"/></symbol>
        <symbol id="icon-review" viewBox="0 0 24 24"><path d="M9 11 11 13 15 9M5 3h14a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2Z"/></symbol>
    </svg>
    <a class="skip-link" href="#main-content">Bỏ qua đến nội dung chính</a>

    <?php if ($useAppShell): ?>
        <div class="app-shell" data-app-shell>
            <aside class="app-sidebar" data-app-sidebar>
                <a class="app-brand" href="<?= $workspaceUrl ?>" aria-label="StayBooking">
                    <img
                        class="app-brand__logo"
                        src="<?= $assetUrl('/assets/images/accommodation/shared/logoNew.png') ?>"
                        width="174"
                        height="58"
                        alt=""
                    >
                </a>
                <nav class="app-navigation" aria-label="Điều hướng chính">
                    <?php if ($currentUserRole === 'ADMIN'): ?>
                        <span class="app-navigation__section">Quản trị</span>
                        <a class="is-active" href="<?= $baseUrl ?>/admin/properties/review"><svg class="app-icon"><use href="#icon-review"/></svg><span>Kiểm duyệt cơ sở</span></a>
                        <span class="is-disabled"><svg class="app-icon"><use href="#icon-users"/></svg><span>Tài khoản</span></span>
                        <span class="is-disabled"><svg class="app-icon"><use href="#icon-file"/></svg><span>Nhật ký hệ thống</span></span>
                    <?php else: ?>
                        <span class="app-navigation__section">Không gian đối tác</span>
                        <span class="is-disabled"><svg class="app-icon"><use href="#icon-grid"/></svg><span>Tổng quan</span></span>
                        <a class="is-active" href="<?= $baseUrl ?>/partner/properties"><svg class="app-icon"><use href="#icon-building"/></svg><span>Cơ sở lưu trú</span></a>
                        <span class="is-disabled"><svg class="app-icon"><use href="#icon-bed"/></svg><span>Đặt phòng</span></span>
                        <span class="is-disabled"><svg class="app-icon"><use href="#icon-users"/></svg><span>Khách hàng</span></span>
                        <span class="is-disabled"><svg class="app-icon"><use href="#icon-chart"/></svg><span>Doanh thu</span></span>
                        <span class="is-disabled"><svg class="app-icon"><use href="#icon-file"/></svg><span>Báo cáo</span></span>
                    <?php endif; ?>
                </nav>
                <div class="app-sidebar__footer"><span class="is-disabled"><svg class="app-icon"><use href="#icon-settings"/></svg><span>Cài đặt</span></span></div>
            </aside>
            <button class="sidebar-backdrop" type="button" data-sidebar-toggle aria-label="Đóng menu"></button>

            <div class="app-workspace">
                <header class="app-topbar">
                    <button class="icon-button app-topbar__menu" type="button" data-sidebar-toggle aria-label="Mở menu"><svg class="app-icon"><use href="#icon-menu"/></svg></button>
                    <div class="app-topbar__context"><?= $currentUserRole === 'ADMIN' ? 'Quản trị hệ thống' : 'Partner Hub' ?></div>
                    <div class="app-topbar__account">
                        <button class="icon-button" type="button" aria-label="Thông báo"><svg class="app-icon"><use href="#icon-bell"/></svg></button>
                        <span class="app-user"><span class="app-user__avatar"><?= mb_strtoupper(mb_substr($currentUserName, 0, 1)) ?></span><span><strong><?= $currentUserName ?></strong><small><?= $currentUserRole === 'ADMIN' ? 'Quản trị viên' : 'Đối tác lưu trú' ?></small></span></span>
                        <form method="post" action="<?= $baseUrl ?>/auth/logout"><input type="hidden" name="_token" value="<?= $csrfToken ?>"><button class="icon-button" type="submit" aria-label="Đăng xuất"><svg class="app-icon"><use href="#icon-logout"/></svg></button></form>
                    </div>
                </header>
                <?php if ($flashes !== []): ?>
                    <div class="app-flashes" aria-live="polite"><?php foreach ($flashes as $type => $messages): ?><?php foreach ($messages as $message): ?><div class="alert alert--<?= $type === 'success' ? 'success' : 'info' ?>"><?= $message ?></div><?php endforeach; ?><?php endforeach; ?></div>
                <?php endif; ?>
                <main id="main-content" class="app-content"><?= $content ?></main>
            </div>
        </div>
    <?php else: ?>
        <header class="site-header"><div class="container site-header__inner"><a class="brand" href="<?= $baseUrl ?>/" aria-label="StayBooking"><img class="brand__logo" src="<?= $assetUrl('/assets/images/accommodation/shared/logoNew.png') ?>" width="186" height="62" alt=""></a><nav class="site-nav" aria-label="Điều hướng công khai"><a class="nav-link" href="<?= $baseUrl ?>/#search">Tìm chỗ nghỉ</a><?php if ($authenticated): ?><span class="customer-nav-name"><?= $currentUserName ?></span><form method="post" action="<?= $baseUrl ?>/auth/logout"><input type="hidden" name="_token" value="<?= $csrfToken ?>"><button class="button button--header" type="submit">Đăng xuất</button></form><?php else: ?><a class="nav-link" href="<?= $baseUrl ?>/auth/login">Đăng nhập</a><a class="button button--header" href="<?= $baseUrl ?>/auth/register">Đăng ký</a><?php endif; ?></nav></div></header>
        <?php if ($flashes !== []): ?><div class="container flash-region" aria-live="polite"><?php foreach ($flashes as $type => $messages): ?><?php foreach ($messages as $message): ?><div class="alert alert--<?= $type === 'success' ? 'success' : 'info' ?>"><?= $message ?></div><?php endforeach; ?><?php endforeach; ?></div><?php endif; ?>
        <main id="main-content" class="container page-content"><?= $content ?></main>
        <footer class="site-footer"><div class="container">StayBooking · Nền tảng đặt phòng tại TP.HCM</div></footer>
    <?php endif; ?>
</body>
</html>

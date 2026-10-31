<?php

declare(strict_types=1);

/** @var array<string, mixed> $preview */
$allocation = $preview['allocation'];
$property = $allocation['property'];
?>
<section class="checkout-preview">
    <p class="eyebrow eyebrow--blue">Preview đặt phòng</p>
    <h1>Xác nhận lựa chọn tại <?= $property['name'] ?></h1>
    <p><?= $preview['checkIn'] ?> → <?= $preview['checkOut'] ?> · <?= count($allocation['rooms']) ?> phòng</p>
    <?php foreach ($allocation['warnings'] as $warning): ?><div class="selection-changed"><?= $warning ?></div><?php endforeach; ?>
    <div class="checkout-preview__total"><span>Tổng cộng</span><strong><?= number_format((float) $preview['grandTotal'], 0, ',', '.') ?> ₫</strong></div>
    <p>Preview này chưa giữ phòng và hết hạn lúc <?= $preview['expiresAt'] ?>.</p>
</section>

<?php /** @var App\Core\View $this */
$icons = [403 => 'shield-x', 404 => 'search-x', 419 => 'timer-off', 500 => 'server-crash'];
$titles = [403 => 'ไม่มีสิทธิ์เข้าถึง', 404 => 'ไม่พบหน้าที่ต้องการ', 405 => 'ไม่รองรับคำขอนี้', 419 => 'เซสชันหมดอายุ', 500 => 'เกิดข้อผิดพลาด'];
?>
<div class="d-flex align-items-center justify-content-center" style="min-height:70vh">
    <div class="card-x text-center p-5" style="max-width:520px">
        <div class="empty-icon mx-auto mb-3" style="width:72px;height:72px;border-radius:22px;display:grid;place-items:center;background:var(--bg)">
            <?= icon($icons[$status] ?? 'alert-triangle', 'icon-lg') ?>
        </div>
        <div class="display-6 fw-600 mb-1"><?= (int) $status ?></div>
        <h2 class="h5"><?= e($titles[$status] ?? 'Error') ?></h2>
        <p class="text-muted"><?= e($message) ?></p>
        <a href="<?= e(url('/')) ?>" class="btn btn-primary mt-2"><?= icon('home') ?> กลับหน้าหลัก</a>
    </div>
</div>

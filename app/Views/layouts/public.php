<?php /** @var App\Core\View $this */ $repairHome = isset($hotel) ? '/repair/'.rawurlencode($hotel['code']) : '/repair'; ?>
<!doctype html><html lang="th"><head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover"><meta name="robots" content="noindex, nofollow"><meta name="theme-color" content="#111827">
<meta name="description" content="แจ้งปัญหาห้องพักหรือพื้นที่โรงแรม SUNGROUP ได้โดยไม่ต้องมีบัญชีผู้ใช้">
<title><?= e($title) ?> · SUNGROUP</title>
<link rel="icon" href="<?= e(asset('images/favicon.svg')) ?>" type="image/svg+xml">
<link rel="stylesheet" href="<?= e(asset('vendor/bootstrap/bootstrap.min.css')) ?>"><link rel="stylesheet" href="<?= e(asset('css/app.css')) ?>"><link rel="stylesheet" href="<?= e(asset('css/public-repair.css')) ?>">
</head><body class="public-repair">
<header class="repair-nav"><a class="repair-brand" href="<?= e(url($repairHome)) ?>"><span class="brand-logo"><?= icon('wrench') ?></span><span><strong><?= isset($hotel) ? e($hotel['name_en']) : 'SUNGROUP' ?></strong><small><?= isset($hotel) ? e($hotel['code']).' · ' : '' ?>HOTELS & RESORTS · SERVICE REQUEST</small></span></a><a class="repair-staff" href="<?= e(url('/login')) ?>">สำหรับเจ้าหน้าที่ <?= icon('arrow-up-right') ?></a></header>
<?php if (\App\Services\DemoShowcaseSeeder::isTestWorkspace()): ?><div class="repair-demo">พื้นที่สาธิต · แบบฟอร์มนี้ส่งเข้าใบงานในฐานทดสอบ</div><?php endif ?>
<main id="main-content" class="repair-main"><?= $this->section('content') ?></main>
<footer class="repair-footer">SUNGROUP Maintenance Center · ดูแลทุกปัญหา เพื่อการพักผ่อนที่ราบรื่น</footer>
<script src="<?= e(asset('vendor/lucide/lucide.min.js')) ?>"></script><script src="<?= e(asset('js/public-repair.js')) ?>"></script>
</body></html>

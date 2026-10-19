<?php
/** @var App\Core\View $this */
use App\Core\Session;
use App\Helpers\UI;

$flash = array_filter(['success' => Session::getFlash('success'), 'error' => Session::getFlash('error'), 'warning' => Session::getFlash('warning'), 'info' => Session::getFlash('info')]);
?>
<!doctype html>
<html lang="th">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="csrf-token" content="<?= e(csrf_token()) ?>">
    <meta name="description" content="เข้าสู่ระบบ SUNGROUP Maintenance Center ระบบบริหารงานซ่อมบำรุงกลุ่มโรงแรม">
    <meta name="robots" content="noindex, nofollow">
    <meta name="theme-color" content="#0B1220">
    <title><?= e(($title ?? '') ? $title . ' · ' : '') ?>SUNGROUP Maintenance Center</title>
    <link rel="icon" href="<?= e(asset('images/favicon.svg')) ?>" type="image/svg+xml">
    <link rel="stylesheet" href="<?= e(asset('vendor/bootstrap/bootstrap.min.css')) ?>">
    <link rel="stylesheet" href="<?= e(asset('css/app.css')) ?>">
</head>
<body>
<?= $this->section('content') ?>
<script type="application/json" id="flash-data"><?= UI::json($flash) ?></script>
<script src="<?= e(asset('vendor/lucide/lucide.min.js')) ?>"></script>
<script src="<?= e(asset('js/app.js')) ?>"></script>
</body>
</html>

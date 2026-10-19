<?php
/** @var App\Core\View $this */
use App\Core\Auth;
use App\Core\HotelContext;
use App\Core\Session;
use App\Helpers\Navigation;
use App\Helpers\UI;
use App\Services\InAppNotifications;

$user = Auth::user();
$hotels = HotelContext::accessibleHotels();
$selected = HotelContext::selectedHotel();
$accent = $selected['accent_color'] ?? '#2563EB';
$path = App\Core\Request::capture()->path;
$unread = $user ? InAppNotifications::unreadCount((int) $user['id']) : 0;
$flash = array_filter([
    'success' => Session::getFlash('success'),
    'error' => Session::getFlash('error'),
    'warning' => Session::getFlash('warning'),
    'info' => Session::getFlash('info'),
]);
$roleNames = [];
if ($user) {
    foreach (Auth::gate()->allRoles() as $hk => $codes) {
        foreach ($codes as $c) { $roleNames[$c] = true; }
    }
}
$title = $title ?? '';
$breadcrumbs = $breadcrumbs ?? [];
?>
<!doctype html>
<html lang="<?= e(App\Core\Lang::locale()) ?>">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="csrf-token" content="<?= e(csrf_token()) ?>">
    <meta name="base-url" content="<?= e(url('')) === '/' ? '' : e(rtrim(url(''), '/')) ?>">
    <meta name="description" content="SUNGROUP Maintenance Center – ระบบบริหารงานซ่อมบำรุงสำหรับกลุ่มโรงแรม">
    <meta name="robots" content="noindex, nofollow">
    <meta name="theme-color" content="#111827">
    <title><?= e($title ? $title . ' · ' : '') ?><?= e(setting('app_name', 'SUNGROUP Maintenance Center')) ?></title>
    <link rel="icon" href="<?= e(asset('images/favicon.svg')) ?>" type="image/svg+xml">
    <link rel="stylesheet" href="<?= e(asset('vendor/bootstrap/bootstrap.min.css')) ?>">
    <link rel="stylesheet" href="<?= e(asset('vendor/tom-select/tom-select.bootstrap5.min.css')) ?>">
    <link rel="stylesheet" href="<?= e(asset('css/app.css')) ?>">
    <?= $this->section('head') ?>
</head>
<body style="--accent: <?= e($accent) ?>">
<div class="app-shell">
    <aside class="sidebar" id="sidebar" aria-label="เมนูหลัก">
        <div class="sidebar-brand">
            <div class="brand-logo"><?= icon('wrench') ?></div>
            <div class="brand-text"><strong>SUNGROUP</strong><small>Maintenance Center</small></div>
        </div>
        <nav class="sidebar-nav">
            <?php foreach (Navigation::menu() as $group): ?>
                <div class="nav-group-label"><?= e(__($group['group'])) ?></div>
                <?php foreach ($group['items'] as $item): $active = preg_match($item['match'], $path); ?>
                    <a href="<?= e(url($item['url'])) ?>" class="nav-item-link<?= $active ? ' active' : '' ?>" title="<?= e(__($item['label'])) ?>"<?= $active ? ' aria-current="page"' : '' ?>>
                        <?= icon($item['icon']) ?><span><?= e(__($item['label'])) ?></span>
                    </a>
                <?php endforeach; ?>
            <?php endforeach; ?>
        </nav>
        <div class="sidebar-footer">
            <div class="sidebar-user">
                <div class="avatar"><?= e(UI::initials($user['name'] ?? '')) ?></div>
                <div class="sidebar-user-info flex-grow-1">
                    <strong><?= e($user['name'] ?? '') ?></strong>
                    <small><?= e(implode(', ', array_slice(array_keys($roleNames), 0, 2))) ?></small>
                </div>
                <form method="post" action="<?= e(url('/logout')) ?>" class="m-0">
                    <?= csrf_field() ?>
                    <button class="btn-sidebar-icon logout" title="<?= e(__('ออกจากระบบ')) ?>" aria-label="<?= e(__('ออกจากระบบ')) ?>"><?= icon('log-out') ?></button>
                </form>
            </div>
        </div>
    </aside>
    <div class="sidebar-backdrop"></div>

    <div class="main">
        <header class="topbar">
            <button class="icon-btn" data-action="toggle-sidebar" aria-label="<?= e(__('ย่อเมนู')) ?>"><?= icon('panel-left') ?></button>
            <div class="topbar-title min-w-0">
                <?php if ($breadcrumbs): ?>
                    <ol class="breadcrumb">
                        <li class="breadcrumb-item"><a href="<?= e(url('/')) ?>">Home</a></li>
                        <?php foreach ($breadcrumbs as $bc): ?>
                            <?php if (!empty($bc[1])): ?>
                                <li class="breadcrumb-item"><a href="<?= e(url($bc[1])) ?>"><?= e($bc[0]) ?></a></li>
                            <?php else: ?>
                                <li class="breadcrumb-item active" aria-current="page"><?= e($bc[0]) ?></li>
                            <?php endif; ?>
                        <?php endforeach; ?>
                    </ol>
                <?php endif; ?>
                <h1><?= e($title) ?></h1>
            </div>

            <form class="topbar-search" action="<?= e(url('/search')) ?>" method="get" role="search">
                <?= icon('search') ?>
                <input type="search" name="q" class="form-control" placeholder="<?= e(__('ค้นหา')) ?> เลขที่งาน, อุปกรณ์..." aria-label="<?= e(__('ค้นหา')) ?>" value="<?= e($_GET['q'] ?? '') ?>">
            </form>

            <div class="topbar-actions <?= isset($_GET['q']) ? '' : 'ms-auto ms-xl-0' ?>">
                <div class="dropdown hotel-switcher">
                    <button class="btn dropdown-toggle" type="button" data-bs-toggle="dropdown" aria-expanded="false" id="hotelSwitcher" aria-label="เลือกโรงแรม">
                        <span class="hotel-dot" style="background:<?= e($accent) ?>;color:<?= e($accent) ?>"></span>
                        <span class="name"><?= e($selected ? $selected['name_en'] : __('ทุกโรงแรม')) ?></span>
                    </button>
                    <div class="dropdown-menu dropdown-menu-end">
                        <h6 class="dropdown-header">พื้นที่ทำงาน (Workspace)</h6>
                        <?php if (count($hotels) > 1): ?>
                            <form method="post" action="<?= e(url('/hotel-scope')) ?>" class="m-0">
                                <?= csrf_field() ?>
                                <button name="hotel_id" value="all" class="dropdown-item<?= $selected ? '' : ' active' ?>">
                                    <span class="hotel-dot" style="background:#2563EB;color:#2563EB"></span>
                                    <span class="flex-grow-1"><?= e(__('ทุกโรงแรม')) ?></span>
                                    <small class="text-muted"><?= count($hotels) ?></small>
                                </button>
                            </form>
                            <div class="dropdown-divider"></div>
                        <?php endif; ?>
                        <?php foreach ($hotels as $h): ?>
                            <form method="post" action="<?= e(url('/hotel-scope')) ?>" class="m-0">
                                <?= csrf_field() ?>
                                <button name="hotel_id" value="<?= (int) $h['id'] ?>" class="dropdown-item<?= ($selected && (int) $selected['id'] === (int) $h['id']) ? ' active' : '' ?>">
                                    <span class="hotel-dot" style="background:<?= e($h['accent_color']) ?>;color:<?= e($h['accent_color']) ?>"></span>
                                    <span class="flex-grow-1 text-truncate"><?= e($h['name_en']) ?><br><small class="text-muted"><?= e($h['name_th']) ?></small></span>
                                    <small class="mono text-muted"><?= e($h['code']) ?></small>
                                </button>
                            </form>
                        <?php endforeach; ?>
                    </div>
                </div>

                <a href="<?= e(url('/notifications')) ?>" class="icon-btn" aria-label="<?= e(__('การแจ้งเตือน')) ?>">
                    <?= icon('bell') ?>
                    <span class="dot<?= $unread ? '' : ' d-none' ?>" id="notif-count"><?= $unread > 99 ? '99+' : (int) $unread ?></span>
                </a>

                <div class="dropdown">
                    <button class="icon-btn p-0" data-bs-toggle="dropdown" aria-label="เมนูผู้ใช้">
                        <span class="avatar avatar-sm"><?= e(UI::initials($user['name'] ?? '')) ?></span>
                    </button>
                    <div class="dropdown-menu dropdown-menu-end">
                        <div class="px-3 py-2">
                            <div class="fw-500"><?= e($user['name'] ?? '') ?></div>
                            <div class="small text-muted"><?= e($user['email'] ?? '') ?></div>
                        </div>
                        <div class="dropdown-divider"></div>
                        <a class="dropdown-item" href="<?= e(url('/profile')) ?>"><?= icon('user-round') ?> <?= e(__('โปรไฟล์ของฉัน')) ?></a>
                        <a class="dropdown-item" href="<?= e(url('/notifications')) ?>"><?= icon('bell') ?> <?= e(__('การแจ้งเตือน')) ?></a>
                        <div class="dropdown-divider"></div>
                        <form method="post" action="<?= e(url('/logout')) ?>" class="m-0">
                            <?= csrf_field() ?>
                            <button class="dropdown-item text-danger"><?= icon('log-out') ?> <?= e(__('ออกจากระบบ')) ?></button>
                        </form>
                    </div>
                </div>
            </div>
        </header>

        <main class="content" id="main-content">
            <?php if (\App\Services\DemoShowcaseSeeder::isTestWorkspace()): ?>
                <div class="alert alert-info d-flex flex-wrap align-items-center justify-content-between gap-2" role="status"><span><strong>DEMO · พื้นที่ทดสอบ</strong> ข้อมูลตัวอย่างเพื่อสาธิตระบบ · LINE ใช้ MOCK</span><?php if (Auth::gate()->hasGlobal('users.manage') && \App\Services\DemoShowcaseSeeder::manifest()): ?><a class="btn btn-sm btn-primary" href="<?= e(url('/demo')) ?>">เริ่มสาธิตระบบ</a><?php endif ?></div>
            <?php endif ?>
            <?php if ($errs = Session::getFlash('errors')): ?>
                <div class="alert alert-danger" role="alert"><?= icon('alert-circle') ?>
                    <div><strong><?= e(__('validation.failed')) ?></strong>
                        <ul class="mb-0 ps-3 small"><?php foreach ($errs as $msg): ?><li><?= e($msg) ?></li><?php endforeach; ?></ul>
                    </div>
                </div>
            <?php endif; ?>
            <?= $this->section('content') ?>
        </main>
    </div>
</div>

<?php if (can('tickets.create') && !preg_match('#^/tickets/create#', $path)): ?>
    <a href="<?= e(url('/tickets/create')) ?>" class="fab" aria-label="<?= e(__('แจ้งซ่อมใหม่')) ?>"><?= icon('plus') ?> <?= e(__('แจ้งซ่อมใหม่')) ?></a>
<?php endif; ?>

<script type="application/json" id="flash-data"><?= UI::json($flash) ?></script>
<script src="<?= e(asset('vendor/bootstrap/bootstrap.bundle.min.js')) ?>"></script>
<script src="<?= e(asset('vendor/lucide/lucide.min.js')) ?>"></script>
<script src="<?= e(asset('vendor/sweetalert2/sweetalert2.all.min.js')) ?>"></script>
<script src="<?= e(asset('vendor/tom-select/tom-select.complete.min.js')) ?>"></script>
<?= $this->section('vendor_scripts') ?>
<script src="<?= e(asset('js/app.js')) ?>"></script>
<script src="<?= e(asset('js/modules.js')) ?>"></script>
<?= $this->section('scripts') ?>
</body>
</html>

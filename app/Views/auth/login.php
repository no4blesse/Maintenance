<?php /** @var App\Core\View $this */ ?>
<div class="auth-wrap">
    <section class="auth-hero">
        <div class="auth-grid-bg"></div>
        <div class="d-flex align-items-center gap-3 position-relative">
            <div class="brand-logo"><?= icon('wrench') ?></div>
            <div><strong class="text-white fs-5">SUNGROUP</strong><div class="small text-secondary">Maintenance Center</div></div>
        </div>
        <div class="position-relative">
            <h1 class="mb-3">ระบบบริหารงานซ่อมบำรุง<br>สำหรับกลุ่มโรงแรมแบบครบวงจร</h1>
            <p class="text-secondary mb-4" style="max-width:520px">แจ้งซ่อม มอบหมายงาน ติดตามสถานะ คลังอะไหล่ ทะเบียนอุปกรณ์ แผน PM และรายงาน KPI — รวมไว้ในที่เดียว แยกข้อมูลแต่ละโรงแรมอย่างปลอดภัย</p>
            <div class="hotels">
                <?php foreach ($hotels as $h): ?>
                    <div class="hotel-card"><b style="background:<?= e($h['accent_color']) ?>"><?= e($h['code']) ?></b><span><?= e($h['name_en']) ?></span></div>
                <?php endforeach; ?>
            </div>
        </div>
        <div class="small text-secondary position-relative">© <?= date('Y') ?> SUNGROUP Hotels & Resorts</div>
    </section>

    <section class="auth-form">
        <div class="inner">
            <div class="d-lg-none d-flex align-items-center gap-2 mb-4">
                <div class="brand-logo"><?= icon('wrench') ?></div>
                <strong>SUNGROUP Maintenance</strong>
            </div>
            <h2 class="mb-1">เข้าสู่ระบบ</h2>
            <p class="text-muted mb-4">ยินดีต้อนรับกลับ กรุณาเข้าสู่ระบบเพื่อดำเนินการต่อ</p>

            <?php if (!empty($error)): ?>
                <div class="alert alert-danger" role="alert"><?= icon('alert-circle') ?><div><?= e($error) ?></div></div>
            <?php endif; ?>

            <form method="post" action="<?= e(url('/login')) ?>" novalidate>
                <?= csrf_field() ?>
                <div class="mb-3">
                    <label class="form-label" for="email"><?= e(__('อีเมล')) ?></label>
                    <input type="email" class="form-control" id="email" name="email" value="<?= e($email ?? '') ?>" required autocomplete="username" autofocus>
                </div>
                <div class="mb-4">
                    <label class="form-label" for="password"><?= e(__('รหัสผ่าน')) ?></label>
                    <input type="password" class="form-control" id="password" name="password" required autocomplete="current-password">
                </div>
                <button type="submit" class="btn btn-primary btn-lg w-100" id="btn-login" data-loading="กำลังตรวจสอบ..."><?= icon('log-in') ?> <?= e(__('เข้าสู่ระบบ')) ?></button>
            </form>
            <div class="border-top mt-4 pt-3 text-center"><a class="btn btn-outline-primary w-100" href="<?= e(url('/repair')) ?>">แจ้งซ่อมโดยไม่ต้องเข้าสู่ระบบ <?= icon('arrow-right') ?></a><p class="small text-muted mt-2 mb-0">สำหรับลูกค้าและพนักงานที่ไม่มีบัญชี</p></div>
            <p class="small text-muted mt-4 mb-0"><?= icon('shield-check', 'icon-sm') ?> การเชื่อมต่อถูกป้องกันด้วย CSRF, session ที่ปลอดภัย และการจำกัดจำนวนครั้งการเข้าสู่ระบบ</p>
        </div>
    </section>
</div>

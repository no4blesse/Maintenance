<?php
/** @var App\Core\View $this */
use App\Helpers\UI;
use App\Models\TicketStatus as S;

$qs = array_filter($f, static fn ($v) => $v !== '' && $v !== 0 && $v !== false);
$tabs = [
    '' => ['ทั้งหมด', array_sum($statusCounts)],
    'OPEN' => ['เปิดอยู่', array_sum(array_intersect_key($statusCounts, array_flip(S::open())))],
    'NEW' => ['แจ้งใหม่', $statusCounts['NEW'] ?? 0],
    'IN_PROGRESS' => ['กำลังดำเนินการ', $statusCounts['IN_PROGRESS'] ?? 0],
    'PENDING_VERIFICATION' => ['รอตรวจรับ', $statusCounts['PENDING_VERIFICATION'] ?? 0],
    'COMPLETED' => ['เสร็จสมบูรณ์', $statusCounts['COMPLETED'] ?? 0],
];
?>
<div class="page-head">
    <div>
        <h2>งานแจ้งซ่อม</h2>
        <p>ติดตามใบงานซ่อมบำรุงทั้งหมดที่คุณมีสิทธิ์เข้าถึง</p>
    </div>
    <div class="d-flex gap-2 flex-wrap">
        <?php if (can('reports.export')): ?>
            <a class="btn btn-light" href="<?= e(url('/tickets/export', $qs)) ?>" id="btn-export-tickets"><?= icon('download') ?> ส่งออก CSV</a>
        <?php endif; ?>
        <?php if (can('tickets.create')): ?>
            <a class="btn btn-accent" href="<?= e(url('/tickets/create')) ?>" id="btn-new-ticket"><?= icon('plus') ?> แจ้งซ่อมใหม่</a>
        <?php endif; ?>
    </div>
</div>

<nav class="nav-tabs-x" aria-label="ตัวกรองสถานะ">
    <?php foreach ($tabs as $code => [$label, $n]): ?>
        <a href="<?= e(url('/tickets', array_filter(['status' => $code] + array_diff_key($qs, ['status' => 1, 'page' => 1, 'bucket' => 1])))) ?>" class="<?= $f['status'] === $code && !$f['bucket'] && !$f['overdue'] ? 'active' : '' ?>">
            <?= e($label) ?> <span class="badge-soft soft-gray"><?= num($n) ?></span>
        </a>
    <?php endforeach; ?>
    <a href="<?= e(url('/tickets', ['overdue' => 1])) ?>" class="<?= $f['overdue'] ? 'active' : '' ?>"><?= icon('alarm-clock', 'icon-sm') ?> เกินกำหนด</a>
    <?php if (can('tickets.work') || can('tickets.view_own')): ?>
        <a href="<?= e(url('/tickets', ['mine' => 1, 'status' => 'OPEN'])) ?>" class="<?= $f['mine'] ? 'active' : '' ?>"><?= icon('user', 'icon-sm') ?> งานของฉัน</a>
    <?php endif; ?>
</nav>

<div class="card-x">
    <form class="filter-bar" method="get" action="<?= e(url('/tickets')) ?>" id="ticket-filter">
        <?php foreach (['status', 'overdue', 'mine', 'bucket'] as $keep): if (!empty($f[$keep])): ?>
            <input type="hidden" name="<?= e($keep) ?>" value="<?= e((string) $f[$keep]) ?>">
        <?php endif; endforeach; ?>
        <div class="grow">
            <label class="form-label small" for="f-q">ค้นหา</label>
            <input type="search" id="f-q" name="q" value="<?= e($f['q']) ?>" class="form-control form-control-sm" placeholder="เลขที่งาน, หัวข้อ, ห้อง, ผู้แจ้ง">
        </div>
        <div>
            <label class="form-label small" for="f-priority">ความเร่งด่วน</label>
            <select id="f-priority" name="priority_id" class="form-select form-select-sm" data-autosubmit>
                <option value="">ทั้งหมด</option>
                <?php foreach ($priorities as $p): ?><option value="<?= (int) $p['id'] ?>"<?= selected($p['id'], $f['priority_id']) ?>><?= e($p['name_th']) ?></option><?php endforeach; ?>
            </select>
        </div>
        <div>
            <label class="form-label small" for="f-category">หมวดงาน</label>
            <select id="f-category" name="category_id" class="form-select form-select-sm" data-autosubmit>
                <option value="">ทั้งหมด</option>
                <?php foreach ($categories as $c): ?><option value="<?= (int) $c['id'] ?>"<?= selected($c['id'], $f['category_id']) ?>><?= e($c['name_th']) ?></option><?php endforeach; ?>
            </select>
        </div>
        <div>
            <label class="form-label small" for="f-type">ประเภท</label>
            <select id="f-type" name="type" class="form-select form-select-sm" data-autosubmit>
                <option value="">ทั้งหมด</option>
                <option value="CORRECTIVE"<?= selected('CORRECTIVE', $f['type']) ?>>งานซ่อม (CM)</option>
                <option value="PREVENTIVE"<?= selected('PREVENTIVE', $f['type']) ?>>บำรุงรักษา (PM)</option>
            </select>
        </div>
        <?php if ($technicians): ?>
            <div>
                <label class="form-label small" for="f-tech">ช่าง</label>
                <select id="f-tech" name="technician_id" class="form-select form-select-sm" data-autosubmit>
                    <option value="">ทั้งหมด</option>
                    <?php foreach ($technicians as $tc): ?><option value="<?= (int) $tc['id'] ?>"<?= selected($tc['id'], $f['technician_id']) ?>><?= e($tc['name']) ?></option><?php endforeach; ?>
                </select>
            </div>
        <?php endif; ?>
        <div>
            <label class="form-label small" for="f-from">แจ้งตั้งแต่</label>
            <input type="date" id="f-from" name="from" value="<?= e($f['from']) ?>" class="form-control form-control-sm">
        </div>
        <div>
            <label class="form-label small" for="f-to">ถึง</label>
            <input type="date" id="f-to" name="to" value="<?= e($f['to']) ?>" class="form-control form-control-sm">
        </div>
        <div class="d-flex gap-1">
            <button class="btn btn-primary btn-sm" type="submit" data-keep="1"><?= icon('search', 'icon-sm') ?> ค้นหา</button>
            <a class="btn btn-light btn-sm" href="<?= e(url('/tickets')) ?>" title="ล้างตัวกรอง"><?= icon('x', 'icon-sm') ?></a>
        </div>
    </form>
    <?= $this->partial('tickets/_list', [
        'rows' => $rows, 'showHotel' => $showHotel,
        'emptyTitle' => $qs ? 'ไม่พบใบงานตามเงื่อนไข' : 'ยังไม่มีใบงานแจ้งซ่อม',
        'emptyText' => $qs ? 'ลองปรับตัวกรอง หรือเลือก "ทุกโรงแรม"' : 'เริ่มต้นด้วยการกด "แจ้งซ่อมใหม่"',
    ]) ?>
    <?php if ($rows): ?><div class="card-x-footer"><?= UI::pagination($total, $page, $per, $_GET) ?></div><?php endif; ?>
</div>

<?php
/** @var App\Core\View $this */
use App\Helpers\Time;
use App\Helpers\UI;

$s = $summary;
$delta = static function (int $now, int $before): string {
    if ($before === 0) {
        return $now > 0 ? 'ใหม่ในช่วงนี้' : 'เทียบช่วงก่อน: 0';
    }
    $pct = round(($now - $before) * 100 / $before);
    return ($pct >= 0 ? '▲ ' : '▼ ') . abs($pct) . '% จากช่วงก่อน';
};
$user = auth_user();
$q = ['from' => $from, 'to' => $to];
?>
<div class="page-head">
    <div>
        <h2>สวัสดี, <?= e($user['name'] ?? '') ?> 👋</h2>
        <p>
            ภาพรวมงานซ่อมบำรุง · <?= e($selected ? $selected['name_en'] . ' (' . $selected['name_th'] . ')' : 'ทุกโรงแรมที่คุณมีสิทธิ์') ?>
            · ช่วงวันที่แจ้ง <?= e(d($from)) ?> – <?= e(d($to)) ?>
        </p>
    </div>
    <form class="d-flex flex-wrap gap-2 align-items-end no-print" method="get" action="<?= e(url('/')) ?>">
        <div><label class="form-label small mb-1" for="dash-from">ตั้งแต่</label><input type="date" id="dash-from" name="from" value="<?= e($from) ?>" class="form-control form-control-sm"></div>
        <div><label class="form-label small mb-1" for="dash-to">ถึง</label><input type="date" id="dash-to" name="to" value="<?= e($to) ?>" class="form-control form-control-sm"></div>
        <button class="btn btn-light btn-sm" type="submit" data-keep="1"><?= icon('filter') ?> กรอง</button>
    </form>
</div>

<div class="action-panel mb-3 no-print">
    <?php if (can('tickets.create')): ?><a href="<?= e(url('/tickets/create')) ?>" class="btn btn-accent" id="qa-create"><?= icon('plus') ?> แจ้งซ่อมใหม่</a><?php endif; ?>
    <a href="<?= e(url('/tickets', ['status' => 'OPEN'])) ?>" class="btn btn-light" id="qa-open"><?= icon('clipboard-list') ?> งานที่เปิดอยู่</a>
    <?php if (can('calendar.view')): ?><a href="<?= e(url('/calendar')) ?>" class="btn btn-light" id="qa-calendar"><?= icon('calendar-days') ?> ปฏิทินงาน</a><?php endif; ?>
    <a href="<?= e(url('/tickets', ['overdue' => 1])) ?>" class="btn btn-light" id="qa-overdue"><?= icon('alarm-clock') ?> งานเกินกำหนด <?php if ($s['overdue']): ?><span class="badge-soft soft-red"><?= num($s['overdue']) ?></span><?php endif; ?></a>
    <?php if (can('reports.view')): ?><a href="<?= e(url('/reports', $q)) ?>" class="btn btn-light" id="qa-reports"><?= icon('file-bar-chart') ?> รายงาน KPI</a><?php endif; ?>
</div>

<div class="kpi-grid mb-4">
    <a class="kpi-card tone-indigo" href="<?= e(url('/tickets', $q)) ?>">
        <div class="kpi-icon"><?= icon('clipboard-list') ?></div>
        <div class="kpi-label">งานแจ้งซ่อมทั้งหมด</div>
        <div class="kpi-value"><?= num($s['total']) ?></div>
        <div class="kpi-sub"><?= e($delta($s['total'], $prev['total'])) ?></div>
    </a>
    <a class="kpi-card tone-blue" href="<?= e(url('/tickets', $q + ['status' => 'NEW'])) ?>">
        <div class="kpi-icon"><?= icon('inbox') ?></div>
        <div class="kpi-label">แจ้งใหม่ (รอรับเรื่อง)</div>
        <div class="kpi-value"><?= num($s['new']) ?></div>
        <div class="kpi-sub">ยังไม่มีผู้รับเรื่อง</div>
    </a>
    <a class="kpi-card tone-slate" href="<?= e(url('/tickets', $q + ['bucket' => 'pending'])) ?>">
        <div class="kpi-icon"><?= icon('hourglass') ?></div>
        <div class="kpi-label">รอดำเนินการ</div>
        <div class="kpi-value"><?= num($s['pending']) ?></div>
        <div class="kpi-sub">รออะไหล่/ผู้รับเหมา/อนุมัติ <?= num($s['waiting']) ?> · รอตรวจรับ <?= num($s['pending_verification']) ?></div>
    </a>
    <a class="kpi-card tone-amber" href="<?= e(url('/tickets', $q + ['status' => 'IN_PROGRESS'])) ?>">
        <div class="kpi-icon"><?= icon('wrench') ?></div>
        <div class="kpi-label">กำลังดำเนินการ</div>
        <div class="kpi-value"><?= num($s['in_progress']) ?></div>
        <div class="kpi-sub">ช่างกำลังซ่อม</div>
    </a>
    <a class="kpi-card tone-green" href="<?= e(url('/tickets', $q + ['status' => 'COMPLETED'])) ?>">
        <div class="kpi-icon"><?= icon('check-circle-2') ?></div>
        <div class="kpi-label">เสร็จสมบูรณ์</div>
        <div class="kpi-value"><?= num($s['completed']) ?></div>
        <div class="kpi-sub"><?= e($delta($s['completed'], $prev['completed'])) ?></div>
    </a>
    <a class="kpi-card tone-red" href="<?= e(url('/tickets', ['overdue' => 1])) ?>">
        <div class="kpi-icon"><?= icon('alarm-clock') ?></div>
        <div class="kpi-label">เกินกำหนด SLA</div>
        <div class="kpi-value"><?= num($s['overdue']) ?></div>
        <div class="kpi-sub">งานเปิดที่เลยเวลาเป้าหมาย</div>
    </a>
    <div class="kpi-card tone-teal">
        <div class="kpi-icon"><?= icon('gauge') ?></div>
        <div class="kpi-label">อัตราการปิดงาน</div>
        <div class="kpi-value"><?= $s['completion_rate'] === null ? '–' : e(num($s['completion_rate'], 1)) . '%' ?></div>
        <div class="kpi-sub">SLA ผ่าน <?= $s['sla_compliance'] === null ? '–' : e(num($s['sla_compliance'], 1)) . '%' ?> · MTTR <?= e(Time::duration($s['mttr'])) ?></div>
    </div>
    <?php if ($canCost): ?>
        <a class="kpi-card tone-purple" href="<?= e(url('/costs', $q)) ?>">
            <div class="kpi-icon"><?= icon('wallet') ?></div>
            <div class="kpi-label">ค่าใช้จ่ายซ่อมบำรุง (อนุมัติแล้ว)</div>
            <div class="kpi-value"><?= e(money($costTotal, 0)) ?></div>
            <div class="kpi-sub">อะไหล่ + ค่าแรง + ผู้รับเหมา + อื่น ๆ</div>
        </a>
    <?php else: ?>
        <div class="kpi-card tone-purple">
            <div class="kpi-icon"><?= icon('calendar-check') ?></div>
            <div class="kpi-label">PM ตรงเวลา</div>
            <div class="kpi-value"><?= $pm['rate'] === null ? '–' : e(num($pm['rate'], 1)) . '%' ?></div>
            <div class="kpi-sub">ครบกำหนด <?= num($pm['due']) ?> · เสร็จตรงเวลา <?= num($pm['on_time']) ?></div>
        </div>
    <?php endif; ?>
</div>

<div class="row g-3 mb-3">
    <div class="col-xl-8">
        <div class="card-x h-100">
            <div class="card-x-header"><h3><?= icon('trending-up') ?> แนวโน้มงานซ่อมรายเดือน</h3><span class="small text-muted">แจ้งซ่อม vs ปิดงาน</span></div>
            <div class="card-x-body"><div class="chart-box"><canvas data-chart="chart-trend" aria-label="กราฟแนวโน้มรายเดือน"></canvas></div></div>
        </div>
    </div>
    <div class="col-xl-4">
        <div class="card-x h-100">
            <div class="card-x-header"><h3><?= icon('pie-chart') ?> งานตามสถานะ</h3></div>
            <div class="card-x-body"><div class="chart-box"><canvas data-chart="chart-status" aria-label="กราฟสถานะงาน"></canvas></div></div>
        </div>
    </div>
</div>

<div class="row g-3 mb-3">
    <?php if ($multiHotel): ?>
        <div class="col-xl-6">
            <div class="card-x h-100">
                <div class="card-x-header"><h3><?= icon('hotel') ?> งานแยกตามโรงแรม</h3></div>
                <div class="card-x-body"><div class="chart-box"><canvas data-chart="chart-hotel"></canvas></div></div>
            </div>
        </div>
    <?php endif; ?>
    <?php if ($canCost): ?>
        <div class="col-xl-6">
            <div class="card-x h-100">
                <div class="card-x-header"><h3><?= icon('banknote') ?> ค่าใช้จ่ายแยกตามโรงแรม</h3><a class="small" href="<?= e(url('/costs/reports', $q)) ?>">รายงานต้นทุน</a></div>
                <div class="card-x-body"><div class="chart-box"><canvas data-chart="chart-cost"></canvas></div></div>
            </div>
        </div>
    <?php endif; ?>
    <div class="col-xl-<?= ($multiHotel && $canCost) ? '6' : (($multiHotel || $canCost) ? '6' : '6') ?>">
        <div class="card-x h-100">
            <div class="card-x-header"><h3><?= icon('layers') ?> หมวดงานซ่อม</h3></div>
            <div class="card-x-body"><div class="chart-box"><canvas data-chart="chart-category"></canvas></div></div>
        </div>
    </div>
    <div class="col-xl-3 col-md-6">
        <div class="card-x h-100">
            <div class="card-x-header"><h3><?= icon('siren') ?> ความเร่งด่วน</h3></div>
            <div class="card-x-body"><div class="chart-box sm"><canvas data-chart="chart-priority"></canvas></div></div>
        </div>
    </div>
    <div class="col-xl-3 col-md-6">
        <div class="card-x h-100">
            <div class="card-x-header"><h3><?= icon('shield-check') ?> PM vs CM</h3></div>
            <div class="card-x-body"><div class="chart-box sm"><canvas data-chart="chart-type"></canvas></div></div>
        </div>
    </div>
</div>

<div class="row g-3">
    <div class="col-xl-8">
        <div class="card-x">
            <div class="card-x-header">
                <h3><?= icon('activity') ?> งานแจ้งซ่อมล่าสุด</h3>
                <a href="<?= e(url('/tickets')) ?>" class="btn btn-light btn-sm">ดูทั้งหมด <?= icon('arrow-right', 'icon-sm') ?></a>
            </div>
            <?= $this->partial('tickets/_list', ['rows' => $recent, 'showHotel' => $multiHotel, 'emptyTitle' => 'ยังไม่มีงานแจ้งซ่อม', 'emptyText' => 'เมื่อมีการแจ้งซ่อม รายการจะแสดงที่นี่']) ?>
        </div>
        <?php if ($workload): ?>
            <div class="card-x">
                <div class="card-x-header"><h3><?= icon('users') ?> ภาระงานช่าง</h3><span class="small text-muted">ตามงานที่ได้รับมอบหมายในช่วงที่เลือก</span></div>
                <div class="card-x-body"><div class="chart-box sm"><canvas data-chart="chart-workload"></canvas></div></div>
            </div>
        <?php endif; ?>
    </div>
    <div class="col-xl-4">
        <?php if ($myJobs): ?>
            <div class="card-x">
                <div class="card-x-header"><h3><?= icon('hard-hat') ?> งานของฉัน</h3><a class="small" href="<?= e(url('/tickets', ['mine' => 1])) ?>">ทั้งหมด</a></div>
                <div>
                    <?php foreach ($myJobs as $t): ?>
                        <a class="ticket-card d-block" href="<?= e(url('/tickets/' . $t['id'])) ?>">
                            <div class="tc-top"><span class="mono small"><?= e($t['ticket_no']) ?></span><?= UI::status($t['status'], UI::isOverdue($t)) ?></div>
                            <div class="tc-title"><?= e($t['title']) ?></div>
                            <div class="tc-meta"><span><?= icon('map-pin', 'icon-sm') ?> <?= e($t['location_name'] ?? '-') ?></span><?= UI::priority($t['priority_name'], (int) $t['priority_level'], $t['priority_color']) ?></div>
                        </a>
                    <?php endforeach; ?>
                </div>
            </div>
        <?php endif; ?>
        <div class="card-x">
            <div class="card-x-header"><h3><?= icon('calendar-check') ?> บำรุงรักษาเชิงป้องกัน</h3></div>
            <div class="card-x-body">
                <div class="d-flex justify-content-between mb-1 small"><span>ตรงเวลา</span><strong><?= $pm['rate'] === null ? '–' : e(num($pm['rate'], 1)) . '%' ?></strong></div>
                <div class="progress mb-3"><div class="progress-bar bg-success" style="width: <?= (float) ($pm['rate'] ?? 0) ?>%"></div></div>
                <div class="row text-center g-2">
                    <div class="col-4"><div class="fw-semibold fs-5"><?= num($pm['due']) ?></div><div class="small text-muted">ครบกำหนด</div></div>
                    <div class="col-4"><div class="fw-semibold fs-5 text-success"><?= num($pm['completed']) ?></div><div class="small text-muted">เสร็จแล้ว</div></div>
                    <div class="col-4"><div class="fw-semibold fs-5 text-danger"><?= num($pm['overdue']) ?></div><div class="small text-muted">ค้างเกินกำหนด</div></div>
                </div>
            </div>
        </div>
        <?php if (can('inventory.view')): ?>
            <div class="card-x">
                <div class="card-x-header"><h3><?= icon('package-x') ?> อะไหล่ใกล้หมด</h3><a class="small" href="<?= e(url('/inventory', ['low' => 1])) ?>">ทั้งหมด</a></div>
                <?php if (!$lowStock): ?>
                    <?= UI::empty('สต็อกอยู่ในระดับปกติ', '', 'package-check') ?>
                <?php else: ?>
                    <ul class="list-unstyled m-0">
                        <?php foreach ($lowStock as $ls): ?>
                            <li class="d-flex justify-content-between align-items-center px-3 py-2 border-bottom">
                                <div class="min-w-0"><div class="fw-500 text-truncate"><?= e($ls['name']) ?></div><div class="small text-muted"><span class="mono"><?= e($ls['sku']) ?></span> · <?= UI::hotel((int) $ls['hotel_id']) ?></div></div>
                                <span class="badge-soft <?= (float) $ls['qty_on_hand'] <= 0 ? 'soft-red' : 'soft-amber' ?>"><?= e(qty($ls['qty_on_hand'])) ?> / <?= e(qty($ls['rop'])) ?> <?= e($ls['unit']) ?></span>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    </div>
</div>

<?php foreach ($charts as $id => $cfg): ?>
    <script type="application/json" id="<?= e($id) ?>"><?= UI::json($cfg) ?></script>
<?php endforeach; ?>

<?php $this->start('vendor_scripts'); ?>
<script src="<?= e(asset('vendor/chartjs/chart.umd.js')) ?>"></script>
<script src="<?= e(asset('js/charts.js')) ?>"></script>
<?php $this->stop(); ?>

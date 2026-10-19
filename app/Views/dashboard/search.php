<?php
/** @var App\Core\View $this */
use App\Helpers\UI;
use App\Services\AssetService;

$total = count($results['tickets']) + count($results['assets']) + count($results['parts']);
?>
<div class="page-head">
    <div>
        <h2>ผลการค้นหา “<?= e($q) ?>”</h2>
        <p>พบ <?= num($total) ?> รายการ (แสดงเฉพาะข้อมูลที่คุณมีสิทธิ์เข้าถึง)</p>
    </div>
    <form class="d-flex gap-2" method="get" action="<?= e(url('/search')) ?>" role="search">
        <input type="search" name="q" value="<?= e($q) ?>" class="form-control" placeholder="เลขที่งาน, หัวข้อ, ห้อง, รหัสอุปกรณ์, SKU" minlength="2" id="search-page-input" aria-label="ค้นหา">
        <button class="btn btn-primary" type="submit" data-keep="1"><?= icon('search') ?></button>
    </form>
</div>

<?php if (mb_strlen($q) < 2): ?>
    <div class="card-x"><?= UI::empty('พิมพ์อย่างน้อย 2 ตัวอักษรเพื่อค้นหา', '', 'search') ?></div>
<?php elseif ($total === 0): ?>
    <div class="card-x"><?= UI::empty('ไม่พบข้อมูลที่ตรงกับคำค้นหา', 'ลองใช้คำค้นอื่น หรือเลือก "ทุกโรงแรม" ในตัวเลือกโรงแรม', 'search-x') ?></div>
<?php else: ?>
    <?php if ($results['tickets']): ?>
        <div class="card-x">
            <div class="card-x-header"><h3><?= icon('clipboard-list') ?> ใบงานซ่อม (<?= count($results['tickets']) ?>)</h3></div>
            <?= $this->partial('tickets/_list', ['rows' => $results['tickets']]) ?>
        </div>
    <?php endif; ?>
    <?php if ($results['assets']): ?>
        <div class="card-x">
            <div class="card-x-header"><h3><?= icon('cpu') ?> อุปกรณ์ (<?= count($results['assets']) ?>)</h3></div>
            <div class="list-group list-group-flush">
                <?php foreach ($results['assets'] as $a): ?>
                    <a class="list-group-item list-group-item-action d-flex justify-content-between align-items-center" href="<?= e(url('/assets/' . $a['id'])) ?>">
                        <span><span class="mono fw-500"><?= e($a['asset_code']) ?></span> · <?= e($a['name']) ?> <span class="small text-muted">· <?= e($a['location_name'] ?? '-') ?></span></span>
                        <span class="d-flex gap-2"><?= UI::hotel((int) $a['hotel_id']) ?><?= UI::soft(AssetService::STATUSES[$a['status']] ?? $a['status'], $a['status'] === 'ACTIVE' ? 'green' : 'amber') ?></span>
                    </a>
                <?php endforeach; ?>
            </div>
        </div>
    <?php endif; ?>
    <?php if ($results['parts']): ?>
        <div class="card-x">
            <div class="card-x-header"><h3><?= icon('package') ?> อะไหล่ (<?= count($results['parts']) ?>)</h3></div>
            <div class="list-group list-group-flush">
                <?php foreach ($results['parts'] as $p): ?>
                    <a class="list-group-item list-group-item-action" href="<?= e(url('/inventory/parts/' . $p['id'])) ?>"><span class="mono fw-500"><?= e($p['sku']) ?></span> · <?= e($p['name']) ?> <span class="small text-muted">(<?= e($p['unit']) ?>)</span></a>
                <?php endforeach; ?>
            </div>
        </div>
    <?php endif; ?>
<?php endif; ?>

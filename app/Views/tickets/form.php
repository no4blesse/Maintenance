<?php
/** @var App\Core\View $this */
/** @var array|null $ticket */
use App\Helpers\Time;

$isEdit = $ticket !== null;
$val = static fn (string $k, $default = '') => old($k, $isEdit ? ($ticket[$k] ?? $default) : $default);
$defaultPriority = '';
foreach ($priorities as $p) {
    if ($p['code'] === 'NORMAL') {
        $defaultPriority = $p['id'];
    }
}
$action = $isEdit ? url('/tickets/' . $ticket['id'] . '/update') : url('/tickets');
?>
<?php if (!$isEdit): ?><div class="alert alert-info d-flex flex-wrap justify-content-between align-items-center gap-2"><span>ลูกค้าหรือพนักงานไม่มีบัญชี? ใช้หน้าแจ้งซ่อมสาธารณะได้</span><a class="btn btn-sm btn-outline-primary" href="<?= e(url('/repair'.(!empty($hotelId) ? '/'.rawurlencode((string) (\App\Core\HotelContext::hotel((int) $hotelId)['code'] ?? '')) : ''))) ?>" target="_blank" rel="noopener">เปิดหน้าแจ้งซ่อมสำหรับทุกคน <?= icon('external-link') ?></a></div><?php endif ?>
<form method="post" action="<?= e($action) ?>" enctype="multipart/form-data" id="ticket-form" novalidate>
    <?= csrf_field() ?>
    <div class="row g-3">
        <div class="col-lg-8">
            <div class="card-x">
                <div class="card-x-header"><h3><?= icon($isEdit ? 'pencil' : 'clipboard-plus') ?> <?= $isEdit ? 'แก้ไขใบแจ้งซ่อม ' . e($ticket['ticket_no']) : 'รายละเอียดการแจ้งซ่อม' ?></h3></div>
                <div class="card-x-body">
                    <div class="form-section">
                        <div class="row g-3">
                            <?php if ($isEdit): ?>
                                <input type="hidden" value="<?= (int) $ticket['hotel_id'] ?>" data-hotel-source data-perm="tickets.update">
                                <div class="col-md-6">
                                    <label class="form-label">โรงแรม</label>
                                    <div class="form-control bg-light"><?= e($ticket['hotel_name']) ?></div>
                                </div>
                            <?php else: ?>
                                <div class="col-md-6">
                                    <label class="form-label required" for="hotel_id">โรงแรม</label>
                                    <select name="hotel_id" id="hotel_id" class="form-select<?= invalid('hotel_id') ?>" data-hotel-source data-perm="tickets.create" required>
                                        <?php if (count($hotels) !== 1): ?><option value="">— เลือกโรงแรม —</option><?php endif; ?>
                                        <?php foreach ($hotels as $h): ?>
                                            <option value="<?= (int) $h['id'] ?>"<?= selected(old('hotel_id', $hotelId), $h['id']) ?>><?= e($h['code'] . ' · ' . $h['name_en']) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                    <?php if ($m = field_error('hotel_id')): ?><div class="invalid-feedback"><?= e($m) ?></div><?php endif; ?>
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label" for="requested_at">วันเวลาที่แจ้ง</label>
                                    <input type="datetime-local" name="requested_at" id="requested_at" class="form-control<?= invalid('requested_at') ?>" value="<?= e(old('requested_at', Time::inputValue(Time::now()))) ?>" max="<?= e(Time::inputValue(Time::now())) ?>">
                                </div>
                            <?php endif; ?>
                            <p class="small text-muted mb-0">เวลาเริ่มงานบันทึกเมื่อช่างกดรับเรื่อง และเวลาสิ้นสุดเมื่อแจ้งซ่อมเสร็จ</p>
                        </div>
                    </div>

                    <div class="form-section">
                        <div class="section-title">ปัญหาที่พบ</div>
                        <div class="row g-3">
                            <div class="col-12">
                                <label class="form-label required" for="title">หัวข้อปัญหา</label>
                                <input type="text" name="title" id="title" class="form-control<?= invalid('title') ?>" maxlength="200" required value="<?= e($val('title')) ?>" placeholder="เช่น เครื่องปรับอากาศไม่เย็น">
                                <?php if ($m = field_error('title')): ?><div class="invalid-feedback"><?= e($m) ?></div><?php endif; ?>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label required" for="category_id">หมวดงานซ่อม</label>
                                <select name="category_id" id="category_id" class="form-select<?= invalid('category_id') ?>" required>
                                    <option value="">— เลือกหมวดงาน —</option>
                                    <?php foreach ($categories as $c): ?>
                                        <option value="<?= (int) $c['id'] ?>"<?= selected($val('category_id'), $c['id']) ?>><?= e($c['name_th']) ?></option>
                                    <?php endforeach; ?>
                                </select>
                                <?php if ($m = field_error('category_id')): ?><div class="invalid-feedback"><?= e($m) ?></div><?php endif; ?>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label" for="asset_id">อุปกรณ์ (ถ้ามี)</label>
                                <select name="asset_id" id="asset_id" class="form-select" data-options="assets" data-searchable data-placeholder="— ไม่ระบุอุปกรณ์ —" data-selected="<?= e((string) $val('asset_id', $prefill['asset_id'] ?? '')) ?>">
                                    <option value="">— ไม่ระบุอุปกรณ์ —</option>
                                </select>
                                <?php if (!empty($prefill['asset'])): ?><div class="form-text"><?= icon('qr-code', 'icon-sm') ?> อ้างอิงจาก QR: <?= e($prefill['asset']['asset_code'] . ' ' . $prefill['asset']['name']) ?></div><?php endif; ?>
                            </div>
                            <div class="col-12">
                                <label class="form-label" for="description">รายละเอียด</label>
                                <textarea name="description" id="description" rows="4" class="form-control<?= invalid('description') ?>" maxlength="5000" placeholder="อธิบายอาการ เวลาที่พบ และข้อมูลที่ช่างควรทราบ"><?= e($val('description')) ?></textarea>
                            </div>
                        </div>
                    </div>

                    <div class="form-section">
                        <div class="section-title">สถานที่</div>
                        <div class="row g-3">
                            <div class="col-md-4">
                                <label class="form-label" for="building_id">อาคาร</label>
                                <select name="building_id" id="building_id" class="form-select" data-options="buildings" data-placeholder="— อาคาร —" data-selected="<?= e((string) $val('building_id')) ?>"><option value="">— อาคาร —</option></select>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label" for="floor_id">ชั้น</label>
                                <select name="floor_id" id="floor_id" class="form-select" data-options="floors" data-placeholder="— ชั้น —" data-selected="<?= e((string) $val('floor_id')) ?>"><option value="">— ชั้น —</option></select>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label" for="location_id">ห้อง / พื้นที่</label>
                                <select name="location_id" id="location_id" class="form-select<?= invalid('location_id') ?>" data-options="locations" data-searchable data-placeholder="— ห้อง/พื้นที่ —" data-selected="<?= e((string) $val('location_id', $prefill['location_id'] ?? '')) ?>"><option value="">— ห้อง/พื้นที่ —</option></select>
                                <?php if ($m = field_error('location_id')): ?><div class="invalid-feedback d-block"><?= e($m) ?></div><?php endif; ?>
                            </div>
                            <div class="col-12">
                                <label class="form-label" for="location_text">ตำแหน่งเพิ่มเติม</label>
                                <input type="text" name="location_text" id="location_text" class="form-control" maxlength="150" value="<?= e($val('location_text')) ?>" placeholder="เช่น ห้องน้ำฝั่งซ้าย, หน้าลิฟต์ชั้น 2 (กรณีไม่มีในรายการ)">
                            </div>
                        </div>
                    </div>

                    <?php if (!$isEdit): ?>
                        <div class="form-section">
                            <div class="section-title">รูปภาพประกอบ (ก่อนซ่อม)</div>
                            <label class="upload-drop" for="photos">
                                <?= icon('camera', 'icon-lg') ?>
                                <div class="fw-500 mt-1">ถ่ายภาพหรือเลือกรูป</div>
                                <div class="small">JPG / PNG / WEBP สูงสุด 10 รูป รูปละไม่เกิน <?= (int) $maxMb ?> MB</div>
                            </label>
                            <input type="file" name="photos[]" id="photos" class="d-none" accept="image/*" capture="environment" multiple data-preview="#photo-preview" data-max-mb="<?= (int) $maxMb ?>">
                            <div class="upload-preview" id="photo-preview"></div>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <div class="col-lg-4">
            <div class="card-x">
                <div class="card-x-header"><h3><?= icon('siren') ?> ความเร่งด่วน</h3></div>
                <div class="card-x-body">
                    <div class="d-grid gap-2" role="radiogroup" aria-label="ความเร่งด่วน">
                        <?php foreach ($priorities as $p): ?>
                            <input type="radio" class="btn-check" name="priority_id" id="prio-<?= (int) $p['id'] ?>" value="<?= (int) $p['id'] ?>"<?= checked((string) $val('priority_id', $defaultPriority) === (string) $p['id']) ?> required>
                            <label class="btn btn-light justify-content-between" for="prio-<?= (int) $p['id'] ?>" style="--bs-btn-active-bg: <?= e($p['color']) ?>1a; --bs-btn-active-border-color: <?= e($p['color']) ?>; --bs-btn-active-color: <?= e($p['color']) ?>">
                                <span class="d-flex align-items-center gap-2"><span class="hotel-dot" style="background:<?= e($p['color']) ?>;color:<?= e($p['color']) ?>"></span><?= e($p['name_th']) ?></span>
                                <small class="text-muted">SLA <?= e(App\Helpers\Time::duration((float) $p['resolution_minutes'] * 60)) ?></small>
                            </label>
                        <?php endforeach; ?>
                    </div>
                    <?php if ($m = field_error('priority_id')): ?><div class="text-danger small mt-1"><?= e($m) ?></div><?php endif; ?>
                    <div class="form-text">SLA อาจแตกต่างตามการตั้งค่าของแต่ละโรงแรม</div>

                    <hr>
                    <label class="form-label" for="guest_impact">ผลกระทบต่อแขก</label>
                    <select name="guest_impact" id="guest_impact" class="form-select">
                        <?php foreach ($impacts as $k => $lbl): ?><option value="<?= e($k) ?>"<?= selected($val('guest_impact', 'NONE'), $k) ?>><?= e($lbl) ?></option><?php endforeach; ?>
                    </select>
                    <label class="form-label mt-3" for="est_interruption_minutes">คาดว่าหยุดให้บริการ (นาที)</label>
                    <input type="number" min="0" name="est_interruption_minutes" id="est_interruption_minutes" class="form-control" value="<?= e((string) $val('est_interruption_minutes')) ?>">
                </div>
            </div>

            <div class="card-x">
                <div class="card-x-header"><h3><?= icon('user-round') ?> ผู้แจ้ง</h3></div>
                <div class="card-x-body">
                    <?php if (!$isEdit): ?>
                        <label class="form-label" for="reporter_name">ชื่อผู้แจ้ง</label>
                        <input type="text" name="reporter_name" id="reporter_name" class="form-control mb-3" maxlength="150" value="<?= e(old('reporter_name', auth_user()['name'] ?? '')) ?>">
                    <?php else: ?>
                        <div class="mb-3"><span class="text-muted small">ผู้แจ้ง</span><div class="fw-500"><?= e($ticket['reporter_name']) ?></div></div>
                    <?php endif; ?>
                    <label class="form-label" for="reporter_department_id">แผนกผู้แจ้ง</label>
                    <select name="reporter_department_id" id="reporter_department_id" class="form-select" data-options="departments" data-placeholder="— แผนก —" data-selected="<?= e((string) $val('reporter_department_id')) ?>"><option value="">— แผนก —</option></select>
                    <label class="form-label mt-3" for="notes">หมายเหตุ</label>
                    <textarea name="notes" id="notes" rows="2" class="form-control" maxlength="2000"><?= e($val('notes')) ?></textarea>
                    <?php if (can('tickets.assign')): ?>
                        <label class="form-label mt-3" for="estimated_cost">ประมาณการค่าใช้จ่าย (บาท)</label>
                        <input type="number" step="0.01" min="0" name="estimated_cost" id="estimated_cost" class="form-control" value="<?= e((string) $val('estimated_cost')) ?>">
                    <?php endif; ?>
                </div>
            </div>

            <div class="d-grid gap-2">
                <button type="submit" class="btn btn-accent btn-lg btn-touch" id="btn-submit-ticket"><?= icon($isEdit ? 'save' : 'send') ?> <?= $isEdit ? 'บันทึกการแก้ไข' : 'ส่งแจ้งซ่อม' ?></button>
                <a href="<?= e($isEdit ? url('/tickets/' . $ticket['id']) : url('/tickets')) ?>" class="btn btn-light">ยกเลิก</a>
            </div>
        </div>
    </div>
</form>

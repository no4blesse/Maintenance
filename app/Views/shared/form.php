<?php use App\Core\HotelContext; $formPrefix='form-'.substr(hash('sha256',$action),0,10).'-'; ?>
<div class="page-header"><div><h1 class="page-title"><?= e($title) ?></h1><p class="text-muted">SUNGROUP Maintenance Center</p></div></div>
<?php if ($notice): ?><div class="alert alert-info"><?= e($notice) ?></div><?php endif ?>
<div class="card"><div class="card-body">
<form method="post" action="<?= e(url($action)) ?>" enctype="multipart/form-data" class="row g-3" <?= preg_match('#/(status|toggle|reject|cancel|receive|approve)$#',$action)?'data-confirm="ยืนยันการดำเนินการนี้?"':'' ?>>
<?= csrf_field() ?>
<?php foreach ($fields as $name => $def):
    [$caption, $type] = $def; $opts = $def[2] ?? []; $required = $def[3] ?? false;
    $value = old($name, $values[$name] ?? ($name === 'hotel_id' ? HotelContext::selectedId() : ''));
?>
<div class="<?= $type === 'textarea' ? 'col-12' : 'col-md-6' ?>">
<label class="form-label" for="<?= e($formPrefix.$name) ?>"><?= e(__($caption)) ?><?= $required ? ' *' : '' ?></label>
<?php if ($type === 'select'): ?>
<select class="form-select<?= invalid($name) ?>" name="<?= e($name) ?>" id="<?= e($formPrefix.$name) ?>" <?= $name === 'hotel_id' && count($fields)>3 ? 'data-module-hotel-picker' : '' ?> <?= $required ? 'required' : '' ?>>
<option value="">— เลือก —</option><?php foreach ($opts as $k => $v): ?><option value="<?= e($k) ?>" <?= selected($value, $k) ?>><?= e($v) ?></option><?php endforeach ?></select>
<?php elseif ($type === 'textarea'): ?>
<textarea class="form-control<?= invalid($name) ?>" rows="4" id="<?= e($formPrefix.$name) ?>" name="<?= e($name) ?>" <?= $required ? 'required' : '' ?>><?= e($value) ?></textarea>
<?php else: ?>
<input class="form-control<?= invalid($name) ?>" type="<?= e($type) ?>" name="<?= e($name) ?>" id="<?= e($formPrefix.$name) ?>" <?= $type === 'file' || $type === 'password' ? '' : 'value="' . e($value) . '"' ?> <?= $type === 'number' ? 'step="0.01"' : '' ?> <?= $required ? 'required' : '' ?> autocomplete="<?= $type === 'password' ? 'new-password' : 'off' ?>">
<?php endif ?>
<?php if ($err = field_error($name)): ?><div class="text-danger small"><?= e($err) ?></div><?php endif ?>
</div><?php endforeach ?>
<div class="col-12"><button class="btn btn-primary" type="submit"><?= icon('save') ?> บันทึก</button></div>
</form></div></div>

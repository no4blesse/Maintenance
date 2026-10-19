<?php
/** @var App\Core\View $this */
use App\Helpers\UI;

$icons = ['TICKET_CREATED' => 'inbox', 'TICKET_ASSIGNED' => 'user-plus', 'TICKET_COMPLETED' => 'badge-check', 'TICKET_SUBMITTED' => 'check-check', 'TICKET_REJECTED' => 'undo-2', 'TICKET_REOPENED' => 'rotate-ccw', 'TICKET_COMMENT' => 'message-square', 'TICKET_APPROVAL' => 'stamp', 'EXPENSE_APPROVAL' => 'wallet', 'TRANSFER' => 'arrow-left-right', 'TICKET_OVERDUE' => 'alarm-clock', 'STOCK_LOW' => 'package-x', 'PM_UPCOMING' => 'calendar-check'];
?>
<div class="page-head">
    <div><h2>การแจ้งเตือน</h2><p>ทั้งหมด <?= num($total) ?> รายการ</p></div>
    <?php if ($rows): ?>
        <form method="post" action="<?= e(url('/notifications/read-all')) ?>">
            <?= csrf_field() ?>
            <button class="btn btn-light" type="submit" id="btn-read-all"><?= icon('check-check') ?> อ่านทั้งหมด</button>
        </form>
    <?php endif; ?>
</div>
<div class="card-x">
    <?php if (!$rows): ?>
        <?= UI::empty('ยังไม่มีการแจ้งเตือน', 'การแจ้งเตือนเกี่ยวกับงานของคุณจะปรากฏที่นี่', 'bell') ?>
    <?php else: ?>
        <div class="list-group list-group-flush">
            <?php foreach ($rows as $n): ?>
                <a href="<?= e(url('/notifications/' . $n['id'] . '/open')) ?>" class="list-group-item list-group-item-action d-flex gap-3 py-3 <?= $n['read_at'] ? '' : 'bg-primary-subtle bg-opacity-25' ?>">
                    <span class="avatar <?= $n['read_at'] ? '' : 'bg-primary text-white' ?>"><?= icon($icons[$n['type']] ?? 'bell') ?></span>
                    <span class="flex-grow-1 min-w-0">
                        <span class="d-flex justify-content-between gap-2"><strong class="text-truncate"><?= e($n['title']) ?></strong><small class="text-muted text-nowrap"><?= e(ago($n['created_at'])) ?></small></span>
                        <?php if ($n['body']): ?><span class="d-block small text-muted text-truncate"><?= e($n['body']) ?></span><?php endif; ?>
                        <?php if ($n['hotel_id']): ?><span class="d-block mt-1"><?= UI::hotel((int) $n['hotel_id']) ?></span><?php endif; ?>
                    </span>
                </a>
            <?php endforeach; ?>
        </div>
        <div class="card-x-footer"><?= UI::pagination($total, $page, $per, $_GET) ?></div>
    <?php endif; ?>
</div>

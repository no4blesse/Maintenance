<?php
/** @var App\Core\View $this */
/** @var list<array> $rows */
use App\Helpers\UI;

$showHotel = $showHotel ?? true;
$emptyTitle = $emptyTitle ?? 'ยังไม่มีใบงาน';
$emptyText = $emptyText ?? '';
?>
<?php if (!$rows): ?>
    <?= UI::empty($emptyTitle, $emptyText, 'clipboard-list') ?>
<?php else: ?>
    <div class="table-responsive table-desktop">
        <table class="table table-modern align-middle">
            <thead>
            <tr>
                <th>เลขที่งาน</th>
                <?php if ($showHotel): ?><th>โรงแรม</th><?php endif; ?>
                <th>รายการ</th>
                <th>ความเร่งด่วน</th>
                <th>สถานะ</th>
                <th>ผู้รับผิดชอบ</th>
                <th>วันที่แจ้ง</th>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($rows as $t): $od = UI::isOverdue($t); ?>
                <tr class="row-link" data-href="<?= e(url('/tickets/' . $t['id'])) ?>">
                    <td><a href="<?= e(url('/tickets/' . $t['id'])) ?>" class="mono fw-500"><?= e($t['ticket_no']) ?></a>
                        <?php if (($t['ticket_type'] ?? '') === 'PREVENTIVE'): ?><div><span class="badge-soft soft-teal">PM</span></div><?php endif; ?>
                    </td>
                    <?php if ($showHotel): ?><td><?= UI::hotel((int) $t['hotel_id']) ?></td><?php endif; ?>
                    <td class="min-w-0" style="max-width:340px">
                        <div class="cell-title text-truncate"><?= e($t['title']) ?></div>
                        <div class="cell-sub text-truncate"><?= icon('map-pin', 'icon-sm') ?> <?= e($t['location_name'] ?? $t['location_text'] ?? '-') ?></div>
                    </td>
                    <td><?= UI::priority($t['priority_name'] ?? '', (int) ($t['priority_level'] ?? 1), (string) ($t['priority_color'] ?? '#64748B')) ?></td>
                    <td><?= UI::status((string) $t['status'], $od) ?></td>
                    <td>
                        <?php if (!empty($t['assignee_name'])): ?>
                            <span class="d-inline-flex align-items-center gap-2"><span class="avatar avatar-sm"><?= e(UI::initials($t['assignee_name'])) ?></span><span class="small"><?= e($t['assignee_name']) ?></span></span>
                        <?php else: ?><span class="text-muted small">รอมอบหมาย</span><?php endif; ?>
                    </td>
                    <td class="small text-muted text-nowrap"><?= e(dt($t['requested_at'] ?? null)) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <div class="ticket-cards">
        <?php foreach ($rows as $t): $od = UI::isOverdue($t); ?>
            <a class="ticket-card" href="<?= e(url('/tickets/' . $t['id'])) ?>">
                <div class="tc-top">
                    <span class="mono small fw-500"><?= e($t['ticket_no']) ?></span>
                    <?= UI::status((string) $t['status'], $od) ?>
                </div>
                <div class="tc-title"><?= e($t['title']) ?></div>
                <div class="tc-meta">
                    <?php if ($showHotel): ?><?= UI::hotel((int) $t['hotel_id']) ?><?php endif; ?>
                    <span><?= icon('map-pin', 'icon-sm') ?> <?= e($t['location_name'] ?? $t['location_text'] ?? '-') ?></span>
                    <?= UI::priority($t['priority_name'] ?? '', (int) ($t['priority_level'] ?? 1), (string) ($t['priority_color'] ?? '#64748B')) ?>
                    <span><?= icon('user', 'icon-sm') ?> <?= e($t['assignee_name'] ?? 'รอมอบหมาย') ?></span>
                    <span><?= icon('clock', 'icon-sm') ?> <?= e(ago($t['requested_at'] ?? null)) ?></span>
                </div>
            </a>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Auth;
use App\Core\Database;
use App\Core\Exceptions\BusinessRuleException;
use App\Core\Exceptions\ForbiddenException;
use App\Core\Exceptions\NotFoundException;
use App\Core\Exceptions\ValidationException;
use App\Core\Logger;
use App\Core\Permissions;
use App\Helpers\Time;
use App\Models\TicketStatus;
use DateTimeImmutable;

/**
 * Preventive maintenance plans and generation of PM work orders.
 *
 * Generation (cron/generate_pm.php) is idempotent:
 *   - each plan row is locked FOR UPDATE while generating
 *   - UNIQUE(pm_plan_id, due_date) on pm_work_orders is the final guard
 *   - next_due_date advances only in the same transaction
 * Catch-up policy: if several periods were missed (cron down / plan created
 * with an old start date) ONLY the most recent due occurrence is generated;
 * older missed occurrences are skipped and logged, avoiding work-order floods.
 */
final class PMService
{
    public const FREQUENCIES = [
        'DAILY' => 'รายวัน', 'WEEKLY' => 'รายสัปดาห์', 'MONTHLY' => 'รายเดือน', 'QUARTERLY' => 'รายไตรมาส',
        'SEMIANNUAL' => 'ทุก 6 เดือน', 'YEARLY' => 'รายปี', 'CUSTOM' => 'กำหนดเอง (วัน)',
    ];

    /**
     * Next occurrence after $date. Month-based frequencies keep the anchor
     * day-of-month (from the plan start date), clamped to the month length
     * (e.g. anchor 31 -> 30 Apr, 28/29 Feb).
     */
    public static function nextDate(string $date, string $frequency, ?int $intervalDays = null, ?int $anchorDay = null): string
    {
        $d = new DateTimeImmutable($date);
        $months = match ($frequency) {
            'MONTHLY' => 1, 'QUARTERLY' => 3, 'SEMIANNUAL' => 6, 'YEARLY' => 12, default => 0,
        };
        if ($months > 0) {
            $anchor = $anchorDay ?: (int) $d->format('j');
            $first = $d->modify('first day of this month')->modify("+{$months} months");
            $day = min($anchor, (int) $first->format('t'));
            return $first->setDate((int) $first->format('Y'), (int) $first->format('n'), $day)->format('Y-m-d');
        }
        $days = match ($frequency) {
            'DAILY' => 1, 'WEEKLY' => 7, 'CUSTOM' => max(1, (int) $intervalDays), default => throw new \InvalidArgumentException('Unknown frequency'),
        };
        return $d->modify("+{$days} days")->format('Y-m-d');
    }

    // ------------------------------------------------------------------ plans

    /** @param list<array{label:string, value_type?:string, unit?:?string}> $items */
    public static function savePlan(?int $id, int $hotelId, array $d, array $items, array $opts = []): int
    {
        $system = !empty($opts['system']);
        if (!$system && !Auth::gate()->can('pm.manage', $hotelId)) {
            throw new ForbiddenException();
        }
        $freq = (string) $d['frequency'];
        if (!isset(self::FREQUENCIES[$freq])) {
            throw new ValidationException(['frequency' => 'ความถี่ไม่ถูกต้อง']);
        }
        $interval = $freq === 'CUSTOM' ? (int) ($d['interval_days'] ?? 0) : null;
        if ($freq === 'CUSTOM' && ($interval < 1 || $interval > 3650)) {
            throw new ValidationException(['interval_days' => 'กรุณาระบุจำนวนวัน 1–3650']);
        }
        $assetId = !empty($d['asset_id']) ? (int) $d['asset_id'] : null;
        if ($assetId && !Database::value("SELECT 1 FROM assets WHERE id = ? AND hotel_id = ? AND deleted_at IS NULL AND status <> 'DISPOSED'", [$assetId, $hotelId])) {
            throw new ValidationException(['asset_id' => 'อุปกรณ์ไม่อยู่ในโรงแรมนี้หรือจำหน่ายออกแล้ว']);
        }
        if (!Database::value('SELECT 1 FROM maintenance_categories WHERE id = ?', [(int) $d['category_id']])) {
            throw new ValidationException(['category_id' => 'หมวดงานไม่ถูกต้อง']);
        }
        TicketService::priority((int) $d['priority_id']);
        $assignee = !empty($d['assigned_to']) ? (int) $d['assigned_to'] : null;
        if ($assignee && !(new Permissions($assignee))->can('tickets.work', $hotelId)) {
            throw new ValidationException(['assigned_to' => 'ผู้ใช้นี้ไม่ใช่ช่างของโรงแรมนี้']);
        }
        $items = array_values(array_filter(array_map(static function ($it) {
            $label = trim((string) ($it['label'] ?? ''));
            if ($label === '') {
                return null;
            }
            $type = in_array($it['value_type'] ?? 'CHECK', ['CHECK', 'NUMBER', 'TEXT'], true) ? $it['value_type'] : 'CHECK';
            return ['id' => !empty($it['id']) ? (int) $it['id'] : null, 'label' => mb_substr($label, 0, 255), 'value_type' => $type, 'unit' => ($it['unit'] ?? '') !== '' ? mb_substr((string) $it['unit'], 0, 20) : null];
        }, $items)));
        $data = [
            'asset_id' => $assetId,
            'category_id' => (int) $d['category_id'],
            'priority_id' => (int) $d['priority_id'],
            'task_name' => mb_substr(trim((string) $d['task_name']), 0, 200),
            'description' => ($d['description'] ?? '') !== '' ? (string) $d['description'] : null,
            'frequency' => $freq,
            'interval_days' => $interval,
            'planned_minutes' => max(5, min(10080, (int) ($d['planned_minutes'] ?? 60))),
            'assigned_to' => $assignee,
            'lead_days' => max(0, min(60, (int) ($d['lead_days'] ?? 0))),
        ];

        return Database::transaction(function () use ($id, $hotelId, $d, $data, $items, $opts) {
            if ($id === null) {
                $start = (string) $d['start_date'];
                $code = (string) Database::value('SELECT code FROM hotels WHERE id = ?', [$hotelId]);
                $data += [
                    'hotel_id' => $hotelId,
                    'plan_no' => sprintf('PM-%s-%05d', $code, NumberSequence::next('pm_plan', $hotelId)),
                    'start_date' => $start,
                    'next_due_date' => $start,
                    'is_active' => 1,
                    'is_demo' => !empty($opts['is_demo']) ? 1 : 0,
                    'created_by' => Auth::id(),
                    'created_at' => Time::now(),
                ];
                $id = Database::insert('pm_plans', $data);
                Audit::log('pm.create', 'pm_plan', $id, $hotelId, null, ['task' => $data['task_name'], 'frequency' => $data['frequency']]);
            } else {
                $plan = Database::first('SELECT * FROM pm_plans WHERE id = ? AND hotel_id = ? FOR UPDATE', [$id, $hotelId]);
                if (!$plan) {
                    throw new NotFoundException();
                }
                if (!empty($d['next_due_date']) && $d['next_due_date'] !== $plan['next_due_date']) {
                    if ($plan['last_generated_due'] && $d['next_due_date'] <= $plan['last_generated_due']) {
                        throw new ValidationException(['next_due_date' => 'วันครบกำหนดถัดไปต้องหลังรอบที่สร้างงานไปแล้ว (' . $plan['last_generated_due'] . ')']);
                    }
                    $data['next_due_date'] = $d['next_due_date'];
                }
                Database::update('pm_plans', $data, 'id = ?', [$id]);
                Audit::log('pm.update', 'pm_plan', $id, $hotelId, null, $data);
            }
            // Checklist: keep history-referenced items (deactivate instead of delete)
            $keep = [];
            foreach ($items as $i => $it) {
                if ($it['id'] && Database::value('SELECT 1 FROM pm_checklist_items WHERE id = ? AND pm_plan_id = ?', [$it['id'], $id])) {
                    Database::update('pm_checklist_items', ['label' => $it['label'], 'value_type' => $it['value_type'], 'unit' => $it['unit'], 'sort_order' => $i, 'is_active' => 1], 'id = ?', [$it['id']]);
                    $keep[] = $it['id'];
                } else {
                    $keep[] = Database::insert('pm_checklist_items', ['pm_plan_id' => $id, 'sort_order' => $i, 'label' => $it['label'], 'value_type' => $it['value_type'], 'unit' => $it['unit']]);
                }
            }
            Database::execute(
                'UPDATE pm_checklist_items SET is_active = 0 WHERE pm_plan_id = ?' . ($keep ? ' AND id NOT IN (' . Database::placeholders($keep) . ')' : ''),
                [$id, ...$keep]
            );
            return (int) $id;
        });
    }

    public static function toggle(int $id): bool
    {
        $p = Database::first('SELECT * FROM pm_plans WHERE id = ?', [$id]);
        if (!$p) {
            throw new NotFoundException();
        }
        if (!Auth::gate()->can('pm.manage', (int) $p['hotel_id'])) {
            throw new ForbiddenException();
        }
        $new = (int) $p['is_active'] === 1 ? 0 : 1;
        Database::update('pm_plans', ['is_active' => $new], 'id = ?', [$id]);
        Audit::log('pm.toggle', 'pm_plan', $id, (int) $p['hotel_id'], ['is_active' => $p['is_active']], ['is_active' => $new]);
        return (bool) $new;
    }

    // ------------------------------------------------------------------ generation

    /**
     * Generate due work orders. Returns stats. $planId limits to one plan (manual "generate now").
     * @return array{created:int, skipped_periods:int, errors:int}
     */
    public static function generateDue(?int $planId = null, ?string $today = null, bool $force = false): array
    {
        $today ??= Time::today();
        $stats = ['created' => 0, 'skipped_periods' => 0, 'errors' => 0];
        $sql = 'SELECT id FROM pm_plans WHERE is_active = 1 AND ' . ($force ? '1=1' : 'DATE_SUB(next_due_date, INTERVAL lead_days DAY) <= ?');
        $params = $force ? [] : [$today];
        if ($planId !== null) {
            $sql .= ' AND id = ?';
            $params[] = $planId;
        }
        foreach (Database::column($sql . ' ORDER BY next_due_date, id', $params) as $pid) {
            try {
                $r = self::generateForPlan((int) $pid, $today, $force);
                $stats['created'] += $r['created'];
                $stats['skipped_periods'] += $r['skipped'];
            } catch (\Throwable $e) {
                $stats['errors']++;
                Logger::error('PM generation failed', ['plan' => $pid, 'error' => $e->getMessage()]);
                if ($planId !== null) {
                    throw $e;
                }
            }
        }
        return $stats;
    }

    /** @return array{created:int, skipped:int, ticket_id:?int} */
    private static function generateForPlan(int $planId, string $today, bool $force): array
    {
        return Database::transaction(function () use ($planId, $today, $force) {
            $p = Database::first('SELECT * FROM pm_plans WHERE id = ? FOR UPDATE', [$planId]);
            if (!$p || (int) $p['is_active'] !== 1) {
                return ['created' => 0, 'skipped' => 0, 'ticket_id' => null];
            }
            $anchor = (int) substr((string) $p['start_date'], 8, 2);
            $due = (string) $p['next_due_date'];
            $leadDate = (new DateTimeImmutable($due))->modify('-' . (int) $p['lead_days'] . ' days')->format('Y-m-d');
            if (!$force && $leadDate > $today) {
                return ['created' => 0, 'skipped' => 0, 'ticket_id' => null]; // re-checked under lock
            }
            // Catch-up: jump to the latest occurrence that is <= today
            $skipped = 0;
            while (true) {
                $next = self::nextDate($due, (string) $p['frequency'], $p['interval_days'] !== null ? (int) $p['interval_days'] : null, $anchor);
                if ($next > $today) {
                    break;
                }
                $due = $next;
                $skipped++;
                if ($skipped > 5000) {
                    break;
                }
            }
            if ($skipped > 0) {
                Logger::info('PM catch-up skipped missed occurrences', ['plan' => $p['plan_no'], 'skipped' => $skipped, 'generated_due' => $due]);
            }
            if (Database::value('SELECT 1 FROM pm_work_orders WHERE pm_plan_id = ? AND due_date = ?', [$planId, $due])) {
                // Already generated – only advance the pointer
                Database::update('pm_plans', ['next_due_date' => self::nextDate($due, (string) $p['frequency'], $p['interval_days'] !== null ? (int) $p['interval_days'] : null, $anchor), 'last_generated_due' => $due], 'id = ?', [$planId]);
                return ['created' => 0, 'skipped' => $skipped, 'ticket_id' => null];
            }
            $hotelId = (int) $p['hotel_id'];
            $asset = $p['asset_id'] ? Database::first('SELECT * FROM assets WHERE id = ?', [$p['asset_id']]) : null;
            $scheduledStart = Time::toUtc($due . ' 08:00');
            $scheduledEnd = Time::addMinutes((string) $scheduledStart, (int) $p['planned_minutes']);
            $assignee = $p['assigned_to'] && (new Permissions((int) $p['assigned_to']))->can('tickets.work', $hotelId) ? (int) $p['assigned_to'] : null;
            $checklist = Database::column('SELECT label FROM pm_checklist_items WHERE pm_plan_id = ? AND is_active = 1 ORDER BY sort_order, id', [$planId]);
            $ticketId = TicketService::create($hotelId, [
                'title' => 'PM: ' . $p['task_name'] . ($asset ? ' (' . $asset['asset_code'] . ')' : ''),
                'description' => trim(($p['description'] ?? '') . ($checklist ? "\n\nรายการตรวจเช็ค:\n- " . implode("\n- ", $checklist) : '')),
                'category_id' => (int) $p['category_id'],
                'priority_id' => (int) $p['priority_id'],
                'asset_id' => $asset ? (int) $asset['id'] : null,
                'reporter_name' => 'ระบบ PM (' . $p['plan_no'] . ')',
                'guest_impact' => 'NONE',
            ], [], [
                'system' => true,
                'ticket_type' => 'PREVENTIVE',
                'scheduled_start' => $scheduledStart,
                'scheduled_end' => $scheduledEnd,
                'assigned_to' => $assignee,
                'is_demo' => (int) $p['is_demo'] === 1,
            ]);
            $workOrderId = Database::insert('pm_work_orders', [
                'hotel_id' => $hotelId, 'pm_plan_id' => $planId, 'ticket_id' => $ticketId, 'due_date' => $due,
                'status' => 'OPEN', 'created_at' => Time::now(),
            ]);
            Database::execute('INSERT INTO pm_work_order_checklist (pm_work_order_id,checklist_item_id,label,value_type,unit,sort_order) SELECT ?,id,label,value_type,unit,sort_order FROM pm_checklist_items WHERE pm_plan_id=? AND is_active=1', [$workOrderId, $planId]);
            Database::update('pm_plans', [
                'next_due_date' => self::nextDate($due, (string) $p['frequency'], $p['interval_days'] !== null ? (int) $p['interval_days'] : null, $anchor),
                'last_generated_due' => $due,
            ], 'id = ?', [$planId]);
            if ($asset) {
                TicketService::assetHistory((int) $asset['id'], $hotelId, 'PM_SCHEDULED', 'สร้างใบงาน PM ครบกำหนด ' . $due . ' (' . $p['plan_no'] . ')', $ticketId, null);
            }
            return ['created' => 1, 'skipped' => $skipped, 'ticket_id' => $ticketId];
        });
    }

    // ------------------------------------------------------------------ execution

    public static function workOrder(int $woId): array
    {
        $wo = Database::first(
            'SELECT w.*, p.plan_no, p.task_name, p.asset_id, t.status AS ticket_status, t.assigned_to, t.ticket_no
             FROM pm_work_orders w JOIN pm_plans p ON p.id = w.pm_plan_id JOIN tickets t ON t.id = w.ticket_id WHERE w.id = ?',
            [$woId]
        );
        if (!$wo) {
            throw new NotFoundException();
        }
        return $wo;
    }

    private static function assertCanWork(array $wo): void
    {
        $g = Auth::gate();
        $hotelId = (int) $wo['hotel_id'];
        $isAssignee = (int) $wo['assigned_to'] === (int) Auth::id();
        if (!($isAssignee && $g->can('tickets.work', $hotelId)) && !$g->can('tickets.assign', $hotelId)) {
            throw new ForbiddenException('บันทึกผลตรวจได้เฉพาะช่างผู้รับผิดชอบหรือหัวหน้าช่าง');
        }
    }

    /** @param array<int, array{done?:mixed, value?:string, comment?:string, defect?:mixed}> $results keyed by checklist item id */
    public static function saveChecklist(int $woId, array $results): int
    {
        $wo = self::workOrder($woId);
        self::assertCanWork($wo);
        if (!in_array($wo['ticket_status'], TicketStatus::WORKABLE, true)) {
            throw new BusinessRuleException('บันทึกผลตรวจได้เมื่องานอยู่ระหว่างดำเนินการเท่านั้น (กรุณากด "เริ่มดำเนินการ" ก่อน)');
        }
        $items = Database::select('SELECT checklist_item_id AS id, label, value_type FROM pm_work_order_checklist WHERE pm_work_order_id = ?', [$woId]);
        $byId = array_column($items, null, 'id');
        return Database::transaction(function () use ($wo, $results, $byId) {
            Database::first('SELECT id FROM tickets WHERE id = ? FOR UPDATE', [$wo['ticket_id']]);
            $wo = self::workOrder((int) $wo['id']);
            self::assertCanWork($wo);
            if (!in_array($wo['ticket_status'], TicketStatus::WORKABLE, true)) {
                throw new BusinessRuleException('ใบงานนี้ไม่อยู่ในสถานะที่บันทึกผลตรวจได้');
            }
            $n = 0;
            $now = Time::now();
            $defects = [];
            foreach ($results as $itemId => $r) {
                if (!is_array($r)) { throw new ValidationException(['results' => 'รูปแบบผลตรวจไม่ถูกต้อง']); }
                $itemId = (int) $itemId;
                if (!isset($byId[$itemId])) {
                    continue; // ignore items from other plans (tamper-proof)
                }
                $value = mb_substr(trim((string) ($r['value'] ?? '')), 0, 255);
                if ($byId[$itemId]['value_type'] === 'NUMBER' && $value !== '' && !is_numeric($value)) {
                    throw new ValidationException(['results' => 'ค่าของ "' . $byId[$itemId]['label'] . '" ต้องเป็นตัวเลข']);
                }
                $defect = !empty($r['defect']) ? 1 : 0;
                Database::execute(
                    'INSERT INTO pm_checklist_results (pm_work_order_id, checklist_item_id, is_done, value_text, comment, is_defect, recorded_by, recorded_at)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?)
                     ON DUPLICATE KEY UPDATE is_done = VALUES(is_done), value_text = VALUES(value_text), comment = VALUES(comment),
                       is_defect = VALUES(is_defect), recorded_by = VALUES(recorded_by), recorded_at = VALUES(recorded_at)',
                    [$wo['id'], $itemId, !empty($r['done']) ? 1 : 0, $value !== '' ? $value : null, ($r['comment'] ?? '') !== '' ? mb_substr((string) $r['comment'], 0, 500) : null, $defect, Auth::id(), $now]
                );
                if ($defect) {
                    $defects[] = $byId[$itemId]['label'];
                }
                $n++;
            }
            TicketService::log((int) $wo['ticket_id'], (int) $wo['hotel_id'], $wo['ticket_status'], $wo['ticket_status'], 'pm_checklist',
                'บันทึกผลตรวจ PM ' . $n . ' รายการ' . ($defects ? ' · พบข้อบกพร่อง: ' . implode(', ', $defects) : ''), Auth::id());
            return $n;
        });
    }

    /** Creates a corrective ticket from a PM inspection finding, linked to the same asset. */
    public static function reportDefect(int $woId, string $title, string $description, int $priorityId, array $photos = []): int
    {
        $wo = self::workOrder($woId);
        $hotelId = (int) $wo['hotel_id'];
        if (!Auth::gate()->can('tickets.create', $hotelId)) {
            throw new ForbiddenException();
        }
        if (mb_strlen(trim($title)) < 3) {
            throw new ValidationException(['title' => 'กรุณาระบุหัวข้อปัญหา']);
        }
        $pm = Database::first('SELECT t.category_id, t.location_id, t.building_id, t.floor_id FROM tickets t WHERE t.id = ?', [$wo['ticket_id']]);
        $id = TicketService::create($hotelId, [
            'title' => $title,
            'description' => trim($description . "\n\nพบระหว่างตรวจ PM: " . $wo['ticket_no'] . ' (' . $wo['plan_no'] . ')'),
            'category_id' => (int) $pm['category_id'],
            'priority_id' => $priorityId,
            'asset_id' => $wo['asset_id'] ? (int) $wo['asset_id'] : null,
            'location_id' => $pm['location_id'], 'building_id' => $pm['building_id'], 'floor_id' => $pm['floor_id'],
            'guest_impact' => 'NONE',
        ], $photos);
        TicketService::log((int) $wo['ticket_id'], $hotelId, $wo['ticket_status'], $wo['ticket_status'], 'pm_defect', 'เปิดงานซ่อมจากการตรวจ PM: ' . Database::value('SELECT ticket_no FROM tickets WHERE id = ?', [$id]), Auth::id());
        return $id;
    }
}

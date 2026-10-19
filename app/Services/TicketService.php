<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Auth;
use App\Core\Database;
use App\Core\Exceptions\BusinessRuleException;
use App\Core\Exceptions\ForbiddenException;
use App\Core\Exceptions\NotFoundException;
use App\Core\Exceptions\ValidationException;
use App\Core\Permissions;
use App\Helpers\Dec;
use App\Helpers\Time;
use App\Models\TicketStatus as S;

/**
 * Work-order domain logic. Controllers validate input shape; this service
 * enforces tenant integrity, authorization and business rules, and keeps
 * every change inside a DB transaction with a status log entry.
 */
final class TicketService
{
    public const GUEST_IMPACT = ['NONE' => 'ไม่กระทบแขก', 'LOW' => 'กระทบเล็กน้อย', 'HIGH' => 'กระทบแขกมาก', 'ROOM_OUT_OF_ORDER' => 'ห้องปิดใช้งาน (OOO)'];

    // ------------------------------------------------------------------ lookups

    public static function priority(int $id): array
    {
        $p = Database::first('SELECT * FROM priorities WHERE id = ? AND is_active = 1', [$id]);
        if (!$p) {
            throw new ValidationException(['priority_id' => 'ระดับความเร่งด่วนไม่ถูกต้อง']);
        }
        return $p;
    }

    /** @return array{0:int,1:int} response & resolution minutes for hotel/priority */
    public static function slaMinutes(int $hotelId, int $priorityId): array
    {
        $r = Database::first('SELECT response_minutes, resolution_minutes FROM sla_rules WHERE hotel_id = ? AND priority_id = ?', [$hotelId, $priorityId])
            ?? Database::first('SELECT response_minutes, resolution_minutes FROM priorities WHERE id = ?', [$priorityId]);
        return [(int) ($r['response_minutes'] ?? 0), (int) ($r['resolution_minutes'] ?? 0)];
    }

    /**
     * Validates that optional location references belong to $hotelId and
     * fills building/floor from the most specific reference.
     * @return array{building_id:?int,floor_id:?int,location_id:?int}
     */
    public static function resolveLocation(int $hotelId, ?int $buildingId, ?int $floorId, ?int $locationId): array
    {
        if ($locationId) {
            $l = Database::first('SELECT building_id, floor_id FROM locations WHERE id = ? AND hotel_id = ?', [$locationId, $hotelId]);
            if (!$l) {
                throw new ValidationException(['location_id' => 'ห้อง/พื้นที่ไม่อยู่ในโรงแรมที่เลือก']);
            }
            $buildingId = $l['building_id'] !== null ? (int) $l['building_id'] : $buildingId;
            $floorId = $l['floor_id'] !== null ? (int) $l['floor_id'] : $floorId;
        }
        if ($floorId) {
            $f = Database::first('SELECT building_id FROM floors WHERE id = ? AND hotel_id = ?', [$floorId, $hotelId]);
            if (!$f) {
                throw new ValidationException(['floor_id' => 'ชั้นไม่อยู่ในโรงแรมที่เลือก']);
            }
            $buildingId = (int) $f['building_id'];
        }
        if ($buildingId && !Database::value('SELECT 1 FROM buildings WHERE id = ? AND hotel_id = ?', [$buildingId, $hotelId])) {
            throw new ValidationException(['building_id' => 'อาคารไม่อยู่ในโรงแรมที่เลือก']);
        }
        return ['building_id' => $buildingId ?: null, 'floor_id' => $floorId ?: null, 'location_id' => $locationId ?: null];
    }

    /** Ticket with joined display fields (no authorization – callers must check). */
    public static function load(int $id): array
    {
        $t = Database::first(
            "SELECT t.*, h.code AS hotel_code, h.name_en AS hotel_name, h.name_th AS hotel_name_th, h.accent_color,
                    mc.name_th AS category_name, mc.icon AS category_icon, mc.color AS category_color,
                    p.code AS priority_code, p.name_th AS priority_name, p.level AS priority_level, p.color AS priority_color,
                    b.name AS building_name, f.name AS floor_name, l.name AS location_name, l.code AS location_code,
                    a.asset_code, a.name AS asset_name, a.status AS asset_status,
                    d.name_th AS department_name, ua.name AS assignee_name, ua.phone AS assignee_phone,
                    ur.email AS reporter_email, ur.phone AS reporter_phone, uv.name AS verifier_name,
                    pw.id AS pm_work_order_id, pw.pm_plan_id, pw.due_date AS pm_due_date
             FROM tickets t
             JOIN hotels h ON h.id = t.hotel_id
             JOIN maintenance_categories mc ON mc.id = t.category_id
             JOIN priorities p ON p.id = t.priority_id
             LEFT JOIN buildings b ON b.id = t.building_id
             LEFT JOIN floors f ON f.id = t.floor_id
             LEFT JOIN locations l ON l.id = t.location_id
             LEFT JOIN assets a ON a.id = t.asset_id
             LEFT JOIN departments d ON d.id = t.reporter_department_id
             LEFT JOIN users ua ON ua.id = t.assigned_to
             LEFT JOIN users ur ON ur.id = t.reporter_id
             LEFT JOIN users uv ON uv.id = t.verified_by
             LEFT JOIN pm_work_orders pw ON pw.ticket_id = t.id
             WHERE t.id = ?",
            [$id]
        );
        if (!$t) {
            throw new NotFoundException();
        }
        $t['_assignment_accepted'] = (bool) Database::value(
            'SELECT accepted_at IS NOT NULL FROM ticket_assignments WHERE ticket_id = ? AND is_current = 1 ORDER BY id DESC LIMIT 1',
            [$id]
        );
        return $t;
    }

    /** Active users holding tickets.work at the hotel (for assignment dropdowns). */
    public static function technicians(int $hotelId): array
    {
        return Database::select(
            "SELECT DISTINCT u.id, u.name, u.phone,
                    (SELECT COUNT(*) FROM tickets x WHERE x.assigned_to = u.id AND x.status NOT IN ('COMPLETED','CANCELLED')) AS open_jobs
             FROM users u
             JOIN user_hotel_roles uhr ON uhr.user_id = u.id AND (uhr.hotel_id = ? OR uhr.hotel_id IS NULL)
             JOIN role_permissions rp ON rp.role_id = uhr.role_id
             JOIN permissions pm ON pm.id = rp.permission_id AND pm.code = 'tickets.work'
             WHERE u.is_active = 1 AND u.deleted_at IS NULL
             ORDER BY u.name",
            [$hotelId]
        );
    }

    // ------------------------------------------------------------------ create / update

    /**
     * @param array<string, mixed> $d validated input
     * @param list<array> $photos uploaded BEFORE images
     * @param array{system?:bool, ticket_type?:string, scheduled_start?:?string, scheduled_end?:?string, assigned_to?:?int} $opts
     */
    public static function create(int $hotelId, array $d, array $photos = [], array $opts = []): int
    {
        $system = !empty($opts['system']);
        $uid = Auth::id();
        if (!$system && !Auth::gate()->can('tickets.create', $hotelId)) {
            throw new ForbiddenException();
        }
        $hotel = Database::first('SELECT * FROM hotels WHERE id = ? AND is_active = 1', [$hotelId]);
        if (!$hotel) {
            throw new ValidationException(['hotel_id' => 'โรงแรมไม่ถูกต้อง']);
        }
        $priority = self::priority((int) $d['priority_id']);
        if (!Database::value('SELECT 1 FROM maintenance_categories WHERE id = ? AND is_active = 1', [(int) $d['category_id']])) {
            throw new ValidationException(['category_id' => 'หมวดงานไม่ถูกต้อง']);
        }
        $assetId = !empty($d['asset_id']) ? (int) $d['asset_id'] : null;
        $asset = null;
        if ($assetId) {
            $asset = Database::first("SELECT * FROM assets WHERE id = ? AND hotel_id = ? AND deleted_at IS NULL", [$assetId, $hotelId]);
            if (!$asset) {
                throw new ValidationException(['asset_id' => 'อุปกรณ์ไม่อยู่ในโรงแรมที่เลือก']);
            }
        }
        $loc = self::resolveLocation(
            $hotelId,
            !empty($d['building_id']) ? (int) $d['building_id'] : ($asset['building_id'] ?? null ? (int) $asset['building_id'] : null),
            !empty($d['floor_id']) ? (int) $d['floor_id'] : ($asset['floor_id'] ?? null ? (int) $asset['floor_id'] : null),
            !empty($d['location_id']) ? (int) $d['location_id'] : ($asset['location_id'] ?? null ? (int) $asset['location_id'] : null),
        );
        $deptId = !empty($d['reporter_department_id']) ? (int) $d['reporter_department_id'] : null;
        if ($deptId && !Database::value('SELECT 1 FROM departments WHERE id = ? AND hotel_id = ?', [$deptId, $hotelId])) {
            throw new ValidationException(['reporter_department_id' => 'แผนกไม่อยู่ในโรงแรมที่เลือก']);
        }
        $impact = (string) ($d['guest_impact'] ?? 'NONE');
        if (!isset(self::GUEST_IMPACT[$impact])) {
            $impact = 'NONE';
        }
        $now = Time::now();
        $requestedAt = !empty($d['requested_at']) ? (Time::toUtc((string) $d['requested_at']) ?? $now) : $now;
        if ($requestedAt > $now) {
            $requestedAt = $now; // a request cannot be reported in the future
        }
        [$respMin, $resMin] = self::slaMinutes($hotelId, (int) $priority['id']);
        $slaBase = $opts['scheduled_start'] ?? null;
        $slaBase = ($slaBase && $slaBase > $requestedAt) ? $slaBase : $requestedAt;
        $reporterName = trim((string) ($d['reporter_name'] ?? ''));
        if ($reporterName === '') {
            $reporterName = (string) (Auth::user()['name'] ?? 'ระบบ');
        }

        return Database::transaction(function () use ($hotel, $hotelId, $d, $photos, $opts, $priority, $assetId, $asset, $loc, $deptId, $impact, $now, $requestedAt, $respMin, $resMin, $slaBase, $reporterName, $uid, $system) {
            $year = (int) substr(Time::toLocal($requestedAt, 'Y-m-d') ?? Time::today(), 0, 4);
            $no = NumberSequence::ticketNo((string) $hotel['code'], $hotelId, $year);
            $id = Database::insert('tickets', [
                'hotel_id' => $hotelId,
                'ticket_no' => $no,
                'ticket_type' => ($opts['ticket_type'] ?? 'CORRECTIVE') === 'PREVENTIVE' ? 'PREVENTIVE' : 'CORRECTIVE',
                'status' => S::NEW,
                'title' => mb_substr(trim((string) $d['title']), 0, 200),
                'description' => ($d['description'] ?? '') !== '' ? (string) $d['description'] : null,
                'category_id' => (int) $d['category_id'],
                'priority_id' => (int) $priority['id'],
                'asset_id' => $assetId,
                'building_id' => $loc['building_id'],
                'floor_id' => $loc['floor_id'],
                'location_id' => $loc['location_id'],
                'location_text' => ($d['location_text'] ?? '') !== '' ? mb_substr((string) $d['location_text'], 0, 150) : null,
                'reporter_id' => $uid,
                'reporter_name' => mb_substr($reporterName, 0, 150),
                'reporter_department_id' => $deptId,
                'guest_impact' => $impact,
                'est_interruption_minutes' => !empty($d['est_interruption_minutes']) ? (int) $d['est_interruption_minutes'] : null,
                'notes' => ($d['notes'] ?? '') !== '' ? (string) $d['notes'] : null,
                'requested_at' => $requestedAt,
                'scheduled_start' => $opts['scheduled_start'] ?? null,
                'scheduled_end' => $opts['scheduled_end'] ?? null,
                'sla_response_due' => $respMin ? Time::addMinutes($slaBase, $respMin) : null,
                'sla_resolution_due' => $resMin ? Time::addMinutes($slaBase, $resMin) : null,
                'estimated_cost' => isset($d['estimated_cost']) && $d['estimated_cost'] !== '' ? Dec::norm($d['estimated_cost'], 2) : null,
                'is_demo' => !empty($opts['is_demo']) ? 1 : 0,
                'created_by' => $uid,
                'created_at' => $now,
            ]);
            self::log($id, $hotelId, null, S::NEW, 'create', $system ? 'สร้างอัตโนมัติโดยระบบ' : null, $uid);
            foreach ($photos as $file) {
                self::storeAttachment($id, $hotelId, $file, 'BEFORE', $uid);
            }
            if ($asset) {
                self::assetHistory((int) $asset['id'], $hotelId, 'TICKET_OPENED', 'เปิดงาน ' . $no . ': ' . $d['title'], $id, $uid);
            }
            Audit::log('ticket.create', 'ticket', $id, $hotelId, null, ['ticket_no' => $no, 'priority' => $priority['code']]);

            $full = self::load($id);
            if (($opts['ticket_type'] ?? '') !== 'PREVENTIVE') {
                NotificationService::ticketCreated($full);
                InAppNotifications::send(
                    array_diff(InAppNotifications::usersWithPermission('tickets.assign', $hotelId), [$uid]),
                    $hotelId,
                    'TICKET_CREATED',
                    ($priority['code'] === 'EMERGENCY' ? '🚨 ' : '') . 'แจ้งซ่อมใหม่ ' . $no,
                    $full['title'] . ($full['location_name'] ? ' · ' . $full['location_name'] : ''),
                    url('/tickets/' . $id)
                );
            }
            if (!empty($opts['assigned_to'])) {
                self::applyAssignment($full, (int) $opts['assigned_to'], null, $uid, true);
            }
            return $id;
        });
    }

    /** Edit descriptive fields (not status). Only while the ticket is open. */
    public static function update(int $id, array $d): void
    {
        Database::transaction(function () use ($id, $d): void {
            $t = Database::first('SELECT * FROM tickets WHERE id = ? FOR UPDATE', [$id]);
            if (!$t) {
                throw new NotFoundException();
            }
            $hotelId = (int) $t['hotel_id'];
            if (!Auth::gate()->can('tickets.update', $hotelId)) {
                throw new ForbiddenException();
            }
            if (!S::isOpen($t['status'])) {
                throw new BusinessRuleException('ไม่สามารถแก้ไขงานที่ปิดหรือยกเลิกแล้ว');
            }
            $priority = self::priority((int) $d['priority_id']);
            $assetId = !empty($d['asset_id']) ? (int) $d['asset_id'] : null;
            if ($assetId && !Database::value('SELECT 1 FROM assets WHERE id = ? AND hotel_id = ? AND deleted_at IS NULL', [$assetId, $hotelId])) {
                throw new ValidationException(['asset_id' => 'อุปกรณ์ไม่อยู่ในโรงแรมนี้']);
            }
            $loc = self::resolveLocation($hotelId, !empty($d['building_id']) ? (int) $d['building_id'] : null, !empty($d['floor_id']) ? (int) $d['floor_id'] : null, !empty($d['location_id']) ? (int) $d['location_id'] : null);
            $deptId = !empty($d['reporter_department_id']) ? (int) $d['reporter_department_id'] : null;
            if ($deptId && !Database::value('SELECT 1 FROM departments WHERE id = ? AND hotel_id = ?', [$deptId, $hotelId])) {
                throw new ValidationException(['reporter_department_id' => 'แผนกไม่อยู่ในโรงแรมนี้']);
            }
            $data = [
                'title' => mb_substr(trim((string) $d['title']), 0, 200),
                'description' => ($d['description'] ?? '') !== '' ? (string) $d['description'] : null,
                'category_id' => (int) $d['category_id'],
                'priority_id' => (int) $priority['id'],
                'asset_id' => $assetId,
                'building_id' => $loc['building_id'],
                'floor_id' => $loc['floor_id'],
                'location_id' => $loc['location_id'],
                'location_text' => ($d['location_text'] ?? '') !== '' ? mb_substr((string) $d['location_text'], 0, 150) : null,
                'reporter_department_id' => $deptId,
                'guest_impact' => isset(self::GUEST_IMPACT[$d['guest_impact'] ?? '']) ? $d['guest_impact'] : 'NONE',
                'est_interruption_minutes' => !empty($d['est_interruption_minutes']) ? (int) $d['est_interruption_minutes'] : null,
                'estimated_cost' => isset($d['estimated_cost']) && $d['estimated_cost'] !== '' ? Dec::norm($d['estimated_cost'], 2) : null,
                'notes' => ($d['notes'] ?? '') !== '' ? (string) $d['notes'] : null,
            ];
            if ((int) $t['priority_id'] !== (int) $priority['id']) {
                // Priority change recalculates SLA from the original request time
                [$respMin, $resMin] = self::slaMinutes($hotelId, (int) $priority['id']);
                $data['sla_response_due'] = $respMin ? Time::addMinutes($t['requested_at'], $respMin) : null;
                $data['sla_resolution_due'] = $resMin ? Time::addMinutes($t['requested_at'], $resMin) : null;
                $data['overdue_notified_at'] = null;
            }
            Database::update('tickets', $data, 'id = ?', [$id]);
            $changed = array_keys(array_filter($data, static fn ($v, $k) => (string) ($t[$k] ?? '') !== (string) ($v ?? ''), ARRAY_FILTER_USE_BOTH));
            if ($changed) {
                self::log($id, $hotelId, $t['status'], $t['status'], 'edit', 'แก้ไขข้อมูล: ' . implode(', ', $changed), Auth::id());
                Audit::log('ticket.update', 'ticket', $id, $hotelId, array_intersect_key($t, array_flip($changed)), array_intersect_key($data, array_flip($changed)));
            }
        });
    }

    // ------------------------------------------------------------------ workflow

    /**
     * Executes a workflow action.
     * @param array{note?:string, technician_id?:int, scheduled_start?:?string, scheduled_end?:?string, amount?:?string} $in
     * @param list<array> $files AFTER photos (submit) or extra images
     */
    public static function transition(int $id, string $action, array $in = [], array $files = []): void
    {
        $uid = (int) Auth::id();
        $note = trim((string) ($in['note'] ?? ''));
        if (in_array($action, \App\Services\TicketWorkflow::NOTE_REQUIRED, true) && mb_strlen($note) < 3) {
            throw new ValidationException(['note' => 'กรุณาระบุหมายเหตุ/เหตุผล (อย่างน้อย 3 ตัวอักษร)']);
        }
        Database::transaction(function () use ($id, $action, $in, $files, $uid, $note): void {
            $locked = Database::first('SELECT id FROM tickets WHERE id = ? FOR UPDATE', [$id]);
            if (!$locked) {
                throw new NotFoundException();
            }
            $t = self::load($id);
            TicketWorkflow::assertAllowed($action, $t, Auth::gate(), $uid);
            $from = (string) $t['status'];
            $to = TicketWorkflow::target($action, $from);
            $now = Time::now();
            $hotelId = (int) $t['hotel_id'];
            $upd = [];
            $selfClaim = $action === 'claim' || (bool) Database::value("SELECT 1 FROM ticket_status_logs WHERE ticket_id=? AND action='claim' LIMIT 1", [$id]);

            // close any open waiting period when leaving a waiting state
            if (in_array($from, S::WAITING, true) && !in_array($to, S::WAITING, true) && $t['waiting_since']) {
                $upd['waiting_seconds'] = (int) $t['waiting_seconds'] + max(0, Time::diffSeconds($t['waiting_since'], $now));
                $upd['waiting_since'] = null;
            }

            switch ($action) {
                case 'claim':
                    if ($t['assigned_to'] === null || $from === S::REOPENED) {
                        self::applyAssignment($t, $uid, 'ช่างรับงานด้วยบัญชีตนเอง', $uid, false);
                    }
                    Database::execute('UPDATE ticket_assignments SET accepted_at=? WHERE ticket_id=? AND is_current=1 AND accepted_at IS NULL', [$now, $id]);
                    $upd['acknowledged_at'] = $t['acknowledged_at'] ?? $now;
                    $upd['started_at'] = $now;
                    $upd['scheduled_start'] = $now;
                    $upd['scheduled_end'] = null;
                    $note = 'รับเรื่องและเริ่มงานโดย '.self::userName($uid).($note ? ' · '.$note : '');
                    if ($t['asset_id'] && $t['asset_status'] === 'ACTIVE') {
                        Database::update('assets', ['status' => 'UNDER_MAINTENANCE'], 'id=?', [$t['asset_id']]);
                        self::assetHistory((int) $t['asset_id'], $hotelId, 'STATUS', 'เริ่มซ่อมโดยช่างรับงานเอง ('.$t['ticket_no'].')', $id, $uid);
                    }
                    break;
                case 'review':
                    $upd['acknowledged_at'] = $t['acknowledged_at'] ?? $now;
                    break;

                case 'assign':
                    if ($selfClaim) { throw new BusinessRuleException('งานนี้ช่างรับด้วยบัญชีตนเองแล้ว ไม่สามารถเปลี่ยนผู้รับงานผ่านการมอบหมายได้'); }
                    $techId = (int) ($in['technician_id'] ?? 0);
                    if ($techId <= 0) {
                        throw new ValidationException(['technician_id' => 'กรุณาเลือกช่างผู้รับผิดชอบ']);
                    }
                    if ($t['assigned_to'] !== null && (int) $t['assigned_to'] === $techId && $from === S::ASSIGNED) {
                        throw new BusinessRuleException('ช่างคนนี้ได้รับมอบหมายงานอยู่แล้ว');
                    }
                    $sStart = Time::toUtc($in['scheduled_start'] ?? null);
                    $sEnd = Time::toUtc($in['scheduled_end'] ?? null);
                    if ($sStart && $sEnd && $sEnd <= $sStart) {
                        throw new ValidationException(['scheduled_end' => 'เวลาสิ้นสุดต้องหลังเวลาเริ่ม']);
                    }
                    if ($sStart) {
                        $upd['scheduled_start'] = $sStart;
                        $upd['scheduled_end'] = $sEnd;
                    }
                    self::applyAssignment($t, $techId, $note ?: null, $uid, false);
                    $upd['acknowledged_at'] = $t['acknowledged_at'] ?? $now;
                    $note = ($t['assigned_to'] ? 'เปลี่ยนผู้รับผิดชอบเป็น ' : 'มอบหมายให้ ') . self::userName($techId) . ($note ? ' · ' . $note : '');
                    break;

                case 'accept':
                    Database::execute('UPDATE ticket_assignments SET accepted_at = ? WHERE ticket_id = ? AND is_current = 1 AND accepted_at IS NULL', [$now, $id]);
                    $upd['acknowledged_at'] = $t['acknowledged_at'] ?? $now;
                    break;

                case 'start':
                    Database::execute('UPDATE ticket_assignments SET accepted_at = ? WHERE ticket_id = ? AND is_current = 1 AND accepted_at IS NULL', [$now, $id]);
                    $upd['acknowledged_at'] = $t['acknowledged_at'] ?? $now;
                    $upd['started_at'] = $t['started_at'] ?? $now;
                    if ($t['asset_id'] && $t['asset_status'] === 'ACTIVE') {
                        Database::update('assets', ['status' => 'UNDER_MAINTENANCE'], 'id = ?', [$t['asset_id']]);
                        self::assetHistory((int) $t['asset_id'], $hotelId, 'STATUS', 'สถานะ: อยู่ระหว่างซ่อม (' . $t['ticket_no'] . ')', $id, $uid);
                    }
                    break;

                case 'wait_parts':
                case 'wait_contractor':
                    $upd['waiting_since'] = $now;
                    break;

                case 'request_approval':
                    $upd['waiting_since'] = $now;
                    $amount = isset($in['amount']) && $in['amount'] !== '' && is_numeric($in['amount']) ? Dec::norm($in['amount'], 2) : null;
                    Database::insert('ticket_approvals', [
                        'ticket_id' => $id, 'hotel_id' => $hotelId, 'approval_type' => 'WORK', 'status' => 'PENDING',
                        'amount' => $amount, 'note' => mb_substr($note, 0, 1000), 'requested_by' => $uid, 'requested_at' => $now,
                    ]);
                    InAppNotifications::send(
                        array_diff(InAppNotifications::usersWithPermission('tickets.verify', $hotelId, false), [$uid]),
                        $hotelId, 'TICKET_APPROVAL', 'ขออนุมัติงาน ' . $t['ticket_no'], $note, url('/tickets/' . $id)
                    );
                    break;

                case 'resume':
                    break;

                case 'submit':
                    if ($t['pm_work_order_id']) {
                        $unfinished = Database::value(
                            'SELECT COUNT(*) FROM pm_work_order_checklist i LEFT JOIN pm_checklist_results r ON r.checklist_item_id=i.checklist_item_id AND r.pm_work_order_id=i.pm_work_order_id WHERE i.pm_work_order_id=? AND (r.is_done IS NULL OR r.is_done=0 OR (i.value_type<>\'CHECK\' AND (r.value_text IS NULL OR r.value_text=\'\')))',
                            [$t['pm_work_order_id']]
                        );
                        if ((int) $unfinished > 0) { throw new BusinessRuleException('กรุณาบันทึกผลตรวจ PM ให้ครบก่อนแจ้งงานเสร็จ'); }
                    }
                    $upd['technically_completed_at'] = $now;
                    if ($selfClaim) { $upd['scheduled_end'] = $now; }
                    foreach ($files as $file) {
                        self::storeAttachment($id, $hotelId, $file, 'AFTER', $uid);
                    }
                    Database::execute("UPDATE ticket_approvals SET status = 'CANCELLED' WHERE ticket_id = ? AND approval_type = 'COMPLETION' AND status = 'PENDING'", [$id]);
                    Database::insert('ticket_approvals', [
                        'ticket_id' => $id, 'hotel_id' => $hotelId, 'approval_type' => 'COMPLETION', 'status' => 'PENDING',
                        'note' => mb_substr($note, 0, 1000), 'requested_by' => $uid, 'requested_at' => $now,
                    ]);
                    InAppNotifications::send(
                        array_diff(InAppNotifications::usersWithPermission('tickets.verify', $hotelId), [$uid]),
                        $hotelId, 'TICKET_SUBMITTED', 'รอตรวจรับงาน ' . $t['ticket_no'], $t['title'], url('/tickets/' . $id)
                    );
                    break;

                case 'verify':
                    $upd['verified_at'] = $now;
                    $upd['verified_by'] = $uid;
                    $upd['closed_at'] = $now;
                    $upd['technically_completed_at'] = $t['technically_completed_at'] ?? $now;
                    self::decidePendingCompletion($id, 'APPROVED', $note, $uid, $now);
                    break;

                case 'reject':
                    $upd['technically_completed_at'] = null;
                    if ($selfClaim) { $upd['scheduled_end'] = null; }
                    self::decidePendingCompletion($id, 'REJECTED', $note, $uid, $now);
                    break;

                case 'reopen':
                    if ($selfClaim) { $upd['started_at'] = null; $upd['scheduled_start'] = null; $upd['scheduled_end'] = null; }
                    $upd['reopen_count'] = (int) $t['reopen_count'] + 1;
                    $upd['technically_completed_at'] = null;
                    $upd['verified_at'] = null;
                    $upd['verified_by'] = null;
                    $upd['closed_at'] = null;
                    $upd['overdue_notified_at'] = null;
                    if ($t['pm_work_order_id']) {
                        Database::update('pm_work_orders', ['status' => 'OPEN', 'completed_at' => null], 'id = ?', [$t['pm_work_order_id']]);
                    }
                    break;

                case 'cancel':
                    $issued = (int) Database::value("SELECT COUNT(*) FROM ticket_part_usages WHERE ticket_id = ? AND status = 'ISSUED'", [$id]);
                    if ($issued > 0) {
                        throw new BusinessRuleException('มีการเบิกอะไหล่ในงานนี้ ' . $issued . ' รายการ กรุณาคืนอะไหล่ก่อนยกเลิกงาน');
                    }
                    $upd['cancelled_at'] = $now;
                    $upd['cancel_reason'] = mb_substr($note, 0, 500);
                    $upd['closed_at'] = $now;
                    Database::execute("UPDATE ticket_approvals SET status = 'CANCELLED' WHERE ticket_id = ? AND status = 'PENDING'", [$id]);
                    Database::execute('UPDATE ticket_assignments SET is_current = 0, ended_at = ? WHERE ticket_id = ? AND is_current = 1', [$now, $id]);
                    if ($t['pm_work_order_id']) {
                        Database::update('pm_work_orders', ['status' => 'CANCELLED'], 'id = ?', [$t['pm_work_order_id']]);
                    }
                    break;
            }

            $upd['status'] = $to;
            Database::update('tickets', $upd, 'id = ?', [$id]);
            self::log($id, $hotelId, $from, $to, $action, $note !== '' ? $note : null, $uid);

            // Post-transition side effects
            if ($action === 'verify') {
                $costs = CostService::recalcTicket($id);
                if ($t['pm_work_order_id']) {
                    Database::update('pm_work_orders', ['status' => 'COMPLETED', 'completed_at' => $now], 'id = ?', [$t['pm_work_order_id']]);
                }
                Database::execute('UPDATE ticket_assignments SET ended_at = ? WHERE ticket_id = ? AND is_current = 1 AND ended_at IS NULL', [$now, $id]);
                if ($t['asset_id']) {
                    self::restoreAssetIfIdle((int) $t['asset_id'], $hotelId, $id, $uid);
                    self::assetHistory((int) $t['asset_id'], $hotelId, 'REPAIRED', 'ปิดงาน ' . $t['ticket_no'] . ' ค่าใช้จ่ายรวม ' . money($costs['total']), $id, $uid);
                }
                $full = self::load($id);
                $data = NotificationService::ticketLines($full);
                $data['lines'][] = ['💰 ค่าใช้จ่ายรวม:', money($costs['total'])];
                NotificationService::queue('TICKET_COMPLETED', $hotelId, $data, 'TICKET_COMPLETED:' . $id . ':' . (int) $t['reopen_count']);
                if ($t['reporter_id'] && (int) $t['reporter_id'] !== $uid) {
                    InAppNotifications::send([(int) $t['reporter_id']], $hotelId, 'TICKET_COMPLETED', 'งานซ่อมเสร็จแล้ว ' . $t['ticket_no'], $t['title'], url('/tickets/' . $id));
                }
            } elseif ($action === 'cancel' && $t['asset_id']) {
                self::restoreAssetIfIdle((int) $t['asset_id'], $hotelId, $id, $uid);
                self::assetHistory((int) $t['asset_id'], $hotelId, 'TICKET_CANCELLED', 'ยกเลิกงาน ' . $t['ticket_no'] . ': ' . $note, $id, $uid);
            } elseif ($action === 'reject' && $t['assigned_to']) {
                InAppNotifications::send([(int) $t['assigned_to']], $hotelId, 'TICKET_REJECTED', 'งานไม่ผ่านการตรวจรับ ' . $t['ticket_no'], $note, url('/tickets/' . $id));
            } elseif ($action === 'reopen' && $t['assigned_to']) {
                InAppNotifications::send([(int) $t['assigned_to']], $hotelId, 'TICKET_REOPENED', 'เปิดงานใหม่ ' . $t['ticket_no'], $note, url('/tickets/' . $id));
            }
            Audit::log('ticket.' . $action, 'ticket', $id, $hotelId, ['status' => $from], ['status' => $to, 'note' => $note]);
        });
    }

    /** Assign/reassign inside an existing transaction. */
    private static function applyAssignment(array $t, int $techId, ?string $note, ?int $by, bool $logStatus): void
    {
        $hotelId = (int) $t['hotel_id'];
        if (!Database::value('SELECT 1 FROM users WHERE id = ? AND is_active = 1 AND deleted_at IS NULL', [$techId])
            || !(new Permissions($techId))->can('tickets.work', $hotelId)) {
            throw new ValidationException(['technician_id' => 'ผู้ใช้นี้ไม่มีสิทธิ์ปฏิบัติงานซ่อมในโรงแรมนี้']);
        }
        $now = Time::now();
        Database::execute('UPDATE ticket_assignments SET is_current = 0, ended_at = ? WHERE ticket_id = ? AND is_current = 1', [$now, $t['id']]);
        Database::insert('ticket_assignments', [
            'ticket_id' => $t['id'], 'hotel_id' => $hotelId, 'technician_id' => $techId, 'assigned_by' => $by,
            'assigned_at' => $now, 'is_current' => 1, 'note' => $note !== null ? mb_substr($note, 0, 500) : null,
        ]);
        Database::update('tickets', ['assigned_to' => $techId], 'id = ?', [$t['id']]);
        if ($logStatus) {
            Database::update('tickets', ['status' => S::ASSIGNED], 'id = ?', [$t['id']]);
            self::log((int) $t['id'], $hotelId, (string) $t['status'], S::ASSIGNED, 'assign', 'มอบหมายให้ ' . self::userName($techId), $by);
        }
        $t['assignee_name'] = self::userName($techId);
        $t['status'] = in_array($t['status'], [S::IN_PROGRESS, S::WAITING_PARTS, S::WAITING_CONTRACTOR, S::WAITING_APPROVAL], true) ? $t['status'] : S::ASSIGNED;
        $assignSeq = (int) Database::value('SELECT COUNT(*) FROM ticket_assignments WHERE ticket_id = ?', [$t['id']]);
        NotificationService::queue('TICKET_ASSIGNED', $hotelId, NotificationService::ticketLines($t), 'TICKET_ASSIGNED:' . $t['id'] . ':' . $assignSeq);
        if ($techId !== $by) {
            InAppNotifications::send([$techId], $hotelId, 'TICKET_ASSIGNED', 'คุณได้รับมอบหมายงาน ' . $t['ticket_no'], (string) $t['title'], url('/tickets/' . $t['id']));
        }
    }

    private static function decidePendingCompletion(int $ticketId, string $status, string $note, int $uid, string $now): void
    {
        Database::execute(
            "UPDATE ticket_approvals SET status = ?, decided_by = ?, decided_at = ?, decision_note = ?
             WHERE ticket_id = ? AND approval_type = 'COMPLETION' AND status = 'PENDING'",
            [$status, $uid, $now, $note !== '' ? mb_substr($note, 0, 1000) : null, $ticketId]
        );
    }

    /** Decide a WORK approval request (ticket waits in WAITING_APPROVAL). */
    public static function decideApproval(int $ticketId, int $approvalId, string $decision, string $note): void
    {
        if (!in_array($decision, ['approve', 'reject'], true)) {
            throw new BusinessRuleException('การดำเนินการไม่ถูกต้อง');
        }
        if ($decision === 'reject' && mb_strlen(trim($note)) < 3) {
            throw new ValidationException(['note' => 'กรุณาระบุเหตุผลที่ไม่อนุมัติ']);
        }
        $uid = (int) Auth::id();
        Database::transaction(function () use ($ticketId, $approvalId, $decision, $note, $uid): void {
            $t = Database::first('SELECT * FROM tickets WHERE id = ? FOR UPDATE', [$ticketId]);
            $a = Database::first("SELECT * FROM ticket_approvals WHERE id = ? AND ticket_id = ? FOR UPDATE", [$approvalId, $ticketId]);
            if (!$t || !$a) {
                throw new NotFoundException();
            }
            $hotelId = (int) $t['hotel_id'];
            if (!Auth::gate()->can('tickets.verify', $hotelId)) {
                throw new ForbiddenException();
            }
            if ($a['approval_type'] !== 'WORK' || $a['status'] !== 'PENDING') {
                throw new BusinessRuleException('คำขออนุมัตินี้ได้รับการพิจารณาแล้ว');
            }
            if ((int) $a['requested_by'] === $uid) {
                throw new BusinessRuleException('ไม่สามารถอนุมัติคำขอของตนเองได้');
            }
            $now = Time::now();
            Database::update('ticket_approvals', [
                'status' => $decision === 'approve' ? 'APPROVED' : 'REJECTED', 'decided_by' => $uid, 'decided_at' => $now,
                'decision_note' => $note !== '' ? mb_substr($note, 0, 1000) : null,
            ], 'id = ?', [$approvalId]);
            if ($t['status'] === S::WAITING_APPROVAL) {
                $upd = ['status' => S::IN_PROGRESS];
                if ($t['waiting_since']) {
                    $upd['waiting_seconds'] = (int) $t['waiting_seconds'] + max(0, Time::diffSeconds($t['waiting_since'], $now));
                    $upd['waiting_since'] = null;
                }
                Database::update('tickets', $upd, 'id = ?', [$ticketId]);
                self::log($ticketId, $hotelId, S::WAITING_APPROVAL, S::IN_PROGRESS, $decision === 'approve' ? 'approval_granted' : 'approval_denied', ($decision === 'approve' ? 'อนุมัติ' : 'ไม่อนุมัติ') . ($note !== '' ? ': ' . $note : ''), $uid);
            }
            if ($t['assigned_to']) {
                InAppNotifications::send([(int) $t['assigned_to']], $hotelId, 'TICKET_APPROVAL', ($decision === 'approve' ? 'อนุมัติแล้ว ' : 'ไม่อนุมัติ ') . $t['ticket_no'], $note ?: null, url('/tickets/' . $ticketId));
            }
            Audit::log('ticket.approval_' . $decision, 'ticket', $ticketId, $hotelId, null, ['approval_id' => $approvalId, 'note' => $note]);
        });
    }

    // ------------------------------------------------------------------ work logs, comments, files

    /**
     * Records labour. started/ended are local datetime strings.
     * Labour cost = hours x technician rate effective on the work date (snapshot).
     */
    public static function addWorkLog(int $ticketId, string $startedLocal, string $endedLocal, string $description, ?int $technicianId = null): int
    {
        $uid = (int) Auth::id();
        $start = Time::toUtc($startedLocal);
        $end = Time::toUtc($endedLocal);
        if (!$start || !$end || $end <= $start) {
            throw new ValidationException(['ended_at' => 'เวลาสิ้นสุดต้องหลังเวลาเริ่ม']);
        }
        if ($end > Time::addMinutes(Time::now(), 5)) {
            throw new ValidationException(['ended_at' => 'ไม่สามารถบันทึกเวลาทำงานล่วงหน้าได้']);
        }
        $seconds = Time::diffSeconds($start, $end);
        if ($seconds > 24 * 3600) {
            throw new ValidationException(['ended_at' => 'บันทึกได้ไม่เกิน 24 ชั่วโมงต่อรายการ']);
        }
        $hours = Dec::round(Dec::div((string) $seconds, '3600', 6), 2);
        if (Dec::cmp($hours, '0') <= 0) {
            throw new ValidationException(['ended_at' => 'ระยะเวลาทำงานน้อยเกินไป']);
        }
        if (mb_strlen(trim($description)) < 3) {
            throw new ValidationException(['description' => 'กรุณาระบุรายละเอียดงานที่ทำ']);
        }
        return Database::transaction(function () use ($ticketId, $start, $end, $hours, $description, $technicianId, $uid) {
            $t = Database::first('SELECT * FROM tickets WHERE id = ? FOR UPDATE', [$ticketId]);
            if (!$t) {
                throw new NotFoundException();
            }
            $hotelId = (int) $t['hotel_id'];
            $g = Auth::gate();
            $tech = $technicianId ?: $uid;
            $isAssignee = (int) $t['assigned_to'] === $uid;
            if ($tech === $uid) {
                if (!($isAssignee && $g->can('tickets.work', $hotelId)) && !$g->can('tickets.assign', $hotelId)) {
                    throw new ForbiddenException('บันทึกเวลาทำงานได้เฉพาะช่างผู้รับผิดชอบหรือหัวหน้าช่าง');
                }
            } else {
                if (!$g->can('tickets.assign', $hotelId)) {
                    throw new ForbiddenException('เฉพาะหัวหน้าช่างที่บันทึกเวลาแทนช่างคนอื่นได้');
                }
                if (!(new Permissions($tech))->can('tickets.work', $hotelId)) {
                    throw new ValidationException(['technician_id' => 'ผู้ใช้นี้ไม่ใช่ช่างของโรงแรมนี้']);
                }
            }
            if (!in_array($t['status'], S::WORKABLE, true)) {
                throw new BusinessRuleException('ไม่สามารถบันทึกเวลาทำงานในสถานะปัจจุบันของงาน');
            }
            $workDate = (string) Time::toLocal($start, 'Y-m-d');
            $rate = CostService::laborRate($tech, $hotelId, $workDate);
            $cost = Dec::money($hours, $rate);
            $id = Database::insert('work_logs', [
                'ticket_id' => $ticketId, 'hotel_id' => $hotelId, 'technician_id' => $tech,
                'started_at' => $start, 'ended_at' => $end, 'hours' => $hours, 'description' => trim($description),
                'labor_rate' => Dec::norm($rate, 2), 'labor_cost' => $cost, 'created_by' => $uid, 'created_at' => Time::now(),
            ]);
            if (Dec::cmp($cost, '0') > 0) {
                CostService::recordSystemCost(
                    $hotelId, $ticketId, $t['asset_id'] ? (int) $t['asset_id'] : null, 'LABOR', 'WORK_LOG', $id,
                    'ค่าแรง ' . self::userName($tech) . ' ' . qty($hours) . ' ชม. × ' . money($rate), $hours, $rate, $cost, $workDate, $uid
                );
                CostService::recalcTicket($ticketId);
            }
            self::log($ticketId, $hotelId, $t['status'], $t['status'], 'work_log', 'บันทึกเวลาทำงาน ' . qty($hours) . ' ชม. (' . self::userName($tech) . '): ' . mb_substr(trim($description), 0, 200), $uid);
            return $id;
        });
    }

    public static function addComment(int $ticketId, string $body, bool $internal = false): int
    {
        $body = trim($body);
        if (mb_strlen($body) < 1 || mb_strlen($body) > 5000) {
            throw new ValidationException(['body' => 'กรุณากรอกข้อความ (ไม่เกิน 5,000 ตัวอักษร)']);
        }
        $t = Database::first('SELECT id, hotel_id, ticket_no, reporter_id, assigned_to, title FROM tickets WHERE id = ?', [$ticketId]);
        if (!$t) {
            throw new NotFoundException();
        }
        $uid = (int) Auth::id();
        $hotelId = (int) $t['hotel_id'];
        // Internal notes only by maintenance staff
        if ($internal && !Auth::gate()->can('tickets.work', $hotelId) && !Auth::gate()->can('tickets.assign', $hotelId)) {
            $internal = false;
        }
        $id = Database::insert('ticket_comments', [
            'ticket_id' => $ticketId, 'hotel_id' => $hotelId, 'user_id' => $uid, 'body' => $body,
            'is_internal' => $internal ? 1 : 0, 'created_at' => Time::now(),
        ]);
        $notify = array_filter([(int) $t['reporter_id'], (int) $t['assigned_to']]);
        if ($internal) {
            $notify = array_filter([(int) $t['assigned_to']]);
        }
        InAppNotifications::send(array_values(array_diff($notify, [$uid])), $hotelId, 'TICKET_COMMENT', 'ความคิดเห็นใหม่ ' . $t['ticket_no'], mb_substr($body, 0, 200), url('/tickets/' . $ticketId . '#comments'));
        return $id;
    }

    /** @param list<array> $files */
    public static function addAttachments(int $ticketId, array $files, string $stage): int
    {
        if (!in_array($stage, ['BEFORE', 'AFTER', 'DOCUMENT'], true)) {
            $stage = 'BEFORE';
        }
        if (!$files) {
            throw new ValidationException(['files' => 'กรุณาเลือกไฟล์']);
        }
        $t = Database::first('SELECT id, hotel_id, status, ticket_no FROM tickets WHERE id = ?', [$ticketId]);
        if (!$t) {
            throw new NotFoundException();
        }
        if (!S::isOpen($t['status']) && !Auth::gate()->can('tickets.verify', (int) $t['hotel_id'])) {
            throw new BusinessRuleException('งานปิดแล้ว ไม่สามารถแนบไฟล์เพิ่มได้');
        }
        return Database::transaction(function () use ($t, $files, $stage) {
            $n = 0;
            foreach ($files as $f) {
                self::storeAttachment((int) $t['id'], (int) $t['hotel_id'], $f, $stage, Auth::id());
                $n++;
            }
            self::log((int) $t['id'], (int) $t['hotel_id'], $t['status'], $t['status'], 'attachment', 'แนบไฟล์ ' . $n . ' ไฟล์ (' . $stage . ')', Auth::id());
            return $n;
        });
    }

    private static function storeAttachment(int $ticketId, int $hotelId, array $file, string $stage, ?int $uid): int
    {
        $kind = $stage === 'DOCUMENT' ? 'documents' : 'images';
        $s = FileStorage::store($file, $kind, 'tickets/' . $hotelId);
        return Database::insert('ticket_attachments', [
            'ticket_id' => $ticketId, 'hotel_id' => $hotelId, 'stage' => $stage,
            'disk_path' => $s['disk_path'], 'original_name' => $s['original_name'], 'mime' => $s['mime'],
            'size_bytes' => $s['size_bytes'], 'uploaded_by' => $uid, 'created_at' => Time::now(),
        ]);
    }

    // ------------------------------------------------------------------ helpers

    public static function log(int $ticketId, int $hotelId, ?string $from, string $to, string $action, ?string $note, ?int $uid): void
    {
        Database::insert('ticket_status_logs', [
            'ticket_id' => $ticketId, 'hotel_id' => $hotelId, 'from_status' => $from, 'to_status' => $to,
            'action' => $action, 'note' => $note, 'user_id' => $uid, 'created_at' => Time::now(),
        ]);
    }

    public static function assetHistory(int $assetId, int $hotelId, string $event, string $desc, ?int $ticketId, ?int $uid): void
    {
        Database::insert('asset_history', [
            'asset_id' => $assetId, 'hotel_id' => $hotelId, 'event' => mb_substr($event, 0, 30), 'description' => mb_substr($desc, 0, 500),
            'ticket_id' => $ticketId, 'user_id' => $uid, 'created_at' => Time::now(),
        ]);
    }

    /** Asset back to ACTIVE if it was under maintenance and has no other in-progress work. */
    private static function restoreAssetIfIdle(int $assetId, int $hotelId, int $exceptTicket, ?int $uid): void
    {
        $status = Database::value('SELECT status FROM assets WHERE id = ?', [$assetId]);
        if ($status !== 'UNDER_MAINTENANCE') {
            return;
        }
        $other = (int) Database::value(
            "SELECT COUNT(*) FROM tickets WHERE asset_id = ? AND id <> ? AND status IN ('IN_PROGRESS','WAITING_PARTS','WAITING_CONTRACTOR','WAITING_APPROVAL','PENDING_VERIFICATION')",
            [$assetId, $exceptTicket]
        );
        if ($other === 0) {
            Database::update('assets', ['status' => 'ACTIVE'], 'id = ?', [$assetId]);
            self::assetHistory($assetId, $hotelId, 'STATUS', 'สถานะ: ใช้งานปกติ', $exceptTicket, $uid);
        }
    }

    private static function userName(int $id): string
    {
        return (string) (Database::value('SELECT name FROM users WHERE id = ?', [$id]) ?? '#' . $id);
    }
}

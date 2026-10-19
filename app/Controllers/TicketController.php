<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Controller;
use App\Core\Database;
use App\Core\Exceptions\NotFoundException;
use App\Core\HotelContext;
use App\Core\Response;
use App\Helpers\Csv;
use App\Helpers\Time;
use App\Models\TicketStatus as S;
use App\Policies\TicketPolicy;
use App\Services\Audit;
use App\Services\FileStorage;
use App\Services\InventoryService;
use App\Services\PMService;
use App\Services\TicketService;
use App\Services\TicketWorkflow;

final class TicketController extends Controller
{
    /** @return array{0:string,1:list<mixed>,2:array<string,mixed>} */
    private function filters(): array
    {
        $q = $this->request->query;
        [$vis, $params] = TicketPolicy::visibility('t');
        $w = [$vis];
        $f = [
            'q' => mb_substr(trim((string) ($q['q'] ?? '')), 0, 100),
            'status' => (string) ($q['status'] ?? ''),
            'bucket' => (string) ($q['bucket'] ?? ''),
            'priority_id' => (int) ($q['priority_id'] ?? 0),
            'category_id' => (int) ($q['category_id'] ?? 0),
            'technician_id' => (int) ($q['technician_id'] ?? 0),
            'type' => (string) ($q['type'] ?? ''),
            'overdue' => !empty($q['overdue']),
            'mine' => !empty($q['mine']),
            'from' => preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) ($q['from'] ?? '')) ? $q['from'] : '',
            'to' => preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) ($q['to'] ?? '')) ? $q['to'] : '',
            'asset_id' => (int) ($q['asset_id'] ?? 0),
        ];
        if ($f['q'] !== '') {
            $like = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\%', '\_'], $f['q']) . '%';
            $w[] = '(t.ticket_no LIKE ? OR t.title LIKE ? OR l.name LIKE ? OR t.location_text LIKE ? OR t.reporter_name LIKE ?)';
            array_push($params, $like, $like, $like, $like, $like);
        }
        if ($f['status'] === 'OPEN') {
            $w[] = "t.status NOT IN ('COMPLETED','CANCELLED')";
        } elseif (isset(S::LABELS[$f['status']])) {
            $w[] = 't.status = ?';
            $params[] = $f['status'];
        }
        if ($f['bucket'] === 'pending') {
            $w[] = 't.status IN (' . Database::placeholders(S::PENDING_BUCKET) . ')';
            array_push($params, ...S::PENDING_BUCKET);
        }
        foreach (['priority_id' => 't.priority_id', 'category_id' => 't.category_id', 'technician_id' => 't.assigned_to', 'asset_id' => 't.asset_id'] as $k => $col) {
            if ($f[$k] > 0) {
                $w[] = "$col = ?";
                $params[] = $f[$k];
            }
        }
        if (in_array($f['type'], ['CORRECTIVE', 'PREVENTIVE'], true)) {
            $w[] = 't.ticket_type = ?';
            $params[] = $f['type'];
        }
        if ($f['overdue']) {
            $w[] = "t.status NOT IN ('COMPLETED','CANCELLED') AND t.sla_resolution_due < ?";
            $params[] = Time::now();
        }
        if ($f['mine']) {
            $w[] = '(t.assigned_to = ? OR t.reporter_id = ?)';
            array_push($params, Auth::id(), Auth::id());
        }
        if ($f['from'] !== '' || $f['to'] !== '') {
            [$a, $b] = Time::localDateRangeToUtc($f['from'] ?: '2000-01-01', $f['to'] ?: Time::today());
            $w[] = 't.requested_at >= ? AND t.requested_at < ?';
            array_push($params, $a, $b);
        }
        return [implode(' AND ', $w), $params, $f];
    }

    private const LIST_SELECT = "SELECT t.id, t.ticket_no, t.hotel_id, t.title, t.status, t.ticket_type, t.requested_at, t.sla_resolution_due,
            t.location_text, t.reporter_name, t.cost_total, t.technically_completed_at, t.closed_at,
            p.name_th AS priority_name, p.level AS priority_level, p.color AS priority_color,
            mc.name_th AS category_name, l.name AS location_name, u.name AS assignee_name
        FROM tickets t
        JOIN priorities p ON p.id = t.priority_id
        JOIN maintenance_categories mc ON mc.id = t.category_id
        LEFT JOIN locations l ON l.id = t.location_id
        LEFT JOIN users u ON u.id = t.assigned_to";

    public function index(): Response
    {
        [$where, $params, $f] = $this->filters();
        $total = (int) Database::value("SELECT COUNT(*) FROM tickets t LEFT JOIN locations l ON l.id = t.location_id WHERE $where", $params);
        $per = $this->perPage();
        $page = min($this->page(), max(1, (int) ceil($total / $per)));
        $rows = Database::select(self::LIST_SELECT . " WHERE $where ORDER BY t.requested_at DESC, t.id DESC LIMIT $per OFFSET " . (($page - 1) * $per), $params);
        $counts = Database::select(
            "SELECT t.status, COUNT(*) AS n FROM tickets t WHERE " . TicketPolicy::visibility('t')[0] . ' GROUP BY t.status',
            TicketPolicy::visibility('t')[1]
        );
        $technicians = [];
        $sel = HotelContext::selectedId();
        if ($sel && can('tickets.view', $sel)) {
            $technicians = TicketService::technicians($sel);
        }
        return $this->view('tickets/index', [
            'title' => 'งานแจ้งซ่อม',
            'breadcrumbs' => [['งานแจ้งซ่อม', null]],
            'rows' => $rows, 'total' => $total, 'page' => $page, 'per' => $per, 'f' => $f,
            'statusCounts' => array_column($counts, 'n', 'status'),
            'priorities' => Database::select('SELECT id, name_th FROM priorities WHERE is_active = 1 ORDER BY level'),
            'categories' => Database::select('SELECT id, name_th FROM maintenance_categories WHERE is_active = 1 ORDER BY sort_order'),
            'technicians' => $technicians,
            'showHotel' => HotelContext::selectedId() === null,
        ]);
    }

    public function export(): Response
    {
        [$where, $params] = $this->filters();
        $exportHotels = HotelContext::scope('reports.export');
        $where .= ' AND t.hotel_id IN (' . Database::placeholders($exportHotels) . ')';
        array_push($params, ...$exportHotels);
        $rows = Database::select(self::LIST_SELECT . " WHERE $where ORDER BY t.requested_at DESC LIMIT 10000", $params);
        Audit::log('ticket.export', 'ticket', null, HotelContext::selectedId(), null, ['rows' => count($rows)]);
        $hotels = HotelContext::all();
        return Csv::response(
            'work-orders-' . Time::today() . '.csv',
            ['เลขที่งาน', 'โรงแรม', 'ประเภท', 'หัวข้อ', 'หมวดงาน', 'สถานที่', 'ความเร่งด่วน', 'สถานะ', 'ผู้แจ้ง', 'ผู้รับผิดชอบ', 'วันที่แจ้ง', 'กำหนดเสร็จ (SLA)', 'ซ่อมเสร็จ', 'ปิดงาน', 'ค่าใช้จ่ายรวม'],
            (static function () use ($rows, $hotels) {
                foreach ($rows as $r) {
                    yield [$r['ticket_no'], $hotels[(int) $r['hotel_id']]['code'] ?? '', $r['ticket_type'], $r['title'], $r['category_name'],
                        $r['location_name'] ?? $r['location_text'], $r['priority_name'], S::label($r['status']), $r['reporter_name'], $r['assignee_name'],
                        Time::toLocal($r['requested_at'], 'Y-m-d H:i'), Time::toLocal($r['sla_resolution_due'], 'Y-m-d H:i'),
                        Time::toLocal($r['technically_completed_at'], 'Y-m-d H:i'), Time::toLocal($r['closed_at'], 'Y-m-d H:i'), $r['cost_total']];
                }
            })()
        );
    }

    private function formData(?int $hotelId): array
    {
        return [
            'hotels' => HotelContext::hotelsFor('tickets.create'),
            'priorities' => Database::select('SELECT * FROM priorities WHERE is_active = 1 ORDER BY level'),
            'categories' => Database::select('SELECT * FROM maintenance_categories WHERE is_active = 1 ORDER BY sort_order'),
            'impacts' => TicketService::GUEST_IMPACT,
            'hotelId' => $hotelId,
            'maxMb' => (int) (setting('upload_max_mb') ?: 10),
        ];
    }

    public function create(): Response
    {
        $hotels = HotelContext::hotelsFor('tickets.create');
        $hotelId = HotelContext::selectedId() ?? (count($hotels) === 1 ? (int) $hotels[0]['id'] : null);
        $prefill = ['asset_id' => $this->request->int('asset_id'), 'location_id' => $this->request->int('location_id'), 'date' => $this->request->str('date')];
        if ($prefill['asset_id']) {
            $a = Database::first('SELECT id, hotel_id, location_id, building_id, floor_id, name, asset_code FROM assets WHERE id = ? AND deleted_at IS NULL', [$prefill['asset_id']]);
            if ($a && can('tickets.create', (int) $a['hotel_id'])) {
                $hotelId = (int) $a['hotel_id'];
                $prefill['asset'] = $a;
                $prefill['location_id'] = $a['location_id'];
            } else {
                $prefill['asset_id'] = null;
            }
        }
        return $this->view('tickets/form', $this->formData($hotelId) + [
            'title' => 'แจ้งซ่อมใหม่',
            'breadcrumbs' => [['งานแจ้งซ่อม', '/tickets'], ['แจ้งซ่อมใหม่', null]],
            'ticket' => null, 'prefill' => $prefill,
        ]);
    }

    private function rules(): array
    {
        return [
            'title' => 'required|string|min:3|max:200',
            'description' => 'nullable|string|max:5000',
            'category_id' => 'required|int',
            'priority_id' => 'required|int',
            'asset_id' => 'nullable|int',
            'building_id' => 'nullable|int',
            'floor_id' => 'nullable|int',
            'location_id' => 'nullable|int',
            'location_text' => 'nullable|string|max:150',
            'reporter_name' => 'nullable|string|max:150',
            'reporter_department_id' => 'nullable|int',
            'guest_impact' => 'nullable|in:NONE,LOW,HIGH,ROOM_OUT_OF_ORDER',
            'est_interruption_minutes' => 'nullable|int|min:0|max:100000',
            'estimated_cost' => 'nullable|decimal|min:0',
            'notes' => 'nullable|string|max:2000',
            'requested_at' => 'nullable|datetime',
            'scheduled_start' => 'nullable|datetime',
            'scheduled_end' => 'nullable|datetime',
        ];
    }

    public function store(): Response
    {
        $hotelId = $this->hotelForWrite('tickets.create');
        $d = $this->validate($this->rules());
        if (empty($d['location_id']) && empty($d['location_text']) && empty($d['asset_id'])) {
            throw new \App\Core\Exceptions\ValidationException(['location_id' => 'กรุณาระบุห้อง/พื้นที่ หรือกรอกตำแหน่งเพิ่มเติม']);
        }
        $photos = array_slice($this->request->fileList('photos'), 0, 10);
        $opts = [];
        if (!empty($d['scheduled_start'])) {
            $this->authorize('calendar.reschedule', $hotelId);
            $start = Time::toUtc($d['scheduled_start']);
            $end = !empty($d['scheduled_end']) ? Time::toUtc($d['scheduled_end']) : Time::addMinutes($start, 60);
            if ($end <= $start) {
                throw new \App\Core\Exceptions\ValidationException(['scheduled_end' => 'เวลาสิ้นสุดต้องหลังเริ่ม']);
            }
            $opts = ['scheduled_start' => $start,'scheduled_end' => $end];
        }
        $id = TicketService::create($hotelId, $d, $photos, $opts);
        $no = Database::value('SELECT ticket_no FROM tickets WHERE id = ?', [$id]);
        $this->flash('success', 'บันทึกใบแจ้งซ่อม ' . $no . ' เรียบร้อยแล้ว');
        return $this->redirect('/tickets/' . $id);
    }

    public function show(int $id): Response
    {
        TicketPolicy::findVisible($id);
        $t = TicketService::load($id);
        $hotelId = (int) $t['hotel_id'];
        $g = Auth::gate();
        $uid = $this->userId();
        $isStaffView = $g->can('tickets.work', $hotelId) || $g->can('tickets.assign', $hotelId) || $g->can('tickets.view', $hotelId);
        $data = [
            'title' => $t['ticket_no'],
            'publicIntake' => $isStaffView ? Database::first('SELECT reporter_kind,contact FROM public_repair_submissions WHERE ticket_id=?', [$id]) : null,
            'breadcrumbs' => [['งานแจ้งซ่อม', '/tickets'], [$t['ticket_no'], null]],
            't' => $t,
            'actions' => TicketWorkflow::availableActions($t, $g, $uid),
            'logs' => Database::select('SELECT l.*, u.name AS user_name FROM ticket_status_logs l LEFT JOIN users u ON u.id = l.user_id WHERE l.ticket_id = ? ORDER BY l.created_at DESC, l.id DESC', [$id]),
            'attachments' => Database::select('SELECT * FROM ticket_attachments WHERE ticket_id = ? ORDER BY created_at', [$id]),
            'comments' => Database::select('SELECT c.*, u.name AS user_name FROM ticket_comments c JOIN users u ON u.id = c.user_id WHERE c.ticket_id = ?' . ($isStaffView ? '' : ' AND c.is_internal = 0') . ' ORDER BY c.created_at', [$id]),
            'workLogs' => Database::select('SELECT w.*, u.name AS tech_name FROM work_logs w JOIN users u ON u.id = w.technician_id WHERE w.ticket_id = ? ORDER BY w.started_at DESC', [$id]),
            'parts' => Database::select('SELECT pu.*, sp.sku, sp.name, sp.unit, u.name AS issued_by_name FROM ticket_part_usages pu JOIN spare_parts sp ON sp.id = pu.part_id LEFT JOIN users u ON u.id = pu.issued_by WHERE pu.ticket_id = ? ORDER BY pu.created_at', [$id]),
            'costs' => $g->can('costs.view', $hotelId) || $g->can('tickets.assign', $hotelId)
                ? Database::select('SELECT ce.*, cc.name_th AS category_name, cc.code AS category_code FROM cost_entries ce JOIN cost_categories cc ON cc.id = ce.category_id WHERE ce.ticket_id = ? ORDER BY ce.created_at', [$id])
                : null,
            'approvals' => Database::select('SELECT a.*, ur.name AS requester, ud.name AS decider FROM ticket_approvals a LEFT JOIN users ur ON ur.id = a.requested_by LEFT JOIN users ud ON ud.id = a.decided_by WHERE a.ticket_id = ? ORDER BY a.requested_at DESC', [$id]),
            'assignments' => Database::select('SELECT a.*, u.name AS tech_name, ub.name AS by_name FROM ticket_assignments a JOIN users u ON u.id = a.technician_id LEFT JOIN users ub ON ub.id = a.assigned_by WHERE a.ticket_id = ? ORDER BY a.assigned_at DESC', [$id]),
            'technicians' => $g->can('tickets.assign', $hotelId) ? TicketService::technicians($hotelId) : [],
            'canWork' => in_array($t['status'], S::WORKABLE, true) && ((TicketPolicy::isAssignee($t) && $g->can('tickets.work', $hotelId)) || $g->can('tickets.assign', $hotelId)),
            'canIssue' => in_array($t['status'], S::WORKABLE, true) && $g->can('inventory.issue', $hotelId) && (TicketPolicy::isAssignee($t) || $g->can('tickets.assign', $hotelId)),
            'canReverse' => !in_array($t['status'], S::CLOSED, true) && $g->can('inventory.issue', $hotelId),
            'canVerify' => $g->can('tickets.verify', $hotelId) && !TicketPolicy::isAssignee($t),
            'isStaffView' => $isStaffView,
            'priorities' => Database::select('SELECT id, name_th FROM priorities WHERE is_active = 1 ORDER BY level'),
            'pmItems' => [],
            'pmResults' => [],
            'maxMb' => (int) (setting('upload_max_mb') ?: 10),
        ];
        if ($t['pm_work_order_id']) {
            $data['pmItems'] = Database::select('SELECT checklist_item_id AS id,label,value_type,unit,sort_order FROM pm_work_order_checklist WHERE pm_work_order_id=? ORDER BY sort_order,checklist_item_id', [$t['pm_work_order_id']]);
            $res = Database::select('SELECT r.*, u.name AS recorder FROM pm_checklist_results r LEFT JOIN users u ON u.id = r.recorded_by WHERE r.pm_work_order_id = ?', [$t['pm_work_order_id']]);
            $data['pmResults'] = array_column($res, null, 'checklist_item_id');
        }
        $data['stockParts'] = $data['canIssue']
            ? Database::select('SELECT sp.id, sp.sku, sp.name, sp.unit, hs.qty_on_hand, hs.avg_cost FROM hotel_stock hs JOIN spare_parts sp ON sp.id = hs.part_id WHERE hs.hotel_id = ? AND hs.qty_on_hand > 0 AND sp.is_active = 1 ORDER BY sp.sku', [$hotelId])
            : [];
        return $this->view('tickets/show', $data);
    }

    public function edit(int $id): Response
    {
        TicketPolicy::findVisible($id);
        $t = TicketService::load($id);
        $this->authorize('tickets.update', (int) $t['hotel_id']);
        if (!S::isOpen($t['status'])) {
            $this->flash('warning', 'งานนี้ปิดแล้ว ไม่สามารถแก้ไขได้');
            return $this->redirect('/tickets/' . $id);
        }
        return $this->view('tickets/form', $this->formData((int) $t['hotel_id']) + [
            'title' => 'แก้ไข ' . $t['ticket_no'],
            'breadcrumbs' => [['งานแจ้งซ่อม', '/tickets'], [$t['ticket_no'], '/tickets/' . $id], ['แก้ไข', null]],
            'ticket' => $t, 'prefill' => [],
        ]);
    }

    public function update(int $id): Response
    {
        TicketPolicy::findVisible($id);
        $rules = $this->rules();
        unset($rules['requested_at'], $rules['reporter_name']);
        TicketService::update($id, $this->validate($rules));
        $this->flash('success', 'บันทึกการแก้ไขแล้ว');
        return $this->redirect('/tickets/' . $id);
    }

    public function action(int $id): Response
    {
        TicketPolicy::findVisible($id);
        $action = $this->request->str('action');
        $in = [
            'note' => mb_substr($this->request->str('note'), 0, 2000),
            'technician_id' => $this->request->int('technician_id'),
            'scheduled_start' => $this->request->str('scheduled_start') ?: null,
            'scheduled_end' => $this->request->str('scheduled_end') ?: null,
            'amount' => $this->request->str('amount'),
        ];
        TicketService::transition($id, $action, $in, array_slice($this->request->fileList('photos'), 0, 10));
        $label = TicketWorkflow::ACTIONS[$action]['label'] ?? $action;
        if ($this->request->wantsJson()) {
            return $this->json(['ok' => true, 'message' => 'ดำเนินการ "' . $label . '" แล้ว']);
        }
        $this->flash('success', 'ดำเนินการ "' . $label . '" เรียบร้อย');
        return $this->redirect('/tickets/' . $id);
    }

    public function comment(int $id): Response
    {
        TicketPolicy::findVisible($id);
        TicketService::addComment($id, $this->request->str('body'), (bool) $this->request->input('is_internal'));
        $this->flash('success', 'เพิ่มความคิดเห็นแล้ว');
        return $this->redirect('/tickets/' . $id . '#comments');
    }

    public function upload(int $id): Response
    {
        $t = TicketPolicy::findVisible($id);
        $stage = $this->request->str('stage', 'BEFORE');
        if ($stage === 'AFTER' && !TicketPolicy::isAssignee($t) && !can('tickets.assign', (int) $t['hotel_id'])) {
            $stage = 'BEFORE';
        }
        $n = TicketService::addAttachments($id, array_slice($this->request->fileList('files'), 0, 10), $stage);
        $this->flash('success', 'อัปโหลด ' . $n . ' ไฟล์แล้ว');
        return $this->redirect('/tickets/' . $id . '#photos');
    }

    public function download(int $id, int $attId): Response
    {
        TicketPolicy::findVisible($id);
        $a = Database::first('SELECT * FROM ticket_attachments WHERE id = ? AND ticket_id = ?', [$attId, $id]);
        $path = $a ? FileStorage::absolute($a['disk_path']) : null;
        if (!$a || !$path) {
            throw new NotFoundException();
        }
        return Response::file($path, $a['original_name'], $a['mime'], str_starts_with($a['mime'], 'image/') || $a['mime'] === 'application/pdf');
    }

    public function addWorkLog(int $id): Response
    {
        TicketPolicy::findVisible($id);
        $d = $this->validate([
            'started_at' => 'required|datetime',
            'ended_at' => 'required|datetime',
            'description' => 'required|string|min:3|max:2000',
            'technician_id' => 'nullable|int',
        ]);
        TicketService::addWorkLog($id, $d['started_at'], $d['ended_at'], $d['description'], $d['technician_id'] ?: null);
        $this->flash('success', 'บันทึกเวลาทำงานแล้ว');
        return $this->redirect('/tickets/' . $id . '#worklogs');
    }

    public function issuePart(int $id): Response
    {
        TicketPolicy::findVisible($id);
        $d = $this->validate(['part_id' => 'required|int', 'quantity' => 'required|decimal|min:0.01', 'note' => 'nullable|string|max:300']);
        InventoryService::issueToTicket($id, (int) $d['part_id'], (string) $d['quantity'], $d['note'] ?: null);
        $this->flash('success', 'เบิกอะไหล่และตัดสต็อกเรียบร้อย');
        return $this->redirect('/tickets/' . $id . '#parts');
    }

    public function reversePart(int $id, int $usageId): Response
    {
        TicketPolicy::findVisible($id);
        if (!Database::value('SELECT 1 FROM ticket_part_usages WHERE id = ? AND ticket_id = ?', [$usageId, $id])) {
            throw new NotFoundException();
        }
        InventoryService::reverseTicketUsage($usageId, $this->request->str('reason'));
        $this->flash('success', 'คืนอะไหล่เข้าคลังและยกเลิกต้นทุนแล้ว');
        return $this->redirect('/tickets/' . $id . '#parts');
    }

    public function decideApproval(int $id, int $approvalId): Response
    {
        TicketPolicy::findVisible($id);
        TicketService::decideApproval($id, $approvalId, $this->request->str('decision'), $this->request->str('note'));
        $this->flash('success', 'บันทึกผลการพิจารณาแล้ว');
        return $this->redirect('/tickets/' . $id);
    }
}

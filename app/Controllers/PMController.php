<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\{Database, Response};
use App\Services\{PMService, TicketService};
use App\Policies\TicketPolicy;

final class PMController extends ModuleController
{
    public function index(): Response
    {
        [$w,$p] = $this->scope('pm.view');
        return $this->listing('แผนบำรุงรักษา PM', Database::select("SELECT * FROM pm_plans WHERE $w AND task_name LIKE ? ORDER BY next_due_date LIMIT 500", [...$p,'%'.$this->request->str('q').'%']), ['plan_no' => 'เลขที่แผน','task_name' => 'งาน','frequency' => 'ความถี่','next_due_date' => 'กำหนดถัดไป','is_active' => 'เปิดใช้'], can('pm.manage') ? ['/pm/create' => 'เพิ่มแผน'] : [], fn ($r) => self::link('/pm/'.$r['id'], 'รายละเอียด'));
    }
    private function fields(?array $plan = null): array
    {
        $hotel = $plan['hotel_id'] ?? $this->request->int('hotel_id') ?? current_hotel_id();
        $ok = $hotel && can('pm.manage', (int)$hotel);
        $f = ['hotel_id' => ['โรงแรม','select',$this->hotelOptions('pm.manage'),true],
            'task_name' => ['ชื่องาน','text',[],true], 'description' => ['รายละเอียด','textarea'],
            'asset_id' => ['อุปกรณ์','select',$ok ? $this->options("SELECT id,CONCAT(asset_code,' · ',name) AS name FROM assets WHERE hotel_id=? AND deleted_at IS NULL AND status<>'DISPOSED'", [$hotel]) : []],
            'category_id' => ['หมวดงาน','select',$this->options('SELECT id,name_th AS name FROM maintenance_categories WHERE is_active=1'),true],
            'priority_id' => ['ความเร่งด่วน','select',$this->options('SELECT id,name_th AS name FROM priorities WHERE is_active=1'),true],
            'frequency' => ['ความถี่','select',PMService::FREQUENCIES,true], 'interval_days' => ['จำนวนวัน (กำหนดเอง)','number'],
            'planned_minutes' => ['เวลาที่วางแผน (นาที)','number',[],true], 'lead_days' => ['สร้างล่วงหน้า (วัน)','number'],
            'assigned_to' => ['ช่างผู้รับผิดชอบ','select',$ok ? array_column(TicketService::technicians((int)$hotel), 'name', 'id') : []],
            'start_date' => ['วันที่เริ่ม','date',[],true], 'checklist' => ['รายการตรวจ (หนึ่งรายการต่อบรรทัด; ใช้ label | CHECK/NUMBER/TEXT | หน่วย)','textarea']];
        if ($plan) {
            unset($f['hotel_id'],$f['start_date']);
            $f['next_due_date'] = ['กำหนดถัดไป','date',[],true];
        }
        return $f;
    }
    public function create(): Response
    {
        return $this->form('เพิ่มแผน PM', '/pm', $this->fields(), ['planned_minutes' => 60,'frequency' => 'MONTHLY','start_date' => \App\Helpers\Time::today()]);
    }
    private function save(?int $id): Response
    {
        $plan = $id ? $this->record('pm_plans', $id, 'pm.manage') : null;
        $hotel = $plan ? (int)$plan['hotel_id'] : $this->hotelForWrite('pm.manage');
        $rules = ['task_name' => 'required|string|min:3|max:200','description' => 'nullable|string|max:5000','asset_id' => 'nullable|int','category_id' => 'required|int','priority_id' => 'required|int','frequency' => 'required|string','interval_days' => 'nullable|int|min:1|max:3650','planned_minutes' => 'required|int|min:5|max:10080','lead_days' => 'nullable|int|min:0|max:60','assigned_to' => 'nullable|int','start_date' => ($id ? 'nullable' : 'required').'|date','next_due_date' => 'nullable|date','checklist' => 'nullable|string|max:15000'];
        $d = $this->validate($rules);
        $items = [];
        $existing = $id ? Database::select('SELECT * FROM pm_checklist_items WHERE pm_plan_id=? AND is_active=1 ORDER BY sort_order,id', [$id]) : [];
        foreach (preg_split('/\R/', $d['checklist'] ?? '') as $i => $line) {
            $v = array_map('trim', explode('|', $line));
            if ($v[0] !== '') {
                $items[] = ['id' => $existing[$i]['id'] ?? null,'label' => $v[0],'value_type' => $v[1] ?? 'CHECK','unit' => $v[2] ?? null];
            }
        }
        $id = PMService::savePlan($id, $hotel, $d, $items);
        return $this->saved('/pm/'.$id);
    }
    public function store(): Response
    {
        return $this->save(null);
    }
    public function update(int $id): Response
    {
        return $this->save($id);
    }
    public function edit(int $id): Response
    {
        $p = $this->record('pm_plans', $id, 'pm.manage');
        $p['checklist'] = implode("\n", array_map(fn ($r) => $r['label'].' | '.$r['value_type'].' | '.($r['unit'] ?? ''), Database::select('SELECT * FROM pm_checklist_items WHERE pm_plan_id=? AND is_active=1 ORDER BY sort_order,id', [$id])));
        return $this->form('แก้ไข '.$p['plan_no'], '/pm/'.$id.'/update', $this->fields($p), $p);
    }
    public function show(int $id): Response
    {
        $p = $this->record('pm_plans', $id, 'pm.view');
        $forms = [];
        if (can('pm.manage', (int)$p['hotel_id'])) {
            $forms = [['title' => 'เปิด / ปิดแผน','action' => '/pm/'.$id.'/toggle','fields' => []],['title' => 'สร้างงานตามรอบที่ถึงกำหนด','action' => '/pm/'.$id.'/generate','fields' => []]];
        }
        [$w,$v] = TicketPolicy::visibility('t');
        return $this->listing($p['plan_no'].' · '.$p['task_name'], Database::select("SELECT w.*,t.ticket_no,t.status AS ticket_status FROM pm_work_orders w JOIN tickets t ON t.id=w.ticket_id WHERE w.pm_plan_id=? AND $w ORDER BY w.due_date DESC", [$id,...$v]), ['ticket_no' => 'ใบงาน','due_date' => 'ครบกำหนด','ticket_status' => 'สถานะ'], can('pm.manage', (int)$p['hotel_id']) ? ['/pm/'.$id.'/edit' => 'แก้ไขแผน'] : [], fn ($r) => self::link('/tickets/'.$r['ticket_id'], 'เปิดใบงาน'), $forms, 'รอบถัดไป '.$p['next_due_date'].' · '.PMService::FREQUENCIES[$p['frequency']].' · '.($p['is_active'] ? 'เปิดใช้งาน' : 'ปิดใช้งาน'));
    }
    public function toggle(int $id): Response
    {
        $this->record('pm_plans', $id, 'pm.manage');
        PMService::toggle($id);
        return $this->saved('/pm/'.$id);
    }
    public function generate(int $id): Response
    {
        $this->record('pm_plans', $id, 'pm.manage');
        $stats = PMService::generateDue($id);
        return $this->saved('/pm/'.$id, 'สร้างงาน '.$stats['created'].' รายการ · ข้อผิดพลาด '.$stats['errors']);
    }
    public function saveChecklist(int $id): Response
    {
        $w = PMService::workOrder($id);
        TicketPolicy::findVisible((int)$w['ticket_id']);
        $d = $this->validate(['results' => 'required|array']);
        PMService::saveChecklist($id, $d['results']);
        return $this->saved('/tickets/'.$w['ticket_id']);
    }
    public function reportDefect(int $id): Response
    {
        $w = PMService::workOrder($id);
        TicketPolicy::findVisible((int)$w['ticket_id']);
        $d = $this->validate(['title' => 'required|string|min:3|max:200','description' => 'nullable|string|max:5000','priority_id' => 'required|int']);
        $ticket = PMService::reportDefect($id, $d['title'], $d['description'] ?? '', (int)$d['priority_id'], array_slice($this->request->fileList('photos'), 0, 10));
        return $this->saved('/tickets/'.$ticket);
    }
}

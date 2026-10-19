<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\{Auth,Database,HotelContext,Response};
use App\Core\Exceptions\{ForbiddenException,NotFoundException,ValidationException};
use App\Services\{Audit,SettingsService,TicketService};

final class SettingsController extends ModuleController
{
    private function globalGate(string $p = 'settings.manage'): void
    {
        if (!Auth::gate()->hasGlobal($p)) {
            throw new ForbiddenException();
        }
    }
    private function links(): array
    {
        $links = [];
        if (can('hotels.manage')) {
            $links['/settings/hotels'] = 'โรงแรม';
        }if (can('settings.manage')) {
            foreach (['departments' => 'แผนก','buildings' => 'อาคาร','floors' => 'ชั้น','locations' => 'ห้อง / พื้นที่','maintenance-categories' => 'หมวดงาน','asset-categories' => 'ประเภทอุปกรณ์','part-categories' => 'ประเภทอะไหล่','priorities' => 'ความเร่งด่วน'] as $k => $v) {
                $links['/settings/master/'.$k] = $v;
            }$links['/settings/sla'] = 'SLA';
        }if (can('audit.view')) {
            $links['/settings/audit'] = 'Audit log';
        }return $links;
    }
    private function generalFields(): array
    {
        return ['app_name' => ['ชื่อระบบ','text',[],true],'pagination_size' => ['รายการต่อหน้า','number',[],true],'upload_max_mb' => ['ขนาดไฟล์สูงสุด MB','number',[],true],'kpi_repeat_window_days' => ['ช่วงซ่อมซ้ำ (วัน)','number',[],true],'kpi_mttr_exclude_waiting' => ['MTTR หักเวลารอ','select',[0 => 'ไม่',1 => 'ใช่'],true],'cost_auto_approve_below' => ['อนุมัติอัตโนมัติเมื่อต่ำกว่า (0 = ปิด)','number',[],true]];
    }
    public function index(): Response
    {
        return $this->listing('ตั้งค่าระบบ', [], [], $this->links(), null, Auth::gate()->hasGlobal('settings.manage') ? [['title' => 'ตั้งค่าทั่วไป','action' => '/settings/general','fields' => $this->generalFields(),'values' => SettingsService::all()]] : [], 'สถานะใบงานใช้กฎการเปลี่ยนสถานะในระบบ เพื่อรักษาความสอดคล้องของการตรวจรับและ KPI');
    }
    public function saveGeneral(): Response
    {
        $this->globalGate();
        $d = $this->validate(['app_name' => 'required|string|min:3|max:150','pagination_size' => 'required|int|min:5|max:100','upload_max_mb' => 'required|int|min:1|max:50','kpi_repeat_window_days' => 'required|int|min:1|max:365','kpi_mttr_exclude_waiting' => 'required|in:0,1','cost_auto_approve_below' => 'required|decimal|min:0|max:999999999']);
        Database::transaction(function () use ($d) {
            foreach ($d as $k => $v) {
                SettingsService::set($k, $v, $this->userId());
            }Audit::log('settings.update', 'settings', null, null, null, $d);
        });
        return $this->saved('/settings');
    }
    private function hotelFields(): array
    {
        return ['code' => ['รหัส','text',[],true],'name_th' => ['ชื่อภาษาไทย','text',[],true],'name_en' => ['ชื่อภาษาอังกฤษ','text',[],true],'accent_color' => ['สีประจำโรงแรม','color',[],true],'address' => ['ที่อยู่','textarea'],'phone' => ['โทรศัพท์','text'],'email' => ['อีเมล','email'],'sort_order' => ['ลำดับ','number',[],true],'is_active' => ['เปิดใช้','select',[1 => 'ใช่',0 => 'ไม่'],true]];
    }
    public function hotels(): Response
    {
        $this->globalGate('hotels.manage');
        return $this->listing('ข้อมูลโรงแรม', Database::select('SELECT * FROM hotels ORDER BY sort_order'), ['code' => 'รหัส','name_th' => 'ชื่อไทย','name_en' => 'ชื่ออังกฤษ','accent_color' => 'สี','is_active' => 'เปิดใช้'], [], fn ($r) => self::link('/settings/hotels/'.$r['id'].'/edit', 'แก้ไข'), [['title' => 'เพิ่มโรงแรม','action' => '/settings/hotels','fields' => $this->hotelFields(),'values' => ['accent_color' => '#2563EB','sort_order' => 0,'is_active' => 1]]]);
    }
    public function editHotel(int $id): Response
    {
        $this->globalGate('hotels.manage');
        $r = Database::first('SELECT * FROM hotels WHERE id=?', [$id]) ?? throw new NotFoundException();
        return $this->form('แก้ไขโรงแรม', '/settings/hotels/'.$id.'/update', $this->hotelFields(), $r);
    }
    private function saveHotel(?int $id): Response
    {
        $this->globalGate('hotels.manage');
        $d = $this->validate(['code' => ['required','string','regex:/^[A-Z0-9]{2,10}$/'],'name_th' => 'required|string|max:150','name_en' => 'required|string|max:150','accent_color' => ['required','regex:/^#[0-9A-Fa-f]{6}$/'],'address' => 'nullable|string|max:500','phone' => 'nullable|string|max:50','email' => 'nullable|email|max:150','sort_order' => 'required|int|min:0|max:10000','is_active' => 'required|in:0,1']);
        if (Database::value('SELECT id FROM hotels WHERE code=? AND id<>?', [$d['code'],$id ?? 0])) {
            throw new ValidationException(['code' => 'รหัสโรงแรมซ้ำ']);
        }if ($id) {
            Database::update('hotels', $d, 'id=?', [$id]);
        } else {
            $id = Database::insert('hotels', $d);
        }HotelContext::reset();
        Audit::log('hotel.save', 'hotel', $id, $id, null, $d);
        return $this->saved('/settings/hotels');
    }
    public function storeHotel(): Response
    {
        return $this->saveHotel(null);
    }public function updateHotel(int $id): Response
    {
        return $this->saveHotel($id);
    }
    /** Fixed table/column allowlist: route segments never become SQL identifiers. */
    private function definition(string $entity): array
    {
        $base = ['name_th' => ['ชื่อภาษาไทย','text',[],true],'name_en' => ['ชื่อภาษาอังกฤษ','text',[],true],'is_active' => ['เปิดใช้','select',[1 => 'ใช่',0 => 'ไม่'],true]];
        $code = ['code' => ['รหัส','text',[],true]];
        return match($entity) {
            'departments' => ['departments',true,$code + $base],
            'buildings' => ['buildings',true,$code + ['name' => ['ชื่อ','text',[],true],'is_active' => $base['is_active']]],
            'floors' => ['floors',true,['building_id' => ['อาคาร','number',[],true],'name' => ['ชื่อชั้น','text',[],true],'level_no' => ['ลำดับชั้น','number',[],true],'is_active' => $base['is_active']]],
            'locations' => ['locations',true,$code + ['name' => ['ชื่อห้อง / พื้นที่','text',[],true],'building_id' => ['อาคาร','number'],'floor_id' => ['ชั้น','number'],'location_type' => ['ประเภท','select',['ROOM' => 'ห้อง','PUBLIC' => 'พื้นที่ส่วนกลาง','BACK_OF_HOUSE' => 'หลังบ้าน','OUTDOOR' => 'ภายนอก','OTHER' => 'อื่น ๆ'],true],'is_active' => $base['is_active']]],
            'maintenance-categories' => ['maintenance_categories',false,$code + $base + ['icon' => ['ไอคอน Lucide','text',[],true],'color' => ['สี','color',[],true]]],
            'asset-categories' => ['asset_categories',false,$code + $base],
            'part-categories' => ['part_categories',false,$base],
            'priorities' => ['priorities',false,$code + $base + ['color' => ['สี','color',[],true],'level' => ['ระดับ','number',[],true],'response_minutes' => ['SLA รับเรื่อง (นาที)','number',[],true],'resolution_minutes' => ['SLA ซ่อม (นาที)','number',[],true]]],
            default => throw new NotFoundException(),
        };
    }
    private function masterFields(string $entity, ?int $hotel): array
    {
        [$table,$scoped,$fields] = $this->definition($entity);
        if ($scoped) {
            $fields = ['hotel_id' => ['โรงแรม','select',$this->hotelOptions('settings.manage'),true]] + $fields;
        }
        if ($hotel && can('settings.manage', $hotel)) {
            if (isset($fields['building_id'])) {
                $fields['building_id'][1] = 'select';
                $fields['building_id'][2] = $this->options('SELECT id,name FROM buildings WHERE hotel_id=? AND is_active=1', [$hotel]);
            }
            if (isset($fields['floor_id'])) {
                $fields['floor_id'][1] = 'select';
                $fields['floor_id'][2] = $this->options('SELECT id,name FROM floors WHERE hotel_id=? AND is_active=1', [$hotel]);
            }
        }
        return $fields;
    }
    public function master(string $entity): Response
    {
        [$table,$scoped,$fields] = $this->definition($entity);
        if (!$scoped) {
            $this->globalGate();
        }
        [$w,$p] = $scoped ? $this->scope('settings.manage') : ['1=1',[]];
        $columns = ['id' => 'ID'];
        if ($scoped) {
            $columns['hotel_id'] = 'โรงแรม';
        }foreach ($fields as $k => $def) {
            $columns[$k] = $def[0];
        }
        return $this->listing('ข้อมูลหลัก · '.$entity, Database::select("SELECT * FROM `$table` WHERE $w ORDER BY id LIMIT 1000", $p), $columns, $this->links(), fn ($r) => self::link('/settings/master/'.$entity.'/'.$r['id'].'/edit', 'แก้ไข'), [['title' => 'เพิ่มรายการ','action' => '/settings/master/'.$entity,'fields' => $this->masterFields($entity, $this->request->int('hotel_id') ?? current_hotel_id()),'values' => ['is_active' => 1,'color' => '#64748B','icon' => 'wrench','level' => 1]]]);
    }
    public function editMaster(string $entity, int $id): Response
    {
        [$table,$scoped] = $this->definition($entity);
        if (!$scoped) {
            $this->globalGate();
        }$r = Database::first("SELECT * FROM `$table` WHERE id=?", [$id]) ?? throw new NotFoundException();
        if ($scoped) {
            $this->authorize('settings.manage', (int)$r['hotel_id']);
        }$f = $this->masterFields($entity, $scoped ? (int)$r['hotel_id'] : null);
        unset($f['hotel_id']);
        return $this->form('แก้ไข '.$entity, '/settings/master/'.$entity.'/'.$id.'/update', $f, $r);
    }
    private function saveMaster(string $entity, ?int $id): Response
    {
        [$table,$scoped,$fields] = $this->definition($entity);
        if (!$scoped) {
            $this->globalGate();
        }$hotel = $scoped ? ($id ? (int)$this->record($table, $id, 'settings.manage')['hotel_id'] : $this->hotelForWrite('settings.manage')) : null;
        $rules = [];
        foreach ($fields as $k => $def) {
            $rules[$k] = match($k) {
                'is_active' => 'required|in:0,1','code' => ['required','string','max:'.($table === 'asset_categories' ? 6 : 20),'regex:/^[A-Z0-9_-]+$/'],'name_th' => 'required|string|max:60','name_en' => 'required|string|max:60','name' => 'required|string|max:60','building_id' => ($entity === 'floors' ? 'required' : 'nullable').'|int','floor_id' => 'nullable|int','level_no' => 'required|int|min:-20|max:300','level' => 'required|int|min:1|max:255','response_minutes','resolution_minutes' => 'required|int|min:1|max:525600','color' => ['required','regex:/^#[0-9a-fA-F]{6}$/'],'icon' => ['required','regex:/^[a-z0-9-]{1,40}$/'],'location_type' => 'required|in:ROOM,PUBLIC,BACK_OF_HOUSE,OUTDOOR,OTHER'
            };
        }
        $d = $this->validate($rules);
        if (isset($fields['building_id'])) {
            TicketService::resolveLocation($hotel, $d['building_id'], $d['floor_id'] ?? null, null);
        }
        if (isset($d['code'])) {
            $params = [$d['code'],$id ?? 0];
            $where = 'code=? AND id<>?';
            if ($scoped) {
                $where .= ' AND hotel_id=?';
                $params[] = $hotel;
            }if (Database::value("SELECT id FROM `$table` WHERE $where", $params)) {
                throw new ValidationException(['code' => 'รหัสซ้ำ']);
            }
        }
        if ($id) {
            Database::update($table, $d, 'id=?', [$id]);
        } else {
            $id = Database::insert($table, $d + ($scoped ? ['hotel_id' => $hotel] : []));
        }Audit::log('master.save', $table, $id, $hotel, null, $d);
        return $this->saved('/settings/master/'.$entity);
    }
    public function storeMaster(string $entity): Response
    {
        return $this->saveMaster($entity, null);
    }public function updateMaster(string $entity, int $id): Response
    {
        return $this->saveMaster($entity, $id);
    }
    public function sla(): Response
    {
        [$w,$p] = $this->scope('settings.manage', 's.hotel_id');
        return $this->listing('กฎ SLA รายโรงแรม', Database::select("SELECT s.*,h.code AS hotel,p.name_th AS priority FROM sla_rules s JOIN hotels h ON h.id=s.hotel_id JOIN priorities p ON p.id=s.priority_id WHERE $w", $p), ['hotel' => 'โรงแรม','priority' => 'ความเร่งด่วน','response_minutes' => 'รับเรื่อง (นาที)','resolution_minutes' => 'ซ่อมเสร็จ (นาที)'], [], null, [['title' => 'ตั้งค่า SLA','action' => '/settings/sla','fields' => ['hotel_id' => ['โรงแรม','select',$this->hotelOptions('settings.manage'),true],'priority_id' => ['ความเร่งด่วน','select',$this->options('SELECT id,name_th AS name FROM priorities'),true],'response_minutes' => ['รับเรื่อง (นาที)','number',[],true],'resolution_minutes' => ['ซ่อมเสร็จ (นาที)','number',[],true]]]], 'ใช้กับใบงานใหม่เท่านั้น SLA ของใบงานเดิมคงตาม snapshot');
    }
    public function saveSla(): Response
    {
        $h = $this->hotelForWrite('settings.manage');
        $d = $this->validate(['priority_id' => 'required|int','response_minutes' => 'required|int|min:1|max:525600','resolution_minutes' => 'required|int|min:1|max:525600']);
        TicketService::priority($d['priority_id']);
        Database::execute('INSERT INTO sla_rules (hotel_id,priority_id,response_minutes,resolution_minutes,updated_by) VALUES (?,?,?,?,?) ON DUPLICATE KEY UPDATE response_minutes=VALUES(response_minutes),resolution_minutes=VALUES(resolution_minutes),updated_by=VALUES(updated_by)', [$h,$d['priority_id'],$d['response_minutes'],$d['resolution_minutes'],$this->userId()]);
        Audit::log('sla.save', 'sla_rule', null, $h, null, $d);
        return $this->saved('/settings/sla');
    }
    public function audit(): Response
    {
        [$w,$p] = $this->scope('audit.view');
        if (Auth::gate()->hasGlobal('audit.view')) {
            $w = '('.$w.' OR hotel_id IS NULL)';
        }return $this->listing('บันทึกการใช้งาน', Database::select("SELECT id,user_id,hotel_id,action,entity_type,entity_id,created_at FROM audit_logs WHERE $w AND action LIKE ? ORDER BY id DESC LIMIT 1000", [...$p,'%'.$this->request->str('q').'%']), ['id' => 'ID','user_id' => 'ผู้ใช้','hotel_id' => 'โรงแรม','action' => 'เหตุการณ์','entity_type' => 'ประเภท','entity_id' => 'รหัสรายการ','created_at' => 'เวลา UTC']);
    }
}

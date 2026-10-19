<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\{Auth,Database,HotelContext,Response};
use App\Core\Exceptions\{ForbiddenException,NotFoundException,ValidationException};
use App\Helpers\Csv;
use App\Services\{Audit,InventoryService as Stock,PurchaseService};

final class InventoryController extends ModuleController
{
    private function nav(): array
    {
        return ['/inventory' => 'ยอดสต็อก','/inventory/parts' => 'รายการอะไหล่','/inventory/movements' => 'ประวัติเคลื่อนไหว','/inventory/transfers' => 'โอนโรงแรม','/inventory/suppliers' => 'ผู้จำหน่าย','/inventory/consumption' => 'รายงานใช้วัสดุ','/inventory/purchase-requests' => 'คำขอซื้อ'];
    }
    private function partOptions(): array
    {
        return $this->options("SELECT id,CONCAT(sku,' · ',name) AS name FROM spare_parts WHERE is_active=1 ORDER BY sku");
    }
    private function stockFields(string $permission): array
    {
        return ['hotel_id' => ['โรงแรม','select',$this->hotelOptions($permission),true],'part_id' => ['อะไหล่','select',$this->partOptions(),true],'quantity' => ['จำนวน','number',[],true],'note' => ['เหตุผล / อ้างอิง','text',[],true]];
    }
    public function index(): Response
    {
        [$w,$p] = $this->scope('inventory.view', 'h.id');
        $rows = Database::select("SELECT hs.id,h.code AS hotel,s.sku,s.name,s.unit,COALESCE(hs.qty_on_hand,0) AS qty_on_hand,COALESCE(hs.avg_cost,0) AS avg_cost,COALESCE(hs.reorder_point,s.reorder_point) AS reorder_point,hs.bin_location,ROUND(COALESCE(hs.qty_on_hand*hs.avg_cost,0),2) AS stock_value FROM hotels h CROSS JOIN spare_parts s LEFT JOIN hotel_stock hs ON hs.hotel_id=h.id AND hs.part_id=s.id WHERE $w AND s.is_active=1 AND (s.name LIKE ? OR s.sku LIKE ?) ORDER BY h.code,s.sku LIMIT 1000", [...$p,'%'.$this->request->str('q').'%','%'.$this->request->str('q').'%']);
        $forms = [];
        foreach (['receive' => ['รับเข้าสต็อก','inventory.receive'],'issue' => ['เบิกใช้ทั่วไป','inventory.issue'],'return' => ['คืนเข้าสต็อก','inventory.receive'],'adjust' => ['ตรวจนับ / ปรับยอด','inventory.adjust']] as $op => [$title,$permission]) {
            if (can($permission)) {
                $fields = $this->stockFields($permission);
                if ($op === 'receive') {
                    $fields['unit_cost'] = ['ต้นทุนต่อหน่วย','number',[],true];
                }if ($op === 'adjust') {
                    $fields['quantity'][0] = 'ยอดที่ตรวจนับได้';
                }$forms[] = ['title' => $title,'action' => '/inventory/'.$op,'fields' => $fields];
            }
        }
        return $this->listing('คลังอะไหล่รายโรงแรม', $rows, ['hotel' => 'โรงแรม','sku' => 'SKU','name' => 'อะไหล่','qty_on_hand' => 'คงเหลือ','unit' => 'หน่วย','avg_cost' => 'ต้นทุนเฉลี่ย','stock_value' => 'มูลค่า','reorder_point' => 'จุดสั่งซื้อ'], $this->nav(), null, $forms, 'การเบิกใช้กับใบงานให้บันทึกจากหน้าใบงาน เพื่อเชื่อมต้นทุนงานซ่อม');
    }
    public function parts(): Response
    {
        $q = '%'.$this->request->str('q').'%';
        $rows = Database::select('SELECT id,sku,name,unit,cost_price,is_active FROM spare_parts WHERE name LIKE ? OR sku LIKE ? ORDER BY sku LIMIT 500', [$q,$q]);
        return $this->listing('รายการอะไหล่กลาง', $rows, ['sku' => 'SKU','name' => 'ชื่อ','unit' => 'หน่วย','cost_price' => 'ราคาอ้างอิง','is_active' => 'เปิดใช้'], Auth::gate()->hasGlobal('inventory.manage') ? ['/inventory/parts/create' => 'เพิ่มอะไหล่'] : [], fn ($r) => self::link('/inventory/parts/'.$r['id'], 'รายละเอียด'));
    }
    private function partFields(): array
    {
        return ['sku' => ['SKU','text',[],true],'name' => ['ชื่อ','text',[],true],'unit' => ['หน่วย','text',[],true],'category_id' => ['ประเภท','select',$this->options('SELECT id,name_th AS name FROM part_categories WHERE is_active=1')],'description' => ['รายละเอียด','textarea'],'cost_price' => ['ราคาอ้างอิง','number',[],true],'min_stock' => ['ขั้นต่ำ','number',[],true],'reorder_point' => ['จุดสั่งซื้อ','number',[],true],'is_active' => ['เปิดใช้','select',[1 => 'ใช่',0 => 'ไม่'],true]];
    }
    private function catalogGate(): void
    {
        if (!Auth::gate()->hasGlobal('inventory.manage')) {
            throw new ForbiddenException('รายการกลางแก้ไขได้โดยผู้ดูแลคลังระดับกลุ่ม');
        }
    }
    public function createPart(): Response
    {
        $this->catalogGate();
        return $this->form('เพิ่มอะไหล่', '/inventory/parts', $this->partFields(), ['cost_price' => '0','min_stock' => '0','reorder_point' => '0','is_active' => 1]);
    }
    private function savePart(?int $id): Response
    {
        $this->catalogGate();
        $d = $this->validate(['sku' => 'required|string|max:40','name' => 'required|string|min:2|max:200','unit' => 'required|string|max:20','category_id' => 'nullable|int','description' => 'nullable|string|max:1000','cost_price' => 'required|decimal|min:0|max:999999999999','min_stock' => 'required|decimal|min:0|max:9999999999','reorder_point' => 'required|decimal|min:0|max:9999999999','is_active' => 'required|in:0,1']);
        if (Database::value('SELECT id FROM spare_parts WHERE sku=? AND id<>?', [$d['sku'],$id ?? 0])) {
            throw new ValidationException(['sku' => 'SKU ซ้ำ']);
        }if ($d['category_id'] && !Database::value('SELECT 1 FROM part_categories WHERE id=?', [$d['category_id']])) {
            throw new ValidationException(['category_id' => 'ประเภทไม่ถูกต้อง']);
        }if ($id) {
            Database::update('spare_parts', $d, 'id=?', [$id]);
        } else {
            $id = Database::insert('spare_parts', $d);
        }Audit::log('part.save', 'spare_part', $id, null, null, $d);
        return $this->saved('/inventory/parts/'.$id);
    }
    public function storePart(): Response
    {
        return $this->savePart(null);
    }
    public function updatePart(int $id): Response
    {
        return $this->savePart($id);
    }
    public function editPart(int $id): Response
    {
        $this->catalogGate();
        $p = Database::first('SELECT * FROM spare_parts WHERE id=?', [$id]);
        if (!$p) {
            throw new NotFoundException();
        }return $this->form('แก้ไข '.$p['sku'], '/inventory/parts/'.$id.'/update', $this->partFields(), $p);
    }
    public function showPart(int $id): Response
    {
        $part = Database::first('SELECT * FROM spare_parts WHERE id=?', [$id]);
        if (!$part) {
            throw new NotFoundException();
        }[$w,$p] = $this->scope('inventory.view', 's.hotel_id');
        return $this->listing($part['sku'].' · '.$part['name'], Database::select("SELECT s.*,h.code FROM hotel_stock s JOIN hotels h ON h.id=s.hotel_id WHERE s.part_id=? AND $w", [$id,...$p]), ['code' => 'โรงแรม','qty_on_hand' => 'คงเหลือ','avg_cost' => 'ต้นทุนเฉลี่ย','min_stock' => 'ขั้นต่ำ','reorder_point' => 'จุดสั่งซื้อ','bin_location' => 'ตำแหน่งเก็บ'], Auth::gate()->hasGlobal('inventory.manage') ? ['/inventory/parts/'.$id.'/edit' => 'แก้ไขรายการกลาง'] : [], fn ($r) => can('inventory.manage', (int)$r['hotel_id']) ? self::link('/inventory/stock/'.$r['id'].'/settings', 'ตั้งค่าสต็อก') : '');
    }
    private function move(string $op): Response
    {
        $perm = match($op) {
            'receive','return' => 'inventory.receive','issue' => 'inventory.issue','adjust' => 'inventory.adjust'
        };
        $hotel = $this->hotelForWrite($perm);
        $d = $this->validate(['part_id' => 'required|int','quantity' => 'required|decimal|min:'.($op === 'adjust' ? '0' : '0.01').'|max:9999999999','unit_cost' => 'nullable|decimal|min:0|max:9999999999','note' => 'required|string|min:3|max:500']);
        match($op) {
            'receive' => Stock::receive($hotel, (int)$d['part_id'], $d['quantity'], $d['unit_cost'] ?? '0', null, $d['note']),'issue' => Stock::issue($hotel, (int)$d['part_id'], $d['quantity'], $d['note']),'return' => Stock::returnStock($hotel, (int)$d['part_id'], $d['quantity'], $d['note']),'adjust' => Stock::adjustTo($hotel, (int)$d['part_id'], $d['quantity'], $d['note'])
        };
        return $this->saved('/inventory');
    }
    public function receive(): Response
    {
        return $this->move('receive');
    } public function issue(): Response
    {
        return $this->move('issue');
    } public function returnStock(): Response
    {
        return $this->move('return');
    } public function adjust(): Response
    {
        return $this->move('adjust');
    }
    public function stockSettings(int $id): Response
    {
        $r = $this->record('hotel_stock', $id, 'inventory.manage');
        $d = $this->validate(['min_stock' => 'nullable|decimal|min:0|max:9999999999','reorder_point' => 'nullable|decimal|min:0|max:9999999999','bin_location' => 'nullable|string|max:50']);
        Database::update('hotel_stock', $d, 'id=?', [$id]);
        Audit::log('stock.settings', 'hotel_stock', $id, (int)$r['hotel_id'], null, $d);
        return $this->saved('/inventory');
    }
    public function editStock(int $id): Response
    {
        $r = $this->record('hotel_stock', $id, 'inventory.manage');
        return $this->form('ตั้งค่าสต็อก', '/inventory/stock/'.$id.'/settings', ['min_stock' => ['ขั้นต่ำ','number'],'reorder_point' => ['จุดสั่งซื้อ','number'],'bin_location' => ['ตำแหน่งเก็บ','text']], $r);
    }
    private function movementRows(bool $export = false): array
    {
        [$w,$p] = $this->scope($export ? 'reports.export' : 'inventory.view', 'm.hotel_id');
        [$v,$s] = $this->scope('inventory.view', 'm.hotel_id');
        return Database::select("SELECT m.id,h.code AS hotel,s.sku,s.name,m.movement_type,m.quantity,m.unit_cost,m.total_cost,m.balance_after,m.note,m.created_at FROM stock_movements m JOIN spare_parts s ON s.id=m.part_id JOIN hotels h ON h.id=m.hotel_id WHERE $w AND $v AND (s.name LIKE ? OR s.sku LIKE ?) ORDER BY m.id DESC LIMIT 10000", [...$p,...$s,'%'.$this->request->str('q').'%','%'.$this->request->str('q').'%']);
    }
    public function movements(): Response
    {
        return $this->listing('ประวัติเคลื่อนไหว', $this->movementRows(), ['hotel' => 'โรงแรม','sku' => 'SKU','name' => 'อะไหล่','movement_type' => 'ประเภท','quantity' => 'จำนวน','balance_after' => 'คงเหลือ','note' => 'เหตุผล','created_at' => 'เวลา UTC'], can('reports.export') ? ['/inventory/movements/export' => 'ส่งออก CSV'] : []);
    }
    public function exportMovements(): Response
    {
        Audit::log('inventory.export', 'stock_movement', null);
        return Csv::response('stock-movements.csv', ['ID','โรงแรม','SKU','ชื่อ','ประเภท','จำนวน','ต้นทุน','รวม','ยอดหลังรายการ','เหตุผล','เวลา UTC'], $this->movementRows(true));
    }
    public function consumption(): Response
    {
        [$w,$p] = $this->scope('inventory.view', 'm.hotel_id');
        return $this->listing('การใช้อะไหล่รายเดือน', Database::select("SELECT h.code AS hotel,DATE_FORMAT(m.created_at,'%Y-%m') AS month,s.sku,s.name,SUM(-m.quantity) AS used_qty,SUM(m.total_cost) AS amount FROM stock_movements m JOIN spare_parts s ON s.id=m.part_id JOIN hotels h ON h.id=m.hotel_id WHERE $w AND m.movement_type='ISSUE' GROUP BY h.code,month,s.sku,s.name ORDER BY month DESC LIMIT 1000", $p), ['hotel' => 'โรงแรม','month' => 'เดือน UTC','sku' => 'SKU','name' => 'อะไหล่','used_qty' => 'จำนวนเบิก','amount' => 'มูลค่าเบิก']);
    }
    public function transfers(): Response
    {
        [$w,$p] = $this->scope('inventory.view', 'from_hotel_id');
        [$v,$s] = $this->scope('inventory.view', 'to_hotel_id');
        return $this->listing('โอนอะไหล่ระหว่างโรงแรม', Database::select("SELECT * FROM stock_transfers WHERE ($w OR $v) ORDER BY id DESC LIMIT 500", [...$p,...$s]), ['transfer_no' => 'เลขที่','from_hotel_id' => 'ต้นทาง','to_hotel_id' => 'ปลายทาง','status' => 'สถานะ'], can('inventory.transfer') ? ['/inventory/transfers/create' => 'ขอโอน'] : [], fn ($r) => self::link('/inventory/transfers/'.$r['id'], 'รายละเอียด'));
    }
    public function createTransfer(): Response
    {
        return $this->form('ขอโอนอะไหล่', '/inventory/transfers', ['hotel_id' => ['โรงแรมต้นทาง','select',$this->hotelOptions('inventory.transfer'),true],'to_hotel_id' => ['โรงแรมปลายทาง','select',array_column(array_filter(HotelContext::all(), fn ($h) => $h['is_active']), 'name_th', 'id'),true],'part_id' => ['อะไหล่','select',$this->partOptions(),true],'quantity' => ['จำนวน','number',[],true],'note' => ['หมายเหตุ','textarea']]);
    }
    public function storeTransfer(): Response
    {
        $h = $this->hotelForWrite('inventory.transfer');
        $d = $this->validate(['to_hotel_id' => 'required|int','part_id' => 'required|int','quantity' => 'required|decimal|min:0.01|max:9999999999','note' => 'nullable|string|max:1000']);
        $id = Stock::requestTransfer($h, (int)$d['to_hotel_id'], [['part_id' => (int)$d['part_id'],'quantity' => $d['quantity']]], $d['note']);
        return $this->saved('/inventory/transfers/'.$id);
    }
    private function transfer(int $id): array
    {
        $r = Database::first('SELECT * FROM stock_transfers WHERE id=?', [$id]);
        if (!$r) {
            throw new NotFoundException();
        }if (!can('inventory.view', (int)$r['from_hotel_id']) && !can('inventory.view', (int)$r['to_hotel_id'])) {
            throw new ForbiddenException();
        }return $r;
    }
    public function showTransfer(int $id): Response
    {
        $r = $this->transfer($id);
        $forms = [];
        foreach (['approve' => 'อนุมัติและส่งออก','reject' => 'ปฏิเสธ','receive' => 'รับเข้าปลายทาง','cancel' => 'ยกเลิก'] as $op => $label) {
            $allowed = match($op) {
                'approve','reject' => $r['status'] === 'REQUESTED' && can('inventory.transfer_approve', (int)$r['from_hotel_id']),'receive' => $r['status'] === 'IN_TRANSIT' && can('inventory.transfer', (int)$r['to_hotel_id']),'cancel' => in_array($r['status'], ['REQUESTED','IN_TRANSIT']) && can('inventory.transfer_approve', (int)$r['from_hotel_id'])
            };
            if ($allowed) {
                $forms[] = ['title' => $label,'action' => '/inventory/transfers/'.$id.'/'.$op,'fields' => ['reason' => ['เหตุผล / หมายเหตุ','text',[],true]]];
            }
        }return $this->listing($r['transfer_no'].' · '.$r['status'], Database::select('SELECT i.*,s.sku,s.name FROM stock_transfer_items i JOIN spare_parts s ON s.id=i.part_id WHERE i.transfer_id=?', [$id]), ['sku' => 'SKU','name' => 'ชื่อ','quantity' => 'จำนวน','unit_cost' => 'ต้นทุน'], [], null, $forms);
    }
    public function transferAction(int $id, string $op): Response
    {
        $this->transfer($id);
        Stock::transferAction($id, $op, $this->request->str('reason'));
        return $this->saved('/inventory/transfers/'.$id);
    }
    public function suppliers(): Response
    {
        [$w,$p] = $this->scope('inventory.view');
        $forms = [];
        if (can('inventory.manage')) {
            $forms[] = ['title' => 'เพิ่มผู้จำหน่าย','action' => '/inventory/suppliers','fields' => $this->supplierFields()];
        }return $this->listing('ผู้จำหน่าย', Database::select("SELECT * FROM suppliers WHERE (hotel_id IS NULL OR $w) AND name LIKE ? ORDER BY name LIMIT 500", [...$p,'%'.$this->request->str('q').'%']), ['name' => 'ชื่อ','contact_name' => 'ผู้ติดต่อ','phone' => 'โทรศัพท์','email' => 'อีเมล'], [], fn ($r) => $r['hotel_id'] && can('inventory.manage', (int)$r['hotel_id']) ? self::link('/inventory/suppliers/'.$r['id'].'/edit', 'แก้ไข') : '', $forms);
    }
    private function supplierFields(): array
    {
        return ['hotel_id' => ['โรงแรม','select',$this->hotelOptions('inventory.manage'),true],'name' => ['ชื่อ','text',[],true],'contact_name' => ['ผู้ติดต่อ','text'],'phone' => ['โทรศัพท์','text'],'email' => ['อีเมล','email'],'tax_id' => ['เลขประจำตัวผู้เสียภาษี','text'],'address' => ['ที่อยู่','textarea'],'is_contractor' => ['ผู้รับเหมา','select',[0 => 'ไม่',1 => 'ใช่'],true],'is_active' => ['เปิดใช้','select',[1 => 'ใช่',0 => 'ไม่'],true]];
    }
    private function saveSupplier(?int $id): Response
    {
        $h = $id ? (int)$this->record('suppliers', $id, 'inventory.manage')['hotel_id'] : $this->hotelForWrite('inventory.manage');
        $d = $this->validate(['name' => 'required|string|max:200','contact_name' => 'nullable|string|max:150','phone' => 'nullable|string|max:50','email' => 'nullable|email|max:150','tax_id' => 'nullable|string|max:20','address' => 'nullable|string|max:500','is_contractor' => 'required|in:0,1','is_active' => 'required|in:0,1']);
        if ($id) {
            Database::update('suppliers', $d, 'id=?', [$id]);
        } else {
            $id = Database::insert('suppliers', $d + ['hotel_id' => $h]);
        }Audit::log('supplier.save', 'supplier', $id, $h, null, $d);
        return $this->saved('/inventory/suppliers');
    }
    public function storeSupplier(): Response
    {
        return $this->saveSupplier(null);
    } public function updateSupplier(int $id): Response
    {
        return $this->saveSupplier($id);
    }
    public function editSupplier(int $id): Response
    {
        $r = $this->record('suppliers', $id, 'inventory.manage');
        $f = $this->supplierFields();
        unset($f['hotel_id']);
        return $this->form('แก้ไขผู้จำหน่าย', '/inventory/suppliers/'.$id.'/update', $f, $r);
    }
    public function purchaseRequests(): Response
    {
        [$w,$p] = $this->scope('purchase.request');
        [$a,$b] = $this->scope('purchase.approve');
        [$c,$d] = $this->scope('inventory.receive');
        return $this->listing('คำขอซื้อ', Database::select("SELECT * FROM purchase_requests WHERE ($w OR $a OR $c) ORDER BY id DESC LIMIT 500", [...$p,...$b,...$d]), ['pr_no' => 'เลขที่','hotel_id' => 'โรงแรม','total_amount' => 'รวม','status' => 'สถานะ'], can('purchase.request') ? ['/inventory/purchase-requests/create' => 'ขอซื้อ'] : [], fn ($r) => self::link('/inventory/purchase-requests/'.$r['id'], 'รายละเอียด'));
    }
    public function createPurchaseRequest(): Response
    {
        return $this->form('ขอซื้ออะไหล่', '/inventory/purchase-requests', ['hotel_id' => ['โรงแรม','select',$this->hotelOptions('purchase.request'),true],'part_id' => ['อะไหล่','select',$this->partOptions(),true],'quantity' => ['จำนวน','number',[],true],'unit_cost' => ['ราคาต่อหน่วย','number',[],true],'supplier_id' => ['ผู้จำหน่าย','number'],'note' => ['หมายเหตุ','textarea']]);
    }
    public function storePurchaseRequest(): Response
    {
        $h = $this->hotelForWrite('purchase.request');
        $d = $this->validate(['part_id' => 'required|int','quantity' => 'required|decimal|min:0.01|max:9999999999','unit_cost' => 'required|decimal|min:0|max:9999999999','supplier_id' => 'nullable|int','note' => 'nullable|string|max:1000']);
        return $this->saved('/inventory/purchase-requests/'.PurchaseService::create($h, $d));
    }
    private function purchase(int $id): array
    {
        $r = Database::first('SELECT * FROM purchase_requests WHERE id=?', [$id]);
        if (!$r) {
            throw new NotFoundException();
        }if (!can('purchase.request', (int)$r['hotel_id']) && !can('purchase.approve', (int)$r['hotel_id']) && !can('inventory.receive', (int)$r['hotel_id'])) {
            throw new ForbiddenException();
        }return $r;
    }
    public function showPurchaseRequest(int $id): Response
    {
        $r = $this->purchase($id);
        $forms = [];
        foreach (['approve' => 'อนุมัติ','reject' => 'ปฏิเสธ','receive' => 'รับเข้าสต็อก','cancel' => 'ยกเลิก'] as $op => $caption) {
            $ok = match($op) {
                'approve','reject' => $r['status'] === 'SUBMITTED' && can('purchase.approve', (int)$r['hotel_id']) && (int)$r['requested_by'] !== $this->userId(),'receive' => $r['status'] === 'APPROVED' && can('inventory.receive', (int)$r['hotel_id']),'cancel' => $r['status'] === 'SUBMITTED' && (int)$r['requested_by'] === $this->userId()
            };
            if ($ok) {
                $forms[] = ['title' => $caption,'action' => '/inventory/purchase-requests/'.$id.'/'.$op,'fields' => ['reason' => ['หมายเหตุ','text',[],$op === 'reject']]];
            }
        }return $this->listing($r['pr_no'].' · '.$r['status'], Database::select('SELECT i.*,s.sku,s.name FROM purchase_request_items i JOIN spare_parts s ON s.id=i.part_id WHERE i.pr_id=?', [$id]), ['sku' => 'SKU','name' => 'อะไหล่','quantity' => 'จำนวน','unit_cost' => 'ราคา','total_cost' => 'รวม'], [], null, $forms);
    }
    public function purchaseRequestAction(int $id, string $op): Response
    {
        $this->purchase($id);
        PurchaseService::action($id, $op, $this->request->str('reason'));
        return $this->saved('/inventory/purchase-requests/'.$id);
    }
}

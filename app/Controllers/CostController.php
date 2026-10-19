<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\{Database,Response};
use App\Core\Exceptions\{NotFoundException,ValidationException};
use App\Helpers\{Csv,Dec,Time};
use App\Services\{Audit,CostService,KPIService,FileStorage};

final class CostController extends ModuleController
{
    private function rows(bool $export = false): array
    {
        [$w,$p] = $this->scope('costs.view', 'c.hotel_id');
        if ($export) {
            [$v,$h] = $this->scope('reports.export', 'c.hotel_id');
            $w .= ' AND '.$v;
            array_push($p, ...$h);
        }
        [$from,$to] = KPIService::range($this->request->str('from'), $this->request->str('to'));
        $status = $this->request->str('status');
        if (in_array($status, ['PENDING','APPROVED','REJECTED','VOID'], true)) {
            $w .= ' AND c.status=?';
            $p[] = $status;
        }
        return Database::select("SELECT c.id,h.code AS hotel,c.hotel_id,cc.name_th AS category,c.description,c.quantity,c.unit_cost,c.total_cost,c.expense_date,c.status,c.source,c.created_by,c.document_path FROM cost_entries c JOIN hotels h ON h.id=c.hotel_id JOIN cost_categories cc ON cc.id=c.category_id WHERE $w AND c.expense_date BETWEEN ? AND ? AND c.description LIKE ? ORDER BY c.id DESC LIMIT 10000", [...$p,$from,$to,'%'.$this->request->str('q').'%']);
    }
    public function index(): Response
    {
        $links = ['/costs/budgets' => 'งบประมาณ','/costs/reports' => 'รายงานต้นทุน'];
        if (can('costs.create')) {
            $links['/costs/create'] = 'บันทึกค่าใช้จ่าย';
        }if (can('reports.export')) {
            $links['/costs/export'] = 'ส่งออก CSV';
        }
        return $this->listing('ต้นทุนและค่าใช้จ่าย', $this->rows(), ['hotel' => 'โรงแรม','expense_date' => 'วันที่','category' => 'ประเภท','description' => 'รายละเอียด','total_cost' => 'รวม','status' => 'สถานะ'], $links, function ($r) {
            $html = $r['document_path'] ? self::link('/costs/'.$r['id'].'/document', 'เอกสาร') : '';
            if (can('costs.approve', (int)$r['hotel_id']) && $r['source'] === 'MANUAL') {
                if ($r['status'] === 'PENDING' && (int)$r['created_by'] !== $this->userId()) {
                    $html .= self::button('/costs/'.$r['id'].'/approve', 'อนุมัติ').self::button('/costs/'.$r['id'].'/reject', 'ปฏิเสธ', [], true);
                }if ($r['status'] === 'APPROVED') {
                    $html .= self::button('/costs/'.$r['id'].'/void', 'ยกเลิกต้นทุน', [], true);
                }
            }return $html;
        }, [], 'แสดงค่าใช้จ่ายเดือนปัจจุบัน; กรองช่วงเวลาผ่าน from และ to (YYYY-MM-DD) ในรายงาน');
    }
    public function create(): Response
    {
        $hotel = $this->request->int('hotel_id') ?? current_hotel_id();
        $ok = $hotel && can('costs.create', $hotel);
        return $this->form('บันทึกค่าใช้จ่าย', '/costs', [
            'hotel_id' => ['โรงแรม','select',$this->hotelOptions('costs.create'),true],
            'ticket_id' => ['ใบงาน','select',$ok ? $this->options("SELECT id,CONCAT(ticket_no,' · ',title) AS name FROM tickets WHERE hotel_id=? ORDER BY id DESC LIMIT 500", [$hotel]) : []],
            'asset_id' => ['อุปกรณ์','select',$ok ? $this->options("SELECT id,CONCAT(asset_code,' · ',name) AS name FROM assets WHERE hotel_id=? AND deleted_at IS NULL", [$hotel]) : []],
            'category' => ['ประเภทต้นทุน','select',['CONTRACTOR' => 'ผู้รับเหมา','REPLACEMENT' => 'เปลี่ยนอุปกรณ์','OTHER' => 'อื่น ๆ'],true],
            'description' => ['รายละเอียด','textarea',[],true],'quantity' => ['จำนวน','number',[],true],'unit_cost' => ['ราคาต่อหน่วย','number',[],true],'expense_date' => ['วันที่ค่าใช้จ่าย','date',[],true],
            'supplier_id' => ['ผู้จำหน่าย','select',$ok ? $this->options('SELECT id,name FROM suppliers WHERE is_active=1 AND (hotel_id IS NULL OR hotel_id=?)', [$hotel]) : []], 'document' => ['เอกสารประกอบ','file']], ['quantity' => 1,'expense_date' => Time::today()]);
    }
    public function store(): Response
    {
        $h = $this->hotelForWrite('costs.create');
        $d = $this->validate(['ticket_id' => 'nullable|int','asset_id' => 'nullable|int','category' => 'required|in:CONTRACTOR,REPLACEMENT,OTHER','description' => 'required|string|min:3|max:500','quantity' => 'required|decimal|min:0.01|max:9999999999','unit_cost' => 'required|decimal|min:0|max:9999999999','expense_date' => 'required|date','supplier_id' => 'nullable|int']);
        CostService::createManual($h, $d, $this->request->file('document'), $this->userId());
        return $this->saved('/costs');
    }
    public function export(): Response
    {
        $rows = $this->rows(true);
        $data = array_map(fn ($r) => [$r['id'],$r['hotel'],$r['expense_date'],$r['category'],$r['description'],$r['quantity'],$r['unit_cost'],$r['total_cost'],$r['status']], $rows);
        Audit::log('cost.export', 'cost_entry', null);
        return Csv::response('maintenance-costs.csv', ['ID','โรงแรม','วันที่','ประเภท','รายละเอียด','จำนวน','ราคาต่อหน่วย','รวม','สถานะ'], $data);
    }
    private function decide(int $id, string $op): Response
    {
        $this->record('cost_entries', $id, 'costs.approve');
        $reason = $this->request->str('reason');
        if ($op !== 'approve' && mb_strlen($reason) < 3) {
            throw new ValidationException(['reason' => 'กรุณาระบุเหตุผล']);
        }CostService::decide($id, $op, $reason, $this->userId());
        return $this->saved('/costs');
    }
    public function approve(int $id): Response
    {
        return $this->decide($id, 'approve');
    }public function reject(int $id): Response
    {
        return $this->decide($id, 'reject');
    }public function void(int $id): Response
    {
        return $this->decide($id, 'void');
    }
    public function document(int $id): Response
    {
        $r = $this->record('cost_entries', $id, 'costs.view');
        $p = $r['document_path'] ? FileStorage::absolute($r['document_path']) : null;
        if (!$p) {
            throw new NotFoundException();
        }return Response::file($p, $r['document_name'], $r['document_mime'], true);
    }
    public function budgets(): Response
    {
        [$w,$p] = $this->scope('costs.view', 'b.hotel_id');
        $year = $this->request->int('year', (int)substr(Time::today(), 0, 4));
        $rows = Database::select("SELECT b.*,h.code AS hotel,c.name_th AS category FROM budgets b JOIN hotels h ON h.id=b.hotel_id LEFT JOIN cost_categories c ON c.id=b.category_id WHERE $w AND b.year=? ORDER BY h.code,b.month,b.category_id", [...$p,$year]);
        foreach ($rows as &$r) {
            $from = sprintf('%04d-%02d-01', $r['year'], $r['month'] ?: 1);
            $to = $r['month'] ? (new \DateTimeImmutable($from))->format('Y-m-t') : $r['year'].'-12-31';
            $r['actual'] = (string)Database::value("SELECT COALESCE(SUM(total_cost),0) FROM cost_entries WHERE hotel_id=? AND status='APPROVED' AND expense_date BETWEEN ? AND ?".($r['category_id'] ? ' AND category_id=?' : ''), [$r['hotel_id'],$from,$to,...($r['category_id'] ? [$r['category_id']] : [])]);
            $r['variance'] = Dec::sub($r['amount'], $r['actual'], 2);
            $r['utilization'] = Dec::cmp($r['amount'], '0') > 0 ? Dec::mul(Dec::div($r['actual'], $r['amount'], 4), '100', 2).' %' : '—';
            $r['month'] = $r['month'] ?: 'ทั้งปี';
            $r['category'] = $r['category'] ?: 'ทุกประเภท';
        }unset($r);
        $forms = can('budgets.manage') ? [['title' => 'กำหนดงบประมาณ','action' => '/costs/budgets','fields' => ['hotel_id' => ['โรงแรม','select',$this->hotelOptions('budgets.manage'),true],'year' => ['ปี ค.ศ.','number',[],true],'month' => ['เดือน (ว่าง = ทั้งปี)','select',array_combine(range(1, 12), range(1, 12))],'category_id' => ['ประเภท (ว่าง = ทั้งหมด)','select',$this->options('SELECT id,name_th AS name FROM cost_categories')],'amount' => ['งบประมาณ','number',[],true],'note' => ['หมายเหตุ','text']],'values' => ['year' => $year]]] : [];
        return $this->listing('งบประมาณ ปี '.$year, $rows, ['hotel' => 'โรงแรม','month' => 'เดือน','category' => 'ประเภท','amount' => 'งบ','actual' => 'ใช้จริง','variance' => 'คงเหลือ','utilization' => 'ใช้ไป'], [], null, $forms, 'งบรวม งบรายประเภท งบปี และงบเดือนเป็นมุมมองที่ซ้อนกัน ไม่ควรนำงบทุกแถวมาบวกรวม');
    }
    public function saveBudget(): Response
    {
        $h = $this->hotelForWrite('budgets.manage');
        $d = $this->validate(['year' => 'required|int|min:2000|max:2200','month' => 'nullable|int|min:1|max:12','category_id' => 'nullable|int','amount' => 'required|decimal|min:0|max:999999999999','note' => 'nullable|string|max:500']);
        if ($d['category_id'] && !Database::value('SELECT 1 FROM cost_categories WHERE id=?', [$d['category_id']])) {
            throw new ValidationException(['category_id' => 'ประเภทไม่ถูกต้อง']);
        }
        Database::execute('INSERT INTO budgets (hotel_id,year,month,category_id,amount,note,created_by) VALUES (?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE amount=VALUES(amount),note=VALUES(note)', [$h,$d['year'],$d['month'],$d['category_id'],$d['amount'],$d['note'],$this->userId()]);
        Audit::log('budget.save', 'budget', null, $h, null, $d);
        return $this->saved('/costs/budgets?year='.$d['year']);
    }
    public function reports(): Response
    {
        [$from,$to] = KPIService::range($this->request->str('from'), $this->request->str('to'));
        [$w,$p] = $this->scope('costs.view', 'c.hotel_id');
        $rows = Database::select("SELECT h.code AS hotel,DATE_FORMAT(c.expense_date,'%Y-%m') AS month,COALESCE(d.name_th,'ไม่ระบุ') AS department,COALESCE(a.asset_code,'ไม่ระบุ') AS asset,COALESCE(t.ticket_no,'ไม่ระบุ') AS ticket,SUM(c.total_cost) AS amount FROM cost_entries c JOIN hotels h ON h.id=c.hotel_id LEFT JOIN tickets t ON t.id=c.ticket_id LEFT JOIN departments d ON d.id=t.reporter_department_id LEFT JOIN assets a ON a.id=c.asset_id WHERE $w AND c.status='APPROVED' AND c.expense_date BETWEEN ? AND ? GROUP BY h.code,month,d.name_th,a.asset_code,t.ticket_no ORDER BY amount DESC LIMIT 1000", [...$p,$from,$to]);
        return $this->view('reports/costs', ['title' => 'รายงานต้นทุน','rows' => $rows,'from' => $from,'to' => $to]);
    }
}

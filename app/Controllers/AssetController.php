<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\{Database, Response};
use App\Core\Exceptions\NotFoundException;
use App\Services\{AssetService, FileStorage};
use App\Policies\TicketPolicy;

final class AssetController extends ModuleController
{
    public function index(): Response
    {
        [$w,$p] = $this->scope('assets.view', 'a.hotel_id');
        $q = '%' . $this->request->str('q') . '%';
        $rows = Database::select("SELECT a.*, h.code AS hotel FROM assets a JOIN hotels h ON h.id=a.hotel_id WHERE $w AND a.deleted_at IS NULL AND (a.name LIKE ? OR a.asset_code LIKE ?) ORDER BY a.id DESC LIMIT 500", [...$p,$q,$q]);
        $links = ['/assets/labels' => 'พิมพ์ QR'];
        if (can('assets.create')) {
            $links['/assets/create'] = 'เพิ่มอุปกรณ์';
        }
        return $this->listing('ทะเบียนอุปกรณ์', $rows, ['asset_code' => 'รหัส','hotel' => 'โรงแรม','name' => 'ชื่อ','brand' => 'ยี่ห้อ','status' => 'สถานะ','warranty_end' => 'ประกันสิ้นสุด'], $links, fn ($r) => self::link('/assets/'.$r['id'], 'รายละเอียด'));
    }
    private function fields(): array
    {
        $hotel = $this->request->int('hotel_id') ?? current_hotel_id();
        // No property-specific options are returned until an authorized hotel is selected.
        $allowed = $hotel && (can('assets.create', $hotel) || can('assets.update', $hotel));
        return [
            'hotel_id' => ['โรงแรม','select',$this->hotelOptions('assets.create'),true],
            'category_id' => ['ประเภทอุปกรณ์','select',$this->options('SELECT id,name_th AS name FROM asset_categories WHERE is_active=1'),true],
            'name' => ['ชื่ออุปกรณ์','text',[],true], 'brand' => ['ยี่ห้อ','text'], 'model' => ['รุ่น','text'], 'serial_no' => ['หมายเลขเครื่อง','text'],
            'location_id' => ['ห้อง / พื้นที่','select',$allowed ? $this->options('SELECT id,name FROM locations WHERE hotel_id=? AND is_active=1', [$hotel]) : []],
            'department_id' => ['แผนก','select',$allowed ? $this->options('SELECT id,name_th AS name FROM departments WHERE hotel_id=?', [$hotel]) : []],
            'purchase_date' => ['วันที่ซื้อ','date'], 'purchase_cost' => ['ราคาซื้อ','number'], 'warranty_start' => ['ประกันเริ่ม','date'], 'warranty_end' => ['ประกันสิ้นสุด','date'],
            'asset_condition' => ['สภาพ','select',AssetService::CONDITIONS,true], 'notes' => ['หมายเหตุ','textarea'], 'image' => ['รูปอุปกรณ์','file'],
        ];
    }
    private function data(): array
    {
        return $this->validate(['name' => 'required|string|min:2|max:200','category_id' => 'required|int','brand' => 'nullable|string|max:100','model' => 'nullable|string|max:100','serial_no' => 'nullable|string|max:100','location_id' => 'nullable|int','department_id' => 'nullable|int','purchase_date' => 'nullable|date','purchase_cost' => 'nullable|decimal|min:0|max:999999999999','warranty_start' => 'nullable|date','warranty_end' => 'nullable|date','asset_condition' => 'required|in:GOOD,FAIR,POOR,CRITICAL','notes' => 'nullable|string|max:5000']) + ['building_id' => null,'floor_id' => null,'supplier_id' => null];
    }
    public function create(): Response
    {
        return $this->form('เพิ่มอุปกรณ์', '/assets', $this->fields(), ['asset_condition' => 'GOOD']);
    }
    public function store(): Response
    {
        $id = AssetService::create($this->hotelForWrite('assets.create'), $this->data(), $this->request->file('image'));
        return $this->saved('/assets/'.$id);
    }
    public function edit(int $id): Response
    {
        $a = $this->record('assets', $id, 'assets.update');
        $fields = $this->fields();
        unset($fields['hotel_id']);
        $fields['location_id'][2] = $this->options('SELECT id,name FROM locations WHERE hotel_id=?', [$a['hotel_id']]);
        $fields['department_id'][2] = $this->options('SELECT id,name_th AS name FROM departments WHERE hotel_id=?', [$a['hotel_id']]);
        return $this->form('แก้ไข '.$a['asset_code'], '/assets/'.$id.'/update', $fields, $a);
    }
    public function update(int $id): Response
    {
        $this->record('assets', $id, 'assets.update');
        AssetService::update($id, $this->data(), $this->request->file('image'));
        return $this->saved('/assets/'.$id);
    }
    public function show(int $id): Response
    {
        $a = $this->record('assets', $id, 'assets.view');
        [$tw,$tp] = TicketPolicy::visibility('t');
        return $this->view('assets/show', ['title' => $a['asset_code'],'a' => $a,
            'history' => Database::select('SELECT * FROM asset_history WHERE asset_id=? ORDER BY id DESC LIMIT 200', [$id]),
            'tickets' => Database::select("SELECT t.id,t.ticket_no,t.title,t.status FROM tickets t WHERE t.asset_id=? AND $tw ORDER BY t.id DESC LIMIT 100", [$id,...$tp]),
            'attachments' => Database::select('SELECT * FROM asset_attachments WHERE asset_id=?', [$id]),
            'cost' => can('costs.view', (int)$a['hotel_id']) ? AssetService::costSummary($id) : null]);
    }
    public function changeStatus(int $id): Response
    {
        AssetService::changeStatus($id, $this->request->str('status'), $this->request->str('reason'));
        return $this->saved('/assets/'.$id);
    }
    public function upload(int $id): Response
    {
        $this->record('assets', $id, 'assets.update');
        foreach (array_slice($this->request->fileList('files'), 0, 10) as $f) {
            AssetService::addAttachment($id, $f, $this->request->str('kind', 'DOCUMENT'));
        } return $this->saved('/assets/'.$id);
    }
    public function download(int $id, int $attId): Response
    {
        $this->record('assets', $id, 'assets.view');
        $f = Database::first('SELECT * FROM asset_attachments WHERE id=? AND asset_id=?', [$attId,$id]);
        $path = $f ? FileStorage::absolute($f['disk_path']) : null;
        if (!$f || !$path) {
            throw new NotFoundException();
        }
        return Response::file($path, $f['original_name'], $f['mime'], true);
    }
    public function image(int $id): Response
    {
        $a = $this->record('assets', $id, 'assets.view');
        $path = $a['image_path'] ? FileStorage::absolute($a['image_path']) : null;
        if (!$path) {
            throw new NotFoundException();
        }
        return Response::file($path, 'asset-image', (string)(new \finfo(FILEINFO_MIME_TYPE))->file($path), true);
    }
    public function qr(string $token): Response
    {
        $a = Database::first('SELECT id,hotel_id FROM assets WHERE qr_token=? AND deleted_at IS NULL', [$token]);
        if (!$a) {
            throw new NotFoundException();
        }
        $this->authorize('assets.view', (int)$a['hotel_id']);
        return $this->redirect('/assets/'.$a['id']);
    }
    public function labels(): Response
    {
        [$w,$p] = $this->scope('assets.view');
        return $this->view('assets/labels', ['title' => 'ป้าย QR อุปกรณ์','rows' => Database::select("SELECT id,asset_code,name,qr_token FROM assets WHERE $w AND deleted_at IS NULL ORDER BY asset_code LIMIT 200", $p)]);
    }
}

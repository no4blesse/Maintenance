<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\{Auth,Config,Database,Response};
use App\Core\Exceptions\{ForbiddenException,HttpException,NotFoundException,ValidationException};
use App\Helpers\Time;
use App\Services\{Audit,NotificationService};
use App\Services\Line\{WebhookService,MessageBuilder};

final class LineBotController extends ModuleController
{
    public function webhook(): Response
    {
        $raw = $this->request->raw();
        if (!WebhookService::verifySignature($raw, $this->request->header('x-line-signature'), (string)Config::get('line.channel_secret'))) {
            throw new HttpException(403, 'Invalid signature');
        }
        if (strlen($raw) > 1048576) {
            throw new HttpException(413, 'Payload too large');
        }
        $d = json_decode($raw, true);
        if (!is_array($d) || !is_array($d['events'] ?? null)) {
            throw new HttpException(400, 'Invalid payload');
        }
        return $this->json(['ok' => true] + WebhookService::handle($d));
    }
    private function destination(int $id): array
    {
        $d = Database::first('SELECT * FROM line_destinations WHERE id=?', [$id]) ?? throw new NotFoundException();
        if ($d['hotel_id']) {
            $this->authorize('line.manage', (int)$d['hotel_id']);
        } elseif (!Auth::gate()->hasGlobal('line.manage')) {
            throw new ForbiddenException();
        }
        return $d;
    }
    public function index(): Response
    {
        [$w,$p] = $this->scope('line.manage');
        if (Auth::gate()->hasGlobal('line.manage')) {
            $w = '('.$w.' OR hotel_id IS NULL)';
        }
        $rows = Database::select("SELECT id,hotel_id,display_name,status,last_event_at FROM line_destinations WHERE $w ORDER BY id DESC", $p);
        $fields = ['hotel_id' => ['โรงแรม','select',$this->hotelOptions('line.manage'),true],'event_type' => ['ประเภทแจ้งเตือน','select',array_map(fn ($r) => $r[1], MessageBuilder::EVENTS),true],'is_enabled' => ['เปิดใช้','select',[1 => 'ใช่',0 => 'ไม่'],true]];
        $forms = [['title' => 'กฎการแจ้งเตือน','action' => '/line/rules','fields' => $fields]];
        foreach ($rows as $d) {
            if ($d['status'] !== 'LEFT') {
                $forms[] = ['title' => 'ผูกกลุ่ม #'.$d['id'].' '.($d['display_name'] ?? ''),'action' => '/line/destinations/'.$d['id'].'/bind','fields' => ['hotel_id' => $fields['hotel_id'],'display_name' => ['ชื่อกลุ่มที่แสดง','text',[],true]],'values' => ['hotel_id' => $d['hotel_id'],'display_name' => $d['display_name']]];
            }
        }
        return $this->listing('LINE Bot', $rows, ['id' => 'รหัสกลุ่ม','hotel_id' => 'โรงแรม','display_name' => 'ชื่อกลุ่ม','status' => 'สถานะ','last_event_at' => 'เหตุการณ์ล่าสุด UTC'], ['/line/queue' => 'คิวและผลการส่ง'], function ($r) {
            return $r['status'] === 'BOUND' ? self::button('/line/destinations/'.$r['id'].'/test', 'ทดสอบแจ้งเตือน').self::button('/line/destinations/'.$r['id'].'/unbind', 'ยกเลิกการผูก') : '';
        }, $forms, (NotificationService::isConnected() ? 'เชื่อมต่อการส่งจริงตามค่าที่ตั้งไว้ใน .env (ยังไม่ยืนยันการส่ง)' : 'NOT CONNECTED · ใช้ MOCK: บันทึกการจำลองเท่านั้น ไม่ส่งข้อความไป LINE').' · Webhook: '.absolute_url('/webhook/line').' · กลุ่มจะปรากฏหลังผ่านการตรวจลายเซ็น webhook');
    }
    public function bind(int $id): Response
    {
        $hotel = $this->hotelForWrite('line.manage');
        $d = $this->validate(['display_name' => 'required|string|max:150']);
        Database::transaction(function () use ($id, $hotel, $d) {
            Database::first('SELECT id FROM line_destinations WHERE id=? FOR UPDATE', [$id]);
            $old = $this->destination($id);
            if ($old['status'] === 'LEFT') {
                throw new ValidationException(['destination' => 'Bot ออกจากกลุ่มแล้ว กรุณาเชิญเข้ากลุ่มก่อน']);
            }Database::update('line_destinations', ['hotel_id' => $hotel,'display_name' => $d['display_name'],'status' => 'BOUND','bound_by' => $this->userId(),'bound_at' => Time::now()], 'id=?', [$id]);
            Audit::log('line.bind', 'line_destination', $id, $hotel, ['hotel' => $old['hotel_id']], ['hotel' => $hotel]);
        });
        return $this->saved('/line');
    }
    public function unbind(int $id): Response
    {
        $d = $this->destination($id);
        Database::update('line_destinations', ['hotel_id' => null,'status' => 'DISABLED','bound_at' => null,'bound_by' => null], 'id=?', [$id]);
        Audit::log('line.unbind', 'line_destination', $id, $d['hotel_id'] ? (int)$d['hotel_id'] : null);
        return $this->saved('/line');
    }
    public function test(int $id): Response
    {
        $d = $this->destination($id);
        if ($d['status'] !== 'BOUND') {
            throw new ValidationException(['destination' => 'ต้องผูกกลุ่มก่อน']);
        }NotificationService::queue('TEST', (int)$d['hotel_id'], ['lines' => [['ข้อความทดสอบ','SUNGROUP Maintenance Center']]], 'TEST:'.bin2hex(random_bytes(12)), $id);
        return $this->saved('/line/queue', 'เพิ่มข้อความทดสอบในคิวแล้ว');
    }
    public function saveRules(): Response
    {
        $hotel = $this->hotelForWrite('line.manage');
        $d = $this->validate(['event_type' => 'required|in:'.implode(',', array_keys(MessageBuilder::EVENTS)),'is_enabled' => 'required|in:0,1']);
        Database::execute('INSERT INTO notification_rules (hotel_id,event_type,is_enabled,updated_by) VALUES (?,?,?,?) ON DUPLICATE KEY UPDATE is_enabled=VALUES(is_enabled),updated_by=VALUES(updated_by)', [$hotel,$d['event_type'],$d['is_enabled'],$this->userId()]);
        Audit::log('line.rule', 'notification_rule', null, $hotel, null, $d);
        return $this->saved('/line');
    }
    public function queue(): Response
    {
        [$w,$p] = $this->scope('line.manage', 'q.hotel_id');
        $rows = Database::select("SELECT q.id,q.hotel_id,q.event_type,q.status,q.attempts,q.last_error,q.created_at,(SELECT l.transport FROM notification_logs l WHERE l.queue_id=q.id ORDER BY l.id DESC LIMIT 1) AS transport FROM notification_queue q WHERE $w ORDER BY q.id DESC LIMIT 500", $p);
        return $this->listing('คิวและผลการส่ง LINE', $rows, ['id' => 'ID','hotel_id' => 'โรงแรม','event_type' => 'เหตุการณ์','status' => 'สถานะคิว','transport' => 'ช่องทาง','attempts' => 'ครั้ง','last_error' => 'ข้อผิดพลาด','created_at' => 'เวลา UTC'], ['/line' => 'ตั้งค่า LINE'], fn ($r) => in_array($r['status'], ['FAILED','DEAD']) ? self::button('/line/queue/'.$r['id'].'/retry', 'ลองใหม่') : '', [['title' => 'ประมวลผลคิวของโรงแรมที่มีสิทธิ์','action' => '/line/process','fields' => []]], 'SENT + MOCK หมายถึงจำลองสำเร็จเท่านั้น ไม่ได้ส่งไป LINE');
    }
    public function retry(int $id): Response
    {
        $r = $this->record('notification_queue', $id, 'line.manage');
        if (!in_array($r['status'], ['FAILED','DEAD'], true)) {
            throw new ValidationException(['queue' => 'ลองใหม่ได้เฉพาะรายการที่ล้มเหลว']);
        }Database::execute("UPDATE notification_queue SET status='PENDING',attempts=0,next_attempt_at=?,locked_at=NULL WHERE id=? AND status IN ('FAILED','DEAD')", [Time::now(),$id]);
        Audit::log('line.retry', 'notification_queue', $id, (int)$r['hotel_id']);
        return $this->saved('/line/queue');
    }
    public function processNow(): Response
    {
        [$w,$p] = $this->scope('line.manage');
        $ids = Database::column("SELECT id FROM notification_queue WHERE $w AND status IN ('PENDING','FAILED') AND next_attempt_at<=? ORDER BY id LIMIT 50", [...$p,Time::now()]);
        foreach ($ids as $id) {
            NotificationService::process(1, (int)$id);
        }return $this->saved('/line/queue', 'ประมวลผลคิวแล้ว กรุณาดูสถานะและช่องทางในผลการส่ง');
    }
}

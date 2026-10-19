<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\{Auth, Config, Database, Validator};
use App\Core\Exceptions\{BusinessRuleException, HttpException, ValidationException};
use App\Helpers\Time;

/** Anonymous creation only. Priority, ownership and all operational fields are server controlled. */
final class PublicRepairService
{
    public static function photoLimitBytes(): int
    {
        $ini = trim((string) ini_get('upload_max_filesize'));
        $bytes = (int) $ini * match (strtolower(substr($ini, -1))) {
            'g' => 1073741824, 'm' => 1048576, 'k' => 1024, default => 1
        };
        return min(5 * 1048576, max(1, (int) setting('upload_max_mb', 10)) * 1048576, $bytes > 0 ? $bytes : PHP_INT_MAX);
    }

    public static function submit(array $input, array $photos, string $ip, string $nonce): array
    {
        $d = Validator::validate($input, [
            'hotel_id' => 'required|int', 'reporter_kind' => 'required|in:GUEST,STAFF',
            'reporter_name' => 'required|string|min:2|max:150', 'contact' => 'nullable|string|max:150',
            'location_text' => 'required|string|min:1|max:150', 'title' => 'required|string|min:3|max:200',
            'description' => 'required|string|min:5|max:5000', 'category_id' => 'required|int',
        ], ['hotel_id' => 'โรงแรม', 'reporter_kind' => 'ประเภทผู้แจ้ง', 'reporter_name' => 'ชื่อผู้แจ้ง', 'contact' => 'ช่องทางติดต่อ', 'location_text' => 'ห้อง / จุดที่พบปัญหา', 'title' => 'เรื่องที่แจ้ง', 'description' => 'รายละเอียด', 'category_id' => 'ประเภทปัญหา']);
        if (!preg_match('/^[a-f0-9]{64}$/D', $nonce)) {
            throw new ValidationException(['form_token' => 'กรุณาเปิดแบบฟอร์มใหม่']);
        }
        if (count($photos) > 3) {
            throw new ValidationException(['photos' => 'แนบรูปได้ไม่เกิน 3 รูป']);
        }
        foreach ($photos as $file) {
            if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
                throw new ValidationException(['photos' => 'รูปอัปโหลดไม่สมบูรณ์หรือใหญ่เกินกำหนด กรุณาเลือกรูปใหม่']);
            }
            $path = (string) ($file['tmp_name'] ?? '');
            if (!is_file($path) || filesize($path) > self::photoLimitBytes() || filesize($path) <= 0 || !in_array((new \finfo(FILEINFO_MIME_TYPE))->file($path), ['image/jpeg', 'image/png', 'image/webp'], true) || @getimagesize($path) === false) {
                throw new ValidationException(['photos' => 'กรุณาแนบรูป JPG, PNG หรือ WebP ตามขนาดที่ระบุ']);
            }
        }
        $hash = hash('sha256', $nonce);
        $ipHash = hash_hmac('sha256', $ip, (string) Config::get('app.key'));
        $locks = ['public-form:'.substr($hash, 0, 48), 'public-ip:'.substr($ipHash, 0, 48)];
        $acquired = [];
        $stored = [];
        $previous = Auth::user();
        try {
            foreach ($locks as $lock) {
                if ((int) Database::value('SELECT GET_LOCK(?, 3)', [$lock]) !== 1) {
                    throw new HttpException(429, 'มีคำขอกำลังดำเนินการ กรุณาลองใหม่อีกสักครู่');
                }
                $acquired[] = $lock;
            }
            $existing = Database::first('SELECT t.ticket_no,t.hotel_id,h.name_th AS hotel_name FROM public_repair_submissions s JOIN tickets t ON t.id=s.ticket_id JOIN hotels h ON h.id=t.hotel_id WHERE s.submission_hash=?', [$hash]);
            if ($existing) {
                if ((int) $existing['hotel_id'] !== (int) $d['hotel_id']) {
                    throw new ValidationException(['form_token' => 'แบบฟอร์มไม่ใช่ของโรงแรมนี้ กรุณาเปิดลิงก์แจ้งซ่อมของโรงแรมใหม่']);
                }
                unset($existing['hotel_id']);
                return $existing;
            }
            $since = Time::nowImmutable()->modify('-1 hour')->format('Y-m-d H:i:s');
            if ((int) Database::value('SELECT COUNT(*) FROM public_repair_submissions WHERE ip_hash=? AND created_at>=?', [$ipHash, $since]) >= 10) {
                throw new HttpException(429, 'ส่งคำขอครบจำนวนที่รองรับแล้ว กรุณาติดต่อแผนกต้อนรับ หรือกลับมาลองใหม่ภายหลัง');
            }
            Auth::actingAs(null);
            return Database::transaction(function () use ($d, $photos, $hash, $ipHash, &$stored): array {
                $priority = Database::value("SELECT id FROM priorities WHERE code='NORMAL' AND is_active=1");
                if (!$priority) {
                    throw new BusinessRuleException('ยังไม่พร้อมรับแจ้งซ่อม กรุณาติดต่อแผนกต้อนรับ');
                }
                $id = TicketService::create((int) $d['hotel_id'], [
                    'title' => $d['title'], 'description' => $d['description'], 'category_id' => $d['category_id'],
                    'priority_id' => $priority, 'location_text' => $d['location_text'], 'reporter_name' => $d['reporter_name'],
                    'notes' => 'แจ้งผ่านหน้าสาธารณะ · '.($d['reporter_kind'] === 'GUEST' ? 'ลูกค้า / ผู้เข้าพัก' : 'พนักงานไม่มีบัญชี').' · รอเจ้าหน้าที่ตรวจสอบข้อมูลและความเร่งด่วน',
                ], [], ['system' => true, 'is_demo' => DemoShowcaseSeeder::isTestWorkspace()]);
                foreach ($photos as $photo) {
                    $s = FileStorage::store($photo, 'images', 'tickets/'.(int) $d['hotel_id']);
                    $stored[] = $s['disk_path'];
                    Database::insert('ticket_attachments', $s + ['ticket_id' => $id, 'hotel_id' => $d['hotel_id'], 'stage' => 'BEFORE', 'uploaded_by' => null, 'created_at' => Time::now()]);
                }
                Database::insert('public_repair_submissions', ['ticket_id' => $id, 'submission_hash' => $hash, 'ip_hash' => $ipHash, 'reporter_kind' => $d['reporter_kind'], 'contact' => $d['contact'], 'created_at' => Time::now()]);
                TicketService::log($id, (int) $d['hotel_id'], 'NEW', 'NEW', 'public_intake', 'รับแจ้งจากหน้าสาธารณะ ไม่ผ่านการยืนยันตัวตน', null);
                Audit::log('ticket.public_intake', 'ticket', $id, (int) $d['hotel_id']);
                return Database::first('SELECT t.ticket_no,h.name_th AS hotel_name FROM tickets t JOIN hotels h ON h.id=t.hotel_id WHERE t.id=?', [$id]);
            });
        } catch (\Throwable $e) {
            foreach ($stored as $path) {
                if ($absolute = FileStorage::absolute($path)) {
                    @unlink($absolute);
                }
            }
            throw $e;
        } finally {
            Auth::actingAs($previous);
            foreach (array_reverse($acquired) as $lock) {
                Database::value('SELECT RELEASE_LOCK(?)', [$lock]);
            }
        }
    }
}

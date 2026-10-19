<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Auth;
use App\Core\Database;
use App\Core\Exceptions\BusinessRuleException;
use App\Core\Exceptions\ForbiddenException;
use App\Core\Exceptions\NotFoundException;
use App\Core\Exceptions\ValidationException;
use App\Helpers\Dec;
use App\Helpers\Time;

/**
 * Asset register. Asset code format: {CATEGORY}-{HOTEL}-{NNNN} e.g. AC-NT-0305
 * (sequence per category+hotel, concurrency-safe via number_sequences).
 * qr_token is a random opaque token; the QR URL still requires login +
 * assets.view at the asset's hotel.
 */
final class AssetService
{
    public const STATUSES = ['ACTIVE' => 'ใช้งานปกติ', 'UNDER_MAINTENANCE' => 'อยู่ระหว่างซ่อม', 'OUT_OF_SERVICE' => 'งดใช้งาน', 'DISPOSED' => 'จำหน่ายออก'];
    public const CONDITIONS = ['GOOD' => 'ดี', 'FAIR' => 'พอใช้', 'POOR' => 'ทรุดโทรม', 'CRITICAL' => 'วิกฤต'];

    /** @return array<string, mixed> cleaned fields */
    private static function clean(int $hotelId, array $d): array
    {
        $cat = Database::first('SELECT id, code FROM asset_categories WHERE id = ? AND is_active = 1', [(int) $d['category_id']]);
        if (!$cat) {
            throw new ValidationException(['category_id' => 'ประเภทอุปกรณ์ไม่ถูกต้อง']);
        }
        $loc = TicketService::resolveLocation(
            $hotelId,
            !empty($d['building_id']) ? (int) $d['building_id'] : null,
            !empty($d['floor_id']) ? (int) $d['floor_id'] : null,
            !empty($d['location_id']) ? (int) $d['location_id'] : null
        );
        $dept = !empty($d['department_id']) ? (int) $d['department_id'] : null;
        if ($dept && !Database::value('SELECT 1 FROM departments WHERE id = ? AND hotel_id = ?', [$dept, $hotelId])) {
            throw new ValidationException(['department_id' => 'แผนกไม่อยู่ในโรงแรมนี้']);
        }
        $sup = !empty($d['supplier_id']) ? (int) $d['supplier_id'] : null;
        if ($sup && !Database::value('SELECT 1 FROM suppliers WHERE id = ? AND (hotel_id IS NULL OR hotel_id = ?)', [$sup, $hotelId])) {
            throw new ValidationException(['supplier_id' => 'ผู้จำหน่ายไม่ถูกต้อง']);
        }
        $ws = ($d['warranty_start'] ?? '') ?: null;
        $we = ($d['warranty_end'] ?? '') ?: null;
        if ($ws && $we && $we < $ws) {
            throw new ValidationException(['warranty_end' => 'วันสิ้นสุดประกันต้องไม่ก่อนวันเริ่ม']);
        }
        $cond = (string) ($d['asset_condition'] ?? 'GOOD');
        return [
            '_cat' => $cat,
            'category_id' => (int) $cat['id'],
            'name' => mb_substr(trim((string) $d['name']), 0, 200),
            'brand' => ($d['brand'] ?? '') !== '' ? mb_substr((string) $d['brand'], 0, 100) : null,
            'model' => ($d['model'] ?? '') !== '' ? mb_substr((string) $d['model'], 0, 100) : null,
            'serial_no' => ($d['serial_no'] ?? '') !== '' ? mb_substr((string) $d['serial_no'], 0, 100) : null,
            'building_id' => $loc['building_id'],
            'floor_id' => $loc['floor_id'],
            'location_id' => $loc['location_id'],
            'department_id' => $dept,
            'supplier_id' => $sup,
            'purchase_date' => ($d['purchase_date'] ?? '') ?: null,
            'purchase_cost' => isset($d['purchase_cost']) && $d['purchase_cost'] !== '' ? Dec::norm($d['purchase_cost'], 2) : null,
            'warranty_start' => $ws,
            'warranty_end' => $we,
            'asset_condition' => isset(self::CONDITIONS[$cond]) ? $cond : 'GOOD',
            'notes' => ($d['notes'] ?? '') !== '' ? (string) $d['notes'] : null,
        ];
    }

    public static function create(int $hotelId, array $d, ?array $image = null, array $opts = []): int
    {
        if (empty($opts['system']) && !Auth::gate()->can('assets.create', $hotelId)) {
            throw new ForbiddenException();
        }
        $data = self::clean($hotelId, $d);
        $cat = $data['_cat'];
        unset($data['_cat']);
        return Database::transaction(function () use ($hotelId, $data, $cat, $image, $opts) {
            $hotelCode = (string) Database::value('SELECT code FROM hotels WHERE id = ?', [$hotelId]);
            $customCode = strtoupper(trim((string) ($opts['asset_code'] ?? '')));
            if ($customCode !== '') {
                if (!preg_match('/^[A-Z0-9\-]{3,30}$/', $customCode)) {
                    throw new ValidationException(['asset_code' => 'รหัสอุปกรณ์ใช้ได้เฉพาะ A-Z 0-9 และ -']);
                }
                if (Database::value('SELECT 1 FROM assets WHERE asset_code = ?', [$customCode])) {
                    throw new ValidationException(['asset_code' => 'รหัสอุปกรณ์นี้ถูกใช้แล้ว']);
                }
                $code = $customCode;
            } else {
                do {
                    $code = sprintf('%s-%s-%04d', $cat['code'], $hotelCode, NumberSequence::next('asset:' . $cat['code'], $hotelId));
                } while (Database::value('SELECT 1 FROM assets WHERE asset_code = ?', [$code]));
            }
            $img = $image ? FileStorage::store($image, 'images', 'assets/' . $hotelId) : null;
            $id = Database::insert('assets', $data + [
                'hotel_id' => $hotelId,
                'asset_code' => $code,
                'status' => 'ACTIVE',
                'image_path' => $img['disk_path'] ?? null,
                'qr_token' => bin2hex(random_bytes(16)),
                'is_demo' => !empty($opts['is_demo']) ? 1 : 0,
                'created_by' => Auth::id(),
                'created_at' => Time::now(),
            ]);
            TicketService::assetHistory($id, $hotelId, 'CREATED', 'ลงทะเบียนอุปกรณ์ ' . $code, null, Auth::id());
            Audit::log('asset.create', 'asset', $id, $hotelId, null, ['code' => $code]);
            return $id;
        });
    }

    public static function update(int $id, array $d, ?array $image = null): void
    {
        Database::transaction(function () use ($id, $d, $image): void {
            $a = Database::first('SELECT * FROM assets WHERE id = ? AND deleted_at IS NULL FOR UPDATE', [$id]);
            if (!$a) {
                throw new NotFoundException();
            }
            $hotelId = (int) $a['hotel_id'];
            if (!Auth::gate()->can('assets.update', $hotelId)) {
                throw new ForbiddenException();
            }
            $data = self::clean($hotelId, $d);
            unset($data['_cat']);
            if ($image) {
                $data['image_path'] = FileStorage::store($image, 'images', 'assets/' . $hotelId)['disk_path'];
            }
            Database::update('assets', $data, 'id = ?', [$id]);
            $changed = array_keys(array_filter($data, static fn ($v, $k) => (string) ($a[$k] ?? '') !== (string) ($v ?? ''), ARRAY_FILTER_USE_BOTH));
            if ($changed) {
                TicketService::assetHistory($id, $hotelId, 'UPDATED', 'แก้ไขข้อมูล: ' . implode(', ', $changed), null, Auth::id());
                Audit::log('asset.update', 'asset', $id, $hotelId, array_intersect_key($a, array_flip($changed)), array_intersect_key($data, array_flip($changed)));
            }
        });
    }

    public static function changeStatus(int $id, string $status, string $reason): void
    {
        if (!isset(self::STATUSES[$status])) {
            throw new ValidationException(['status' => 'สถานะไม่ถูกต้อง']);
        }
        if (mb_strlen(trim($reason)) < 3) {
            throw new ValidationException(['reason' => 'กรุณาระบุเหตุผล']);
        }
        Database::transaction(function () use ($id, $status, $reason): void {
            $a = Database::first('SELECT * FROM assets WHERE id = ? AND deleted_at IS NULL FOR UPDATE', [$id]);
            if (!$a) {
                throw new NotFoundException();
            }
            $hotelId = (int) $a['hotel_id'];
            if (!Auth::gate()->can('assets.update', $hotelId)) {
                throw new ForbiddenException();
            }
            if ($a['status'] === 'DISPOSED') {
                throw new BusinessRuleException('อุปกรณ์ที่จำหน่ายออกแล้วไม่สามารถเปลี่ยนสถานะได้');
            }
            if ($a['status'] === $status) {
                throw new BusinessRuleException('สถานะเดิมอยู่แล้ว');
            }
            if ($status === 'DISPOSED') {
                Database::execute('UPDATE pm_plans SET is_active = 0 WHERE asset_id = ?', [$id]);
            }
            Database::update('assets', ['status' => $status], 'id = ?', [$id]);
            TicketService::assetHistory($id, $hotelId, 'STATUS', 'เปลี่ยนสถานะเป็น ' . self::STATUSES[$status] . ': ' . $reason, null, Auth::id());
            Audit::log('asset.status', 'asset', $id, $hotelId, ['status' => $a['status']], ['status' => $status, 'reason' => $reason]);
        });
    }

    public static function addAttachment(int $id, array $file, string $kind): int
    {
        $a = Database::first('SELECT id, hotel_id FROM assets WHERE id = ? AND deleted_at IS NULL', [$id]);
        if (!$a) {
            throw new NotFoundException();
        }
        $hotelId = (int) $a['hotel_id'];
        if (!Auth::gate()->can('assets.update', $hotelId)) {
            throw new ForbiddenException();
        }
        $kind = in_array($kind, ['IMAGE', 'MANUAL', 'DOCUMENT'], true) ? $kind : 'DOCUMENT';
        $s = FileStorage::store($file, $kind === 'IMAGE' ? 'images' : 'documents', 'assets/' . $hotelId);
        $attId = Database::insert('asset_attachments', [
            'asset_id' => $id, 'hotel_id' => $hotelId, 'kind' => $kind, 'disk_path' => $s['disk_path'],
            'original_name' => $s['original_name'], 'mime' => $s['mime'], 'size_bytes' => $s['size_bytes'],
            'uploaded_by' => Auth::id(), 'created_at' => Time::now(),
        ]);
        TicketService::assetHistory($id, $hotelId, 'ATTACHMENT', 'แนบไฟล์ ' . $s['original_name'], null, Auth::id());
        return $attId;
    }

    /** Repair cost summary from the cost ledger (approved entries) */
    public static function costSummary(int $assetId): array
    {
        $rows = Database::select(
            "SELECT cc.code, cc.name_th, COALESCE(SUM(ce.total_cost),0) AS amount
             FROM cost_entries ce JOIN cost_categories cc ON cc.id = ce.category_id
             WHERE ce.asset_id = ? AND ce.status = 'APPROVED' GROUP BY cc.code, cc.name_th ORDER BY cc.code",
            [$assetId]
        );
        $total = '0';
        foreach ($rows as $r) {
            $total = Dec::add($total, $r['amount'], 2);
        }
        return ['rows' => $rows, 'total' => $total];
    }
}

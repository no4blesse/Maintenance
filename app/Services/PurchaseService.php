<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\{Auth,Database};
use App\Core\Exceptions\{BusinessRuleException,ValidationException};
use App\Helpers\{Dec,Time};

final class PurchaseService
{
    public static function create(int $hotel, array $d): int
    {
        Auth::gate()->authorize('purchase.request', $hotel);
        if (!Database::value('SELECT 1 FROM spare_parts WHERE id=? AND is_active=1', [$d['part_id']])) {
            throw new ValidationException(['part_id' => 'อะไหล่ไม่ถูกต้อง']);
        }
        if ($d['supplier_id'] && !Database::value('SELECT 1 FROM suppliers WHERE id=? AND is_active=1 AND (hotel_id IS NULL OR hotel_id=?)', [$d['supplier_id'],$hotel])) {
            throw new ValidationException(['supplier_id' => 'ผู้จำหน่ายไม่อยู่ในโรงแรม']);
        }
        return Database::transaction(function () use ($hotel, $d) {
            $code = Database::value('SELECT code FROM hotels WHERE id=?', [$hotel]);
            $total = Dec::money($d['quantity'], $d['unit_cost']);
            $year = (int)substr(Time::today(), 0, 4);
            if (Dec::cmp($total, '999999999999.99') > 0) {
                throw new BusinessRuleException('ยอดรวมเกินขนาดที่ระบบรองรับ');
            }
            $id = Database::insert('purchase_requests', ['pr_no' => 'PR-'.$code.'-'.$year.'-'.NumberSequence::next('purchase', $hotel, $year),'hotel_id' => $hotel,'supplier_id' => $d['supplier_id'],'note' => $d['note'],'total_amount' => $total,'requested_by' => Auth::id(),'requested_at' => Time::now()]);
            Database::insert('purchase_request_items', ['pr_id' => $id,'part_id' => $d['part_id'],'quantity' => $d['quantity'],'unit_cost' => $d['unit_cost'],'total_cost' => $total]);
            Audit::log('purchase.create', 'purchase_request', $id, $hotel, null, ['total' => $total]);
            return $id;
        });
    }
    public static function action(int $id, string $op, string $reason): void
    {
        Database::transaction(function () use ($id, $op, $reason) {
            $r = Database::first('SELECT * FROM purchase_requests WHERE id=? FOR UPDATE', [$id]);
            if (!$r) {
                throw new \App\Core\Exceptions\NotFoundException();
            }$hotel = (int)$r['hotel_id'];
            if (in_array($op, ['approve','reject'], true)) {
                Auth::gate()->authorize('purchase.approve', $hotel);
                if ($r['status'] !== 'SUBMITTED') {
                    throw new BusinessRuleException('คำขอได้รับการพิจารณาแล้ว');
                }if ((int)$r['requested_by'] === Auth::id()) {
                    throw new BusinessRuleException('ผู้ขอไม่สามารถพิจารณาคำขอของตนเอง');
                }if ($op === 'reject' && mb_strlen($reason) < 3) {
                    throw new BusinessRuleException('กรุณาระบุเหตุผล');
                }Database::update('purchase_requests', ['status' => $op === 'approve' ? 'APPROVED' : 'REJECTED','approved_by' => Auth::id(),'approved_at' => Time::now(),'reject_reason' => $op === 'reject' ? $reason : null], 'id=?', [$id]);
            } elseif ($op === 'receive') {
                Auth::gate()->authorize('inventory.receive', $hotel);
                if ($r['status'] !== 'APPROVED') {
                    throw new BusinessRuleException('รับเข้าได้เฉพาะคำขอที่อนุมัติและยังไม่รับ');
                }foreach (Database::select('SELECT * FROM purchase_request_items WHERE pr_id=? ORDER BY part_id', [$id]) as $i) {
                    InventoryService::receive($hotel, (int)$i['part_id'], $i['quantity'], $i['unit_cost'], $r['supplier_id'] ? (int)$r['supplier_id'] : null, 'รับตาม '.$r['pr_no'], 'PURCHASE', $id);
                }Database::update('purchase_requests', ['status' => 'RECEIVED','received_by' => Auth::id(),'received_at' => Time::now()], 'id=?', [$id]);
            } elseif ($op === 'cancel') {
                Auth::gate()->authorize('purchase.request', $hotel);
                if ((int)$r['requested_by'] !== Auth::id() || $r['status'] !== 'SUBMITTED') {
                    throw new BusinessRuleException('ยกเลิกได้เฉพาะคำขอของตนที่ยังไม่พิจารณา');
                }Database::update('purchase_requests', ['status' => 'CANCELLED','reject_reason' => $reason], 'id=?', [$id]);
            } else {
                throw new BusinessRuleException('การดำเนินการไม่ถูกต้อง');
            }
            Audit::log('purchase.'.$op, 'purchase_request', $id, $hotel, ['status' => $r['status']], ['reason' => $reason]);
        });
    }
}

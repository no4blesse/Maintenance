<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Auth;
use App\Core\Database;
use App\Core\Exceptions\BusinessRuleException;
use App\Core\Exceptions\ForbiddenException;
use App\Helpers\Dec;
use App\Helpers\Time;

/**
 * Single cost ledger (cost_entries).
 * Recognition rules (no double counting):
 *  - PARTS : recognised when a part is ISSUED to a ticket (source PART_USAGE). Auto-approved.
 *  - LABOR : recognised when a work log is recorded (source WORK_LOG). Auto-approved.
 *  - CONTRACTOR / REPLACEMENT / OTHER : manual entries, require approval
 *    (unless below the configurable auto-approve threshold).
 *  - Stock RECEIVE / purchase requests are inventory value, NOT maintenance cost.
 * Ticket total = SUM(APPROVED entries linked to the ticket).
 */
final class CostService
{
    public const MANUAL_CATEGORIES = ['CONTRACTOR', 'REPLACEMENT', 'OTHER'];
    /** @var array<string, int> */
    private static array $catIds = [];

    public static function categoryId(string $code): int
    {
        if (!isset(self::$catIds[$code])) {
            $id = Database::value('SELECT id FROM cost_categories WHERE code = ?', [$code]);
            if (!$id) {
                throw new \RuntimeException('Unknown cost category ' . $code);
            }
            self::$catIds[$code] = (int) $id;
        }
        return self::$catIds[$code];
    }

    public static function recordSystemCost(int $hotelId, ?int $ticketId, ?int $assetId, string $code, string $source, int $sourceId, string $description, string $qty, string $unitCost, string $total, string $expenseDate, ?int $userId): int
    {
        return Database::insert('cost_entries', [
            'hotel_id' => $hotelId,
            'ticket_id' => $ticketId,
            'asset_id' => $assetId,
            'category_id' => self::categoryId($code),
            'source' => $source,
            'source_id' => $sourceId,
            'description' => mb_substr($description, 0, 500),
            'quantity' => $qty,
            'unit_cost' => $unitCost,
            'total_cost' => $total,
            'expense_date' => $expenseDate,
            'status' => 'APPROVED',
            'approved_at' => Time::now(),
            'created_by' => $userId,
            'created_at' => Time::now(),
        ]);
    }

    public static function voidBySource(string $source, int $sourceId, string $reason): void
    {
        Database::execute(
            "UPDATE cost_entries SET status = 'VOID', reject_reason = ? WHERE source = ? AND source_id = ? AND status <> 'VOID'",
            [mb_substr($reason, 0, 500), $source, $sourceId]
        );
    }

    /** Recalculates ticket cost columns from the ledger. */
    public static function recalcTicket(int $ticketId): array
    {
        $rows = Database::select(
            "SELECT cc.code, COALESCE(SUM(ce.total_cost), 0) AS amount
             FROM cost_entries ce JOIN cost_categories cc ON cc.id = ce.category_id
             WHERE ce.ticket_id = ? AND ce.status = 'APPROVED' GROUP BY cc.code",
            [$ticketId]
        );
        $by = [];
        foreach ($rows as $r) {
            $by[$r['code']] = (string) $r['amount'];
        }
        $parts = Dec::round($by['PARTS'] ?? '0', 2);
        $labor = Dec::round($by['LABOR'] ?? '0', 2);
        $contractor = Dec::round($by['CONTRACTOR'] ?? '0', 2);
        $other = Dec::round(Dec::add($by['REPLACEMENT'] ?? '0', $by['OTHER'] ?? '0'), 2);
        $total = Dec::round(Dec::add(Dec::add($parts, $labor), Dec::add($contractor, $other)), 2);
        Database::update('tickets', [
            'cost_parts' => $parts, 'cost_labor' => $labor, 'cost_contractor' => $contractor,
            'cost_other' => $other, 'cost_total' => $total,
        ], 'id = ?', [$ticketId]);
        return compact('parts', 'labor', 'contractor', 'other', 'total');
    }

    /** Hourly rate effective on $date (local) for technician at hotel; '0.00' when none configured */
    public static function laborRate(int $userId, int $hotelId, string $date): string
    {
        $r = Database::value(
            'SELECT hourly_rate FROM technician_rates WHERE user_id = ? AND hotel_id = ? AND effective_from <= ? ORDER BY effective_from DESC LIMIT 1',
            [$userId, $hotelId, $date]
        );
        return $r === null ? '0.00' : (string) $r;
    }

    /**
     * @param array{category:string, description:string, quantity:string, unit_cost:string, expense_date:string, ticket_id?:?int, asset_id?:?int, supplier_id?:?int} $d
     */
    public static function createManual(int $hotelId, array $d, ?array $document, int $userId): int
    {
        if (!Auth::gate()->can('costs.create', $hotelId)) {
            throw new ForbiddenException();
        }
        if (!in_array($d['category'], self::MANUAL_CATEGORIES, true)) {
            throw new BusinessRuleException('หมวดค่าใช้จ่ายนี้บันทึกโดยระบบอัตโนมัติ (อะไหล่/ค่าแรง)');
        }
        if (!empty($d['ticket_id']) && !Database::value('SELECT 1 FROM tickets WHERE id = ? AND hotel_id = ?', [$d['ticket_id'], $hotelId])) {
            throw new BusinessRuleException('ใบงานไม่อยู่ในโรงแรมที่เลือก');
        }
        if (!empty($d['asset_id']) && !Database::value('SELECT 1 FROM assets WHERE id = ? AND hotel_id = ?', [$d['asset_id'], $hotelId])) {
            throw new BusinessRuleException('อุปกรณ์ไม่อยู่ในโรงแรมที่เลือก');
        }
        if (!empty($d['supplier_id']) && !Database::value('SELECT 1 FROM suppliers WHERE id = ? AND (hotel_id IS NULL OR hotel_id = ?)', [$d['supplier_id'], $hotelId])) {
            throw new BusinessRuleException('ผู้จำหน่ายไม่ถูกต้อง');
        }
        $qty = Dec::norm($d['quantity'], 2);
        if (Dec::cmp($qty, '0') <= 0 || Dec::cmp($d['unit_cost'], '0') < 0) {
            throw new BusinessRuleException('จำนวนและราคาต้องมากกว่า 0');
        }
        $total = Dec::money($qty, $d['unit_cost']);
        if (Dec::cmp($total, '999999999999.99') > 0) { throw new BusinessRuleException('ยอดรวมเกินขนาดที่ระบบรองรับ'); }
        $threshold = (string) (setting('cost_auto_approve_below', '0') ?: '0');
        $auto = Dec::cmp($threshold, '0') > 0 && Dec::cmp($total, $threshold) < 0;

        return Database::transaction(function () use ($hotelId, $d, $document, $userId, $qty, $total, $auto) {
            $doc = $document ? FileStorage::store($document, 'documents', 'costs/' . $hotelId) : null;
            $id = Database::insert('cost_entries', [
                'hotel_id' => $hotelId,
                'ticket_id' => $d['ticket_id'] ?: null,
                'asset_id' => $d['asset_id'] ?: null,
                'category_id' => self::categoryId($d['category']),
                'source' => 'MANUAL',
                'description' => mb_substr($d['description'], 0, 500),
                'quantity' => $qty,
                'unit_cost' => Dec::norm($d['unit_cost'], 4),
                'total_cost' => $total,
                'expense_date' => $d['expense_date'],
                'supplier_id' => $d['supplier_id'] ?: null,
                'status' => $auto ? 'APPROVED' : 'PENDING',
                'approved_at' => $auto ? Time::now() : null,
                'document_path' => $doc['disk_path'] ?? null,
                'document_name' => $doc['original_name'] ?? null,
                'document_mime' => $doc['mime'] ?? null,
                'created_by' => $userId,
                'created_at' => Time::now(),
            ]);
            if (!empty($d['ticket_id'])) {
                self::recalcTicket((int) $d['ticket_id']);
            }
            Audit::log('cost.create', 'cost_entry', $id, $hotelId, null, ['total' => $total, 'category' => $d['category'], 'auto_approved' => $auto]);
            if (!$auto) {
                NotificationService::queue('EXPENSE_APPROVAL', $hotelId, [
                    'lines' => [
                        ['📝', $d['description']],
                        ['💵 จำนวนเงิน:', money($total)],
                        ['📂 หมวด:', $d['category']],
                    ],
                    'url' => absolute_url('costs?status=PENDING'),
                ], 'EXPENSE_APPROVAL:' . $id);
                InAppNotifications::send(
                    InAppNotifications::usersWithPermission('costs.approve', $hotelId, false),
                    $hotelId, 'EXPENSE_APPROVAL', 'รออนุมัติค่าใช้จ่าย ' . money($total), $d['description'], url('/costs?status=PENDING')
                );
            }
            return $id;
        });
    }

    public static function decide(int $id, string $decision, ?string $reason, int $userId): void
    {
        if (!in_array($decision, ['approve', 'reject', 'void'], true)) { throw new BusinessRuleException('การดำเนินการไม่ถูกต้อง'); }
        if ($decision !== 'approve' && mb_strlen(trim($reason ?? '')) < 3) { throw new BusinessRuleException('กรุณาระบุเหตุผล'); }
        Database::transaction(function () use ($id, $decision, $reason, $userId): void {
            $c = Database::first('SELECT * FROM cost_entries WHERE id = ? FOR UPDATE', [$id]);
            if (!$c) {
                throw new \App\Core\Exceptions\NotFoundException();
            }
            if (!Auth::gate()->can('costs.approve', (int) $c['hotel_id'])) {
                throw new ForbiddenException();
            }
            if ($c['source'] !== 'MANUAL') {
                throw new BusinessRuleException('รายการที่ระบบสร้างอัตโนมัติไม่ต้องอนุมัติ');
            }
            if ($decision === 'void') {
                if ($c['status'] !== 'APPROVED') {
                    throw new BusinessRuleException('ยกเลิกได้เฉพาะรายการที่อนุมัติแล้ว');
                }
                Database::update('cost_entries', ['status' => 'VOID', 'reject_reason' => $reason], 'id = ?', [$id]);
            } else {
                if ($c['status'] !== 'PENDING') {
                    throw new BusinessRuleException('รายการนี้ได้รับการพิจารณาแล้ว');
                }
                if ((int) $c['created_by'] === $userId) {
                    throw new BusinessRuleException('ไม่สามารถอนุมัติรายการที่ตนเองบันทึกได้ (แบ่งแยกหน้าที่)');
                }
                Database::update('cost_entries', [
                    'status' => $decision === 'approve' ? 'APPROVED' : 'REJECTED',
                    'approved_by' => $userId,
                    'approved_at' => Time::now(),
                    'reject_reason' => $decision === 'reject' ? $reason : null,
                ], 'id = ?', [$id]);
            }
            if ($c['ticket_id']) {
                self::recalcTicket((int) $c['ticket_id']);
            }
            Audit::log('cost.' . $decision, 'cost_entry', $id, (int) $c['hotel_id'], ['status' => $c['status']], ['reason' => $reason]);
        });
    }
}

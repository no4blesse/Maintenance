<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Auth;
use App\Core\Database;
use App\Core\Exceptions\BusinessRuleException;
use App\Core\Exceptions\ForbiddenException;
use App\Core\Exceptions\NotFoundException;
use App\Helpers\Dec;
use App\Helpers\Time;
use App\Models\TicketStatus;

/**
 * Per-hotel inventory ledger.
 * Rules:
 *  - hotel_stock row is locked with SELECT ... FOR UPDATE before any change,
 *    so concurrent issues serialise and can never drive stock negative
 *    (also enforced by CHECK qty_on_hand >= 0).
 *  - Every change inserts an immutable stock_movements row (balance_after).
 *  - Valuation: moving weighted-average cost per hotel.
 *  - Corrections use reversal movements (reversal_of_id is UNIQUE -> once only).
 *  - Transfer policy: stock leaves the source on APPROVAL (TRANSFER_OUT,
 *    status IN_TRANSIT) and enters destination on RECEIPT (TRANSFER_IN).
 *    Cancelling an in-transit transfer returns stock to the source.
 */
final class InventoryService
{
    /** Lock (creating if necessary) the stock row */
    private static function lock(int $hotelId, int $partId): array
    {
        // Upsert obtains an exclusive duplicate-key lock immediately. INSERT IGNORE
        // takes shared locks that can deadlock when two issuers upgrade to FOR UPDATE.
        Database::execute('INSERT INTO hotel_stock (hotel_id, part_id, qty_on_hand, avg_cost) VALUES (?, ?, 0, 0) ON DUPLICATE KEY UPDATE id = LAST_INSERT_ID(id)', [$hotelId, $partId]);
        $row = Database::first('SELECT * FROM hotel_stock WHERE hotel_id = ? AND part_id = ? FOR UPDATE', [$hotelId, $partId]);
        if (!$row) {
            throw new \RuntimeException('stock row missing');
        }
        return $row;
    }

    private static function assertPart(int $partId): array
    {
        $p = Database::first('SELECT * FROM spare_parts WHERE id = ?', [$partId]);
        if (!$p) {
            throw new NotFoundException('ไม่พบรายการอะไหล่');
        }
        return $p;
    }

    private static function movement(int $hotelId, int $partId, string $type, string $qty, string $unitCost, string $balance, ?string $refType, ?int $refId, ?string $note, ?int $userId, ?int $reversalOf = null, ?int $supplierId = null): int
    {
        return Database::insert('stock_movements', [
            'hotel_id' => $hotelId,
            'part_id' => $partId,
            'movement_type' => $type,
            'quantity' => $qty,
            'unit_cost' => Dec::norm($unitCost, 4),
            'total_cost' => Dec::money(ltrim($qty, '-'), $unitCost),
            'balance_after' => $balance,
            'ref_type' => $refType,
            'ref_id' => $refId,
            'reversal_of_id' => $reversalOf,
            'supplier_id' => $supplierId,
            'note' => $note !== null ? mb_substr($note, 0, 500) : null,
            'user_id' => $userId,
            'created_at' => Time::now(),
        ]);
    }

    /** Increase stock & update weighted-average cost. Must run inside a transaction. */
    private static function addStock(int $hotelId, int $partId, string $qty, string $unitCost, string $type, ?string $refType, ?int $refId, ?string $note, ?int $userId, ?int $reversalOf = null, ?int $supplierId = null): int
    {
        $s = self::lock($hotelId, $partId);
        $newQty = Dec::add($s['qty_on_hand'], $qty, 2);
        $newAvg = Dec::cmp($newQty, '0') > 0
            ? Dec::div(Dec::add(Dec::mul($s['qty_on_hand'], $s['avg_cost'], 8), Dec::mul($qty, $unitCost, 8), 8), $newQty, 4)
            : Dec::norm($unitCost, 4);
        Database::update('hotel_stock', ['qty_on_hand' => $newQty, 'avg_cost' => $newAvg, 'low_stock_notified_at' => null], 'id = ?', [$s['id']]);
        return self::movement($hotelId, $partId, $type, Dec::norm($qty, 2), $unitCost, $newQty, $refType, $refId, $note, $userId, $reversalOf, $supplierId);
    }

    /** Decrease stock at current average cost. Throws if insufficient. Must run inside a transaction. */
    private static function removeStock(int $hotelId, int $partId, string $qty, string $type, ?string $refType, ?int $refId, ?string $note, ?int $userId, ?int $reversalOf = null): array
    {
        $s = self::lock($hotelId, $partId);
        if (Dec::cmp($s['qty_on_hand'], $qty) < 0) {
            $p = Database::first('SELECT sku, name, unit FROM spare_parts WHERE id = ?', [$partId]);
            throw new BusinessRuleException(sprintf('สต็อกไม่เพียงพอ: %s %s คงเหลือ %s %s', $p['sku'] ?? '', $p['name'] ?? '', qty($s['qty_on_hand']), $p['unit'] ?? ''));
        }
        $newQty = Dec::sub($s['qty_on_hand'], $qty, 2);
        Database::update('hotel_stock', ['qty_on_hand' => $newQty], 'id = ?', [$s['id']]);
        $mid = self::movement($hotelId, $partId, $type, '-' . Dec::norm($qty, 2), $s['avg_cost'], $newQty, $refType, $refId, $note, $userId, $reversalOf);
        return ['movement_id' => $mid, 'unit_cost' => (string) $s['avg_cost'], 'balance' => $newQty];
    }

    private static function assertQty(string $qty): string
    {
        if (!is_numeric($qty) || Dec::cmp($qty, '0') <= 0) {
            throw new BusinessRuleException('จำนวนต้องมากกว่า 0');
        }
        return Dec::norm($qty, 2);
    }

    private static function authorize(string $perm, int $hotelId): void
    {
        if (!Auth::gate()->can($perm, $hotelId)) {
            throw new ForbiddenException();
        }
    }

    // ------------------------------------------------------------------ public API

    public static function receive(int $hotelId, int $partId, string $qty, string $unitCost, ?int $supplierId, ?string $note, string $refType = 'MANUAL', ?int $refId = null): int
    {
        self::authorize('inventory.receive', $hotelId);
        self::assertPart($partId);
        $qty = self::assertQty($qty);
        if (!is_numeric($unitCost) || Dec::cmp($unitCost, '0') < 0) {
            throw new BusinessRuleException('ราคาต่อหน่วยไม่ถูกต้อง');
        }
        return Database::transaction(function () use ($hotelId, $partId, $qty, $unitCost, $supplierId, $note, $refType, $refId) {
            $id = self::addStock($hotelId, $partId, $qty, $unitCost, 'RECEIVE', $refType, $refId, $note, Auth::id(), null, $supplierId);
            Audit::log('stock.receive', 'stock_movement', $id, $hotelId, null, ['part_id' => $partId, 'qty' => $qty, 'unit_cost' => $unitCost]);
            return $id;
        });
    }

    /** Non-ticket issue (e.g. general use). Not recorded as maintenance cost. */
    public static function issue(int $hotelId, int $partId, string $qty, string $note): int
    {
        self::authorize('inventory.issue', $hotelId);
        self::assertPart($partId);
        $qty = self::assertQty($qty);
        if (trim($note) === '') {
            throw new BusinessRuleException('กรุณาระบุวัตถุประสงค์การเบิก');
        }
        return Database::transaction(function () use ($hotelId, $partId, $qty, $note) {
            $r = self::removeStock($hotelId, $partId, $qty, 'ISSUE', 'MANUAL', null, $note, Auth::id());
            Audit::log('stock.issue', 'stock_movement', $r['movement_id'], $hotelId, null, ['part_id' => $partId, 'qty' => $qty]);
            return $r['movement_id'];
        });
    }

    public static function returnStock(int $hotelId, int $partId, string $qty, string $note): int
    {
        if (!Auth::gate()->can('inventory.receive', $hotelId) && !Auth::gate()->can('inventory.issue', $hotelId)) {
            throw new ForbiddenException();
        }
        self::assertPart($partId);
        $qty = self::assertQty($qty);
        return Database::transaction(function () use ($hotelId, $partId, $qty, $note) {
            $s = self::lock($hotelId, $partId);
            $id = self::addStock($hotelId, $partId, $qty, (string) $s['avg_cost'], 'RETURN', 'MANUAL', null, $note, Auth::id());
            Audit::log('stock.return', 'stock_movement', $id, $hotelId, null, ['part_id' => $partId, 'qty' => $qty]);
            return $id;
        });
    }

    /** Stock count / adjustment: sets the counted quantity, recording the delta. */
    public static function adjustTo(int $hotelId, int $partId, string $countedQty, string $reason): ?int
    {
        self::authorize('inventory.adjust', $hotelId);
        self::assertPart($partId);
        if (!is_numeric($countedQty) || Dec::cmp($countedQty, '0') < 0) {
            throw new BusinessRuleException('จำนวนที่นับได้ต้องไม่ติดลบ');
        }
        if (mb_strlen(trim($reason)) < 3) {
            throw new BusinessRuleException('กรุณาระบุเหตุผลการปรับปรุงสต็อก');
        }
        return Database::transaction(function () use ($hotelId, $partId, $countedQty, $reason) {
            $s = self::lock($hotelId, $partId);
            $delta = Dec::sub($countedQty, $s['qty_on_hand'], 2);
            if (Dec::cmp($delta, '0') === 0) {
                return null;
            }
            $newQty = Dec::norm($countedQty, 2);
            Database::update('hotel_stock', ['qty_on_hand' => $newQty], 'id = ?', [$s['id']]);
            $id = self::movement($hotelId, $partId, 'ADJUSTMENT', $delta, (string) $s['avg_cost'], $newQty, 'COUNT', null, $reason, Auth::id());
            Audit::log('stock.adjust', 'stock_movement', $id, $hotelId, ['qty' => $s['qty_on_hand']], ['qty' => $newQty, 'reason' => $reason]);
            return $id;
        });
    }

    /** Consume parts on a ticket: stock movement + usage + PARTS cost, atomically. */
    public static function issueToTicket(int $ticketId, int $partId, string $qty, ?string $note = null): int
    {
        $qty = self::assertQty($qty);
        return Database::transaction(function () use ($ticketId, $partId, $qty, $note) {
            $t = Database::first('SELECT id, hotel_id, ticket_no, status, assigned_to, asset_id FROM tickets WHERE id = ? FOR UPDATE', [$ticketId]);
            if (!$t) {
                throw new NotFoundException();
            }
            $hotelId = (int) $t['hotel_id'];
            self::authorize('inventory.issue', $hotelId);
            $g = Auth::gate();
            $isAssignee = (int) $t['assigned_to'] === (int) Auth::id();
            if (!$isAssignee && !$g->can('tickets.assign', $hotelId)) {
                throw new ForbiddenException('เบิกอะไหล่ได้เฉพาะช่างผู้รับผิดชอบหรือหัวหน้าช่าง');
            }
            if (!in_array($t['status'], TicketStatus::WORKABLE, true)) {
                throw new BusinessRuleException('ไม่สามารถเบิกอะไหล่ในสถานะปัจจุบันของงาน');
            }
            $part = self::assertPart($partId);
            $r = self::removeStock($hotelId, $partId, $qty, 'ISSUE', 'TICKET', $ticketId, $note ?: 'เบิกใช้ในงาน ' . $t['ticket_no'], Auth::id());
            $total = Dec::money($qty, $r['unit_cost']);
            $usageId = Database::insert('ticket_part_usages', [
                'ticket_id' => $ticketId,
                'hotel_id' => $hotelId,
                'part_id' => $partId,
                'quantity' => $qty,
                'unit_cost' => Dec::norm($r['unit_cost'], 4),
                'total_cost' => $total,
                'movement_id' => $r['movement_id'],
                'status' => 'ISSUED',
                'issued_by' => Auth::id(),
                'created_at' => Time::now(),
            ]);
            CostService::recordSystemCost(
                $hotelId, $ticketId, $t['asset_id'] ? (int) $t['asset_id'] : null, 'PARTS', 'PART_USAGE', $usageId,
                $part['sku'] . ' ' . $part['name'] . ' x ' . qty($qty), $qty, (string) $r['unit_cost'], $total, Time::today(), Auth::id()
            );
            CostService::recalcTicket($ticketId);
            Database::insert('ticket_status_logs', [
                'ticket_id' => $ticketId, 'hotel_id' => $hotelId, 'from_status' => $t['status'], 'to_status' => $t['status'],
                'action' => 'part_issue', 'note' => 'เบิก ' . $part['sku'] . ' ' . $part['name'] . ' จำนวน ' . qty($qty) . ' ' . $part['unit'] . ' (' . money($total) . ')',
                'user_id' => Auth::id(), 'created_at' => Time::now(),
            ]);
            return $usageId;
        });
    }

    /** Auditable reversal of a ticket part issue (stock RETURN + cost VOID). */
    public static function reverseTicketUsage(int $usageId, string $reason): void
    {
        if (mb_strlen(trim($reason)) < 3) {
            throw new BusinessRuleException('กรุณาระบุเหตุผลการคืนอะไหล่');
        }
        Database::transaction(function () use ($usageId, $reason): void {
            $u = Database::first('SELECT * FROM ticket_part_usages WHERE id = ? FOR UPDATE', [$usageId]);
            if (!$u) {
                throw new NotFoundException();
            }
            $hotelId = (int) $u['hotel_id'];
            self::authorize('inventory.issue', $hotelId);
            if ($u['status'] !== 'ISSUED') {
                throw new BusinessRuleException('รายการนี้ถูกคืนแล้ว');
            }
            $t = Database::first('SELECT status, ticket_no FROM tickets WHERE id = ?', [$u['ticket_id']]);
            if (in_array($t['status'], TicketStatus::CLOSED, true)) {
                throw new BusinessRuleException('งานปิดแล้ว ไม่สามารถคืนอะไหล่ได้ (ต้องเปิดงานใหม่ก่อน)');
            }
            $mid = self::addStock($hotelId, (int) $u['part_id'], (string) $u['quantity'], (string) $u['unit_cost'], 'RETURN', 'TICKET', (int) $u['ticket_id'], 'คืนจากงาน ' . $t['ticket_no'] . ': ' . $reason, Auth::id(), (int) $u['movement_id']);
            Database::update('ticket_part_usages', [
                'status' => 'REVERSED', 'reversal_movement_id' => $mid, 'reversed_by' => Auth::id(), 'reversed_at' => Time::now(), 'reverse_reason' => mb_substr($reason, 0, 500),
            ], 'id = ?', [$usageId]);
            CostService::voidBySource('PART_USAGE', $usageId, 'คืนอะไหล่: ' . $reason);
            CostService::recalcTicket((int) $u['ticket_id']);
            Database::insert('ticket_status_logs', [
                'ticket_id' => $u['ticket_id'], 'hotel_id' => $hotelId, 'from_status' => $t['status'], 'to_status' => $t['status'],
                'action' => 'part_reverse', 'note' => 'คืนอะไหล่ จำนวน ' . qty($u['quantity']) . ': ' . $reason, 'user_id' => Auth::id(), 'created_at' => Time::now(),
            ]);
            Audit::log('stock.reverse_usage', 'ticket_part_usage', $usageId, $hotelId, null, ['reason' => $reason]);
        });
    }

    // ------------------------------------------------------------------ transfers

    /** @param list<array{part_id:int, quantity:string}> $items */
    public static function requestTransfer(int $fromHotel, int $toHotel, array $items, ?string $note): int
    {
        self::authorize('inventory.transfer', $fromHotel);
        if ($fromHotel === $toHotel) {
            throw new BusinessRuleException('โรงแรมต้นทางและปลายทางต้องไม่ซ้ำกัน');
        }
        if (!Database::value('SELECT 1 FROM hotels WHERE id = ? AND is_active = 1', [$toHotel])) {
            throw new BusinessRuleException('โรงแรมปลายทางไม่ถูกต้อง');
        }
        $clean = [];
        foreach ($items as $it) {
            $pid = (int) ($it['part_id'] ?? 0);
            if ($pid <= 0 || ($it['quantity'] ?? '') === '') {
                continue;
            }
            self::assertPart($pid);
            $clean[$pid] = Dec::add($clean[$pid] ?? '0', self::assertQty((string) $it['quantity']), 2);
        }
        if (!$clean) {
            throw new BusinessRuleException('กรุณาเพิ่มรายการอะไหล่อย่างน้อย 1 รายการ');
        }
        return Database::transaction(function () use ($fromHotel, $toHotel, $clean, $note) {
            $code = (string) Database::value('SELECT code FROM hotels WHERE id = ?', [$fromHotel]);
            $year = (int) substr(Time::today(), 0, 4);
            $no = sprintf('TR-%s-%d-%05d', $code, $year, NumberSequence::next('transfer', $fromHotel, $year));
            $id = Database::insert('stock_transfers', [
                'transfer_no' => $no, 'from_hotel_id' => $fromHotel, 'to_hotel_id' => $toHotel, 'status' => 'REQUESTED',
                'note' => $note, 'requested_by' => Auth::id(), 'requested_at' => Time::now(),
            ]);
            foreach ($clean as $pid => $q) {
                Database::insert('stock_transfer_items', ['transfer_id' => $id, 'part_id' => $pid, 'quantity' => $q]);
            }
            Audit::log('transfer.request', 'stock_transfer', $id, $fromHotel, null, ['to' => $toHotel, 'items' => $clean]);
            InAppNotifications::send(InAppNotifications::usersWithPermission('inventory.transfer_approve', $fromHotel, false), $fromHotel, 'TRANSFER', 'คำขอโอนอะไหล่ ' . $no, null, url('/inventory/transfers/' . $id));
            return $id;
        });
    }

    public static function transferAction(int $transferId, string $op, ?string $reason = null): void
    {
        Database::transaction(function () use ($transferId, $op, $reason): void {
            $tr = Database::first('SELECT * FROM stock_transfers WHERE id = ? FOR UPDATE', [$transferId]);
            if (!$tr) {
                throw new NotFoundException();
            }
            $from = (int) $tr['from_hotel_id'];
            $to = (int) $tr['to_hotel_id'];
            $uid = Auth::id();
            $items = Database::select('SELECT * FROM stock_transfer_items WHERE transfer_id = ? ORDER BY part_id', [$transferId]);
            $now = Time::now();
            switch ($op) {
                case 'approve':
                    self::authorize('inventory.transfer_approve', $from);
                    self::expect($tr, 'REQUESTED');
                    if ((int) $tr['requested_by'] === $uid) {
                        throw new BusinessRuleException('ผู้ขอโอนไม่สามารถอนุมัติคำขอของตนเองได้');
                    }
                    foreach ($items as $it) { // ordered by part_id -> consistent lock order
                        $r = self::removeStock($from, (int) $it['part_id'], (string) $it['quantity'], 'TRANSFER_OUT', 'TRANSFER', $transferId, 'โอนออก ' . $tr['transfer_no'], $uid);
                        Database::update('stock_transfer_items', ['unit_cost' => Dec::norm($r['unit_cost'], 4)], 'id = ?', [$it['id']]);
                    }
                    Database::update('stock_transfers', ['status' => 'IN_TRANSIT', 'approved_by' => $uid, 'approved_at' => $now], 'id = ?', [$transferId]);
                    break;
                case 'reject':
                    self::authorize('inventory.transfer_approve', $from);
                    self::expect($tr, 'REQUESTED');
                    Database::update('stock_transfers', ['status' => 'REJECTED', 'closed_by' => $uid, 'closed_at' => $now, 'close_reason' => $reason], 'id = ?', [$transferId]);
                    break;
                case 'receive':
                    self::authorize('inventory.transfer', $to);
                    self::expect($tr, 'IN_TRANSIT');
                    foreach ($items as $it) {
                        self::addStock($to, (int) $it['part_id'], (string) $it['quantity'], (string) $it['unit_cost'], 'TRANSFER_IN', 'TRANSFER', $transferId, 'รับโอน ' . $tr['transfer_no'], $uid);
                    }
                    Database::update('stock_transfers', ['status' => 'RECEIVED', 'received_by' => $uid, 'received_at' => $now], 'id = ?', [$transferId]);
                    break;
                case 'cancel':
                    if ($tr['status'] === 'REQUESTED') {
                        if ((int) $tr['requested_by'] !== $uid) {
                            self::authorize('inventory.transfer_approve', $from);
                        } else {
                            self::authorize('inventory.transfer', $from);
                        }
                    } elseif ($tr['status'] === 'IN_TRANSIT') {
                        self::authorize('inventory.transfer_approve', $from);
                        foreach ($items as $it) {
                            $out = Database::value(
                                "SELECT id FROM stock_movements WHERE ref_type = 'TRANSFER' AND ref_id = ? AND movement_type = 'TRANSFER_OUT' AND part_id = ? AND hotel_id = ?",
                                [$transferId, $it['part_id'], $from]
                            );
                            self::addStock($from, (int) $it['part_id'], (string) $it['quantity'], (string) $it['unit_cost'], 'TRANSFER_IN', 'TRANSFER', $transferId, 'ยกเลิกการโอน ' . $tr['transfer_no'] . ' คืนต้นทาง', $uid, $out ? (int) $out : null);
                        }
                    } else {
                        throw new BusinessRuleException('ไม่สามารถยกเลิกรายการโอนในสถานะนี้');
                    }
                    Database::update('stock_transfers', ['status' => 'CANCELLED', 'closed_by' => $uid, 'closed_at' => $now, 'close_reason' => $reason], 'id = ?', [$transferId]);
                    break;
                default:
                    throw new BusinessRuleException('การดำเนินการไม่ถูกต้อง');
            }
            Audit::log('transfer.' . $op, 'stock_transfer', $transferId, $from, ['status' => $tr['status']], ['reason' => $reason]);
        });
    }

    private static function expect(array $row, string $status): void
    {
        if ($row['status'] !== $status) {
            throw new BusinessRuleException('สถานะรายการไม่ถูกต้องสำหรับการดำเนินการนี้');
        }
    }

    /** Low-stock rows for hotels (qty <= reorder point, reorder point > 0) */
    public static function lowStock(array $hotelIds): array
    {
        if (!$hotelIds) {
            return [];
        }
        return Database::select(
            'SELECT hs.*, sp.sku, sp.name, sp.unit, h.code AS hotel_code,
                    COALESCE(hs.reorder_point, sp.reorder_point) AS rop, COALESCE(hs.min_stock, sp.min_stock) AS min_qty
             FROM hotel_stock hs JOIN spare_parts sp ON sp.id = hs.part_id JOIN hotels h ON h.id = hs.hotel_id
             WHERE sp.is_active = 1 AND hs.hotel_id IN (' . Database::placeholders($hotelIds) . ')
               AND COALESCE(hs.reorder_point, sp.reorder_point) > 0
               AND hs.qty_on_hand <= COALESCE(hs.reorder_point, sp.reorder_point)
             ORDER BY (hs.qty_on_hand / NULLIF(COALESCE(hs.reorder_point, sp.reorder_point),0)), sp.sku',
            $hotelIds
        );
    }
}

<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Helpers\Time;

/** Scheduled events only enqueue; the separate notification worker performs delivery. */
final class AlertService
{
    public static function overdue(): int
    {
        $n = 0;
        foreach (Database::column("SELECT id FROM tickets WHERE status NOT IN ('COMPLETED','CANCELLED') AND sla_resolution_due<? AND overdue_notified_at IS NULL ORDER BY id LIMIT 1000", [Time::now()]) as $id) {
            $n += Database::transaction(function () use ($id) {
                $t = Database::first('SELECT * FROM tickets WHERE id=? FOR UPDATE', [$id]);
                if (!$t || $t['overdue_notified_at'] || in_array($t['status'], ['COMPLETED','CANCELLED']) || $t['sla_resolution_due'] >= Time::now()) {
                    return 0;
                }
                $event = $t['ticket_type'] === 'PREVENTIVE' ? 'PM_OVERDUE' : 'TICKET_OVERDUE';
                NotificationService::queue($event, (int)$t['hotel_id'], NotificationService::ticketLines(TicketService::load((int)$id)), $event.':'.$id.':'.$t['reopen_count']);
                Database::update('tickets', ['overdue_notified_at' => Time::now()], 'id=?', [$id]);
                return 1;
            });
        }
        return $n;
    }
    public static function inventory(): int
    {
        $n = 0;
        $cooldown = max(1, (int)setting('inventory_low_stock_cooldown_hours', 24));
        $before = Time::nowImmutable()->modify('-'.$cooldown.' hours')->format('Y-m-d H:i:s');
        foreach (InventoryService::lowStock(array_map('intval', Database::column('SELECT id FROM hotels WHERE is_active=1'))) as $r) {
            $n += Database::transaction(function () use ($r, $before) {
                $s = Database::first('SELECT * FROM hotel_stock WHERE hotel_id=? AND part_id=? FOR UPDATE', [$r['hotel_id'],$r['part_id']]);
                if (!$s || ($s['low_stock_notified_at'] && $s['low_stock_notified_at'] > $before)) {
                    return 0;
                }
                NotificationService::queue('STOCK_LOW', (int)$s['hotel_id'], ['lines' => [['อะไหล่',$r['sku'].' · '.$r['name']],['คงเหลือ',(string)$s['qty_on_hand']]],'url' => absolute_url('/inventory')], 'STOCK_LOW:'.$s['id'].':'.Time::today());
                Database::update('hotel_stock', ['low_stock_notified_at' => Time::now()], 'id=?', [$s['id']]);
                return 1;
            });
        }
        return $n;
    }
    public static function upcomingPm(): int
    {
        $to = (new \DateTimeImmutable(Time::today()))->modify('+'.max(0, (int)setting('pm_upcoming_notify_days', 1)).' days')->format('Y-m-d');
        $n = 0;
        foreach (Database::select('SELECT p.* FROM pm_plans p JOIN hotels h ON h.id=p.hotel_id WHERE p.is_active=1 AND h.is_active=1 AND p.next_due_date BETWEEN ? AND ?', [Time::today(),$to]) as $p) {
            $n += NotificationService::queue('PM_UPCOMING', (int)$p['hotel_id'], ['lines' => [['แผน',$p['plan_no'].' · '.$p['task_name']],['กำหนด',$p['next_due_date']]],'url' => absolute_url('/pm/'.$p['id'])], 'PM_UPCOMING:'.$p['id'].':'.$p['next_due_date']);
        }return $n;
    }
    public static function daily(): int
    {
        if ((int)Time::nowImmutable()->setTimezone(Time::tz())->format('G') < (int)setting('notify_daily_summary_hour', 8)) {
            return 0;
        }$n = 0;
        foreach (Database::select("SELECT h.id,COUNT(t.id) AS open_count,SUM(t.sla_resolution_due<?) AS overdue FROM hotels h LEFT JOIN tickets t ON t.hotel_id=h.id AND t.status NOT IN ('COMPLETED','CANCELLED') WHERE h.is_active=1 GROUP BY h.id", [Time::now()]) as $h) {
            if ((int)$h['open_count'] > 0) {
                $n += NotificationService::queue('DAILY_SUMMARY', (int)$h['id'], ['lines' => [['งานค้าง',(string)$h['open_count']],['เกิน SLA',(string)($h['overdue'] ?? 0)]],'url' => absolute_url('/tickets?status=OPEN')], 'DAILY_SUMMARY:'.$h['id'].':'.Time::today());
            }
        }return $n;
    }
}

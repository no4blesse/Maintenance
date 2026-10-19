<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\{Auth, Database, Response};
use App\Core\Exceptions\{BusinessRuleException,ValidationException};
use App\Helpers\Time;
use App\Policies\TicketPolicy;
use App\Services\TicketService;

final class CalendarController extends ModuleController
{
    public function index(): Response
    {
        $hotels = \App\Core\HotelContext::scope('calendar.view');
        $departments = $this->options('SELECT id,name_th AS name FROM departments WHERE hotel_id IN ('.Database::placeholders($hotels).') AND is_active=1', $hotels);
        $technicians = [];
        foreach ($hotels as $hotel) {
            foreach (TicketService::technicians($hotel) as $tech) {
                $technicians[$tech['id']] = $tech['name'];
            }
        }
        return $this->view('calendar/index', ['title' => 'ปฏิทินติดตามงาน','priorities' => Database::select('SELECT id,name_th FROM priorities WHERE is_active=1'),'categories' => Database::select('SELECT id,name_th FROM maintenance_categories WHERE is_active=1'),'departments' => $departments,'technicians' => $technicians]);
    }
    public function events(): Response
    {
        [$w,$p] = TicketPolicy::visibility('t');
        [$c,$h] = $this->scope('calendar.view', 't.hotel_id');
        try {
            $start = new \DateTimeImmutable($this->request->str('start'));
            $end = new \DateTimeImmutable($this->request->str('end'));
        } catch (\Throwable) {
            throw new ValidationException(['range' => 'ช่วงเวลาไม่ถูกต้อง']);
        }
        if ($end <= $start || $start->diff($end)->days > 370) {
            throw new ValidationException(['range' => 'เลือกช่วงเวลาไม่เกิน 370 วัน']);
        }
        $w .= ' AND '.$c;
        array_push($p, ...$h);
        foreach (['status' => 't.status','priority_id' => 't.priority_id','category_id' => 't.category_id','technician_id' => 't.assigned_to','department_id' => 't.reporter_department_id','type' => 't.ticket_type'] as $k => $col) {
            $v = $this->request->str($k);
            if ($v !== '') {
                $w .= " AND $col=?";
                $p[] = $v;
            }
        }
        $rows = Database::select("SELECT t.*,h.accent_color,u.name AS technician FROM tickets t JOIN hotels h ON h.id=t.hotel_id LEFT JOIN users u ON u.id=t.assigned_to WHERE $w AND COALESCE(t.scheduled_start,t.requested_at) < ? AND COALESCE(t.scheduled_end,t.scheduled_start,t.requested_at) >= ? ORDER BY t.id LIMIT 2000", [...$p,$end->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s'),$start->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s')]);
        return $this->json(array_map(fn ($t) => ['id' => $t['id'],'title' => $t['ticket_no'].' · '.$t['title'].($t['technician'] ? ' · '.$t['technician'] : ''),'start' => Time::toLocal($t['scheduled_start'] ?? $t['requested_at'], 'Y-m-d\TH:i:s'),'end' => $t['scheduled_end'] ? Time::toLocal($t['scheduled_end'], 'Y-m-d\TH:i:s') : null,'url' => url('/tickets/'.$t['id']),'backgroundColor' => !in_array($t['status'], ['COMPLETED','CANCELLED']) && $t['sla_resolution_due'] < Time::now() ? '#DC2626' : $t['accent_color'],'editable' => $t['started_at'] === null && can('calendar.reschedule', (int)$t['hotel_id']) && !in_array($t['status'], ['COMPLETED','CANCELLED'])], $rows));
    }
    public function reschedule(): Response
    {
        $d = $this->validate(['id' => 'required|int','start' => 'required|datetime','end' => 'required|datetime']);
        TicketPolicy::findVisible((int)$d['id']);
        Database::transaction(function () use ($d) {
            $t = Database::first('SELECT * FROM tickets WHERE id=? FOR UPDATE', [$d['id']]);
            $this->authorize('calendar.reschedule', (int)$t['hotel_id']);
            if ($t['started_at'] !== null) {
                throw new BusinessRuleException('งานนี้เริ่มแล้ว เวลาเริ่มและสิ้นสุดบันทึกอัตโนมัติ ไม่สามารถลากเปลี่ยนเวลาได้');
            }
            if (in_array($t['status'], ['COMPLETED','CANCELLED'])) {
                throw new BusinessRuleException('ไม่สามารถย้ายงานที่ปิดแล้ว');
            }
            $s = Time::toUtc($d['start']);
            $e = Time::toUtc($d['end']);
            if ($e <= $s) {
                throw new ValidationException(['end' => 'เวลาสิ้นสุดต้องหลังเริ่ม']);
            }
            Database::update('tickets', ['scheduled_start' => $s,'scheduled_end' => $e], 'id=?', [$t['id']]);
            TicketService::log((int)$t['id'], (int)$t['hotel_id'], $t['status'], $t['status'], 'reschedule', 'ปรับกำหนดจาก '.($t['scheduled_start'] ?? 'ไม่ได้กำหนด').' เป็น '.$s.' ถึง '.$e, Auth::id());
            \App\Services\Audit::log('ticket.reschedule', 'ticket', (int)$t['id'], (int)$t['hotel_id'], ['start' => $t['scheduled_start'],'end' => $t['scheduled_end']], ['start' => $s,'end' => $e]);
        });
        return $this->json(['ok' => true]);
    }
}

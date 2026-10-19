<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Controller;
use App\Core\Database;
use App\Core\Exceptions\NotFoundException;
use App\Core\HotelContext;
use App\Core\Response;
use App\Helpers\Time;
use App\Models\TicketStatus;
use App\Policies\TicketPolicy;
use App\Services\InAppNotifications;
use App\Services\InventoryService;
use App\Services\KPIService;

final class DashboardController extends Controller
{
    public function index(): Response
    {
        [$from, $to] = KPIService::range($this->request->query['from'] ?? null, $this->request->query['to'] ?? null);
        [$vis, $vp] = TicketPolicy::visibility('t');
        $kpi = new KPIService($vis, $vp, $from, $to);
        $summary = $kpi->summary();
        $costHotels = HotelContext::scope('costs.view');
        $canCost = $costHotels !== [];
        $selected = HotelContext::selectedHotel();
        $multiHotel = $selected === null && count(HotelContext::accessibleHotels()) > 1;

        // Previous period of equal length (for monthly comparison)
        $days = (int) (new \DateTimeImmutable($from))->diff(new \DateTimeImmutable($to))->days + 1;
        $pFrom = (new \DateTimeImmutable($from))->modify("-{$days} days")->format('Y-m-d');
        $pTo = (new \DateTimeImmutable($from))->modify('-1 day')->format('Y-m-d');
        $prev = (new KPIService($vis, $vp, $pFrom, $pTo))->summary();

        $byStatus = $kpi->byStatus();
        $byHotel = $multiHotel ? $kpi->byHotel() : [];
        $trend = $kpi->monthlyTrend();
        $byCategory = $kpi->byCategory(8);
        $byPriority = $kpi->byPriority();
        $workload = HotelContext::scope('tickets.view') ? $kpi->technicianWorkload(8) : [];
        $split = $kpi->typeSplit();
        $costByHotel = $canCost ? KPIService::costByHotel($costHotels, $from, $to) : [];
        $costTotal = $canCost ? KPIService::costTotal($costHotels, $from, $to) : null;

        $charts = [
            'chart-status' => ['type' => 'doughnut', 'labels' => array_column($byStatus, 'label'), 'datasets' => [['data' => array_column($byStatus, 'n'), 'backgroundColor' => array_column($byStatus, 'color')]]],
            'chart-trend' => ['type' => 'line', 'labels' => array_map([self::class, 'monthLabel'], array_column($trend, 'month')), 'datasets' => [
                ['label' => 'แจ้งซ่อม', 'data' => array_column($trend, 'created'), 'borderColor' => '#2563EB', 'backgroundColor' => 'rgba(37,99,235,.10)'],
                ['label' => 'ปิดงาน', 'data' => array_column($trend, 'completed'), 'borderColor' => '#22C55E', 'fill' => false],
            ]],
            'chart-category' => ['type' => 'bar', 'horizontal' => true, 'labels' => array_column($byCategory, 'label'), 'datasets' => [['label' => 'จำนวนงาน', 'data' => array_map('intval', array_column($byCategory, 'n')), 'backgroundColor' => array_column($byCategory, 'color')]]],
            'chart-priority' => ['type' => 'doughnut', 'labels' => array_column($byPriority, 'label'), 'datasets' => [['data' => array_map('intval', array_column($byPriority, 'n')), 'backgroundColor' => array_column($byPriority, 'color')]]],
            'chart-type' => ['type' => 'doughnut', 'labels' => ['งานซ่อมแก้ไข (CM)', 'บำรุงรักษาเชิงป้องกัน (PM)'], 'datasets' => [['data' => [$split['CORRECTIVE'], $split['PREVENTIVE']], 'backgroundColor' => ['#F59E0B', '#14B8A6']]]],
            'chart-workload' => ['type' => 'bar', 'stacked' => true, 'labels' => array_column($workload, 'name'), 'datasets' => [
                ['label' => 'เสร็จแล้ว', 'data' => array_map('intval', array_column($workload, 'completed')), 'backgroundColor' => '#22C55E'],
                ['label' => 'ค้างอยู่', 'data' => array_map('intval', array_column($workload, 'open_count')), 'backgroundColor' => '#F59E0B'],
            ]],
        ];
        if ($byHotel) {
            $charts['chart-hotel'] = ['type' => 'bar', 'labels' => array_column($byHotel, 'code'), 'datasets' => [
                ['label' => 'ทั้งหมด', 'data' => array_map('intval', array_column($byHotel, 'total')), 'backgroundColor' => array_column($byHotel, 'accent_color')],
                ['label' => 'ค้างอยู่', 'data' => array_map('intval', array_column($byHotel, 'open_count')), 'backgroundColor' => '#CBD5E1'],
            ]];
        }
        if ($costByHotel) {
            $charts['chart-cost'] = ['type' => 'bar', 'money' => true, 'stacked' => true, 'labels' => array_column($costByHotel, 'code'), 'datasets' => [
                ['label' => 'อะไหล่', 'data' => array_map('floatval', array_column($costByHotel, 'parts')), 'backgroundColor' => '#3B82F6'],
                ['label' => 'ค่าแรง', 'data' => array_map('floatval', array_column($costByHotel, 'labor')), 'backgroundColor' => '#8B5CF6'],
                ['label' => 'ผู้รับเหมา', 'data' => array_map('floatval', array_column($costByHotel, 'contractor')), 'backgroundColor' => '#F59E0B'],
                ['label' => 'อื่น ๆ', 'data' => array_map('floatval', array_column($costByHotel, 'other')), 'backgroundColor' => '#94A3B8'],
            ]];
        }

        $lowStock = can('inventory.view') ? array_slice(InventoryService::lowStock(HotelContext::scope('inventory.view')), 0, 6) : [];
        $myJobs = [];
        if (can('tickets.work')) {
            $myJobs = Database::select(
                "SELECT t.id, t.ticket_no, t.hotel_id, t.title, t.status, t.sla_resolution_due, p.name_th AS priority_name, p.level AS priority_level, p.color AS priority_color, l.name AS location_name
                 FROM tickets t JOIN priorities p ON p.id = t.priority_id LEFT JOIN locations l ON l.id = t.location_id
                 WHERE t.assigned_to = ? AND t.status NOT IN ('COMPLETED','CANCELLED','PENDING_VERIFICATION') AND t.hotel_id IN (" . Database::placeholders(HotelContext::scope('tickets.work')) . ")
                 ORDER BY p.level DESC, t.sla_resolution_due LIMIT 6",
                [Auth::id(), ...HotelContext::scope('tickets.work')]
            );
        }

        return $this->view('dashboard/index', [
            'title' => 'Dashboard',
            'from' => $from, 'to' => $to,
            'summary' => $summary, 'prev' => $prev,
            'pm' => $kpi->pmCompletion(),
            'charts' => $charts,
            'byHotel' => $byHotel,
            'workload' => $workload,
            'recent' => $kpi->recent(8),
            'costTotal' => $costTotal,
            'canCost' => $canCost,
            'lowStock' => $lowStock,
            'myJobs' => $myJobs,
            'selected' => $selected,
            'multiHotel' => $multiHotel,
        ]);
    }

    public static function monthLabel(string $ym): string
    {
        $m = ['01' => 'ม.ค.', '02' => 'ก.พ.', '03' => 'มี.ค.', '04' => 'เม.ย.', '05' => 'พ.ค.', '06' => 'มิ.ย.', '07' => 'ก.ค.', '08' => 'ส.ค.', '09' => 'ก.ย.', '10' => 'ต.ค.', '11' => 'พ.ย.', '12' => 'ธ.ค.'];
        return ($m[substr($ym, 5, 2)] ?? substr($ym, 5, 2)) . ' ' . substr((string) ((int) substr($ym, 0, 4) + 543), 2);
    }

    public function switchHotel(): Response
    {
        $v = $this->request->str('hotel_id');
        $ok = $v === 'all' ? HotelContext::select(null) : HotelContext::select(ctype_digit($v) ? (int) $v : -1);
        if (!$ok) {
            $this->flash('error', 'คุณไม่มีสิทธิ์เข้าถึงโรงแรมนี้');
        } else {
            $h = HotelContext::selectedHotel();
            $this->flash('success', 'เปลี่ยนพื้นที่ทำงานเป็น ' . ($h ? $h['name_en'] : 'ทุกโรงแรม'));
        }
        return $this->back();
    }

    /** Global search: tickets (visible), assets, parts – all tenant scoped */
    public function search(): Response
    {
        $q = mb_substr($this->request->str('q'), 0, 100);
        $results = ['tickets' => [], 'assets' => [], 'parts' => []];
        if (mb_strlen($q) >= 2) {
            $like = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\%', '\_'], $q) . '%';
            [$vis, $vp] = TicketPolicy::visibility('t');
            $results['tickets'] = Database::select(
                "SELECT t.id, t.ticket_no, t.hotel_id, t.title, t.status, t.sla_resolution_due, t.requested_at FROM tickets t
                 LEFT JOIN locations l ON l.id = t.location_id
                 WHERE $vis AND (t.ticket_no LIKE ? OR t.title LIKE ? OR l.name LIKE ? OR t.location_text LIKE ?)
                 ORDER BY t.requested_at DESC LIMIT 20",
                [...$vp, $like, $like, $like, $like]
            );
            if ($ah = HotelContext::scope('assets.view')) {
                $results['assets'] = Database::select(
                    'SELECT a.id, a.asset_code, a.name, a.hotel_id, a.status, l.name AS location_name FROM assets a LEFT JOIN locations l ON l.id = a.location_id
                     WHERE a.deleted_at IS NULL AND a.hotel_id IN (' . Database::placeholders($ah) . ') AND (a.asset_code LIKE ? OR a.name LIKE ? OR a.serial_no LIKE ?)
                     ORDER BY a.asset_code LIMIT 20',
                    [...$ah, $like, $like, $like]
                );
            }
            if (HotelContext::scope('inventory.view')) {
                $results['parts'] = Database::select(
                    'SELECT id, sku, name, unit FROM spare_parts WHERE (sku LIKE ? OR name LIKE ?) ORDER BY sku LIMIT 20',
                    [$like, $like]
                );
            }
        }
        return $this->view('dashboard/search', ['title' => 'ผลการค้นหา', 'q' => $q, 'results' => $results, 'breadcrumbs' => [['ค้นหา', null]]]);
    }

    public function notifications(): Response
    {
        $uid = $this->userId();
        $total = (int) Database::value('SELECT COUNT(*) FROM user_notifications WHERE user_id = ?', [$uid]);
        $per = 30;
        $rows = Database::select(
            'SELECT * FROM user_notifications WHERE user_id = ? ORDER BY created_at DESC, id DESC LIMIT ' . $per . ' OFFSET ' . (($this->page() - 1) * $per),
            [$uid]
        );
        return $this->view('dashboard/notifications', [
            'title' => 'การแจ้งเตือน', 'rows' => $rows, 'total' => $total, 'page' => $this->page(), 'per' => $per,
            'breadcrumbs' => [['การแจ้งเตือน', null]],
        ]);
    }

    public function readAllNotifications(): Response
    {
        Database::execute('UPDATE user_notifications SET read_at = ? WHERE user_id = ? AND read_at IS NULL', [Time::now(), $this->userId()]);
        $this->flash('success', 'ทำเครื่องหมายอ่านแล้วทั้งหมด');
        return $this->back('/notifications');
    }

    public function openNotification(int $id): Response
    {
        $n = Database::first('SELECT * FROM user_notifications WHERE id = ? AND user_id = ?', [$id, $this->userId()]);
        if (!$n) {
            throw new NotFoundException();
        }
        Database::execute('UPDATE user_notifications SET read_at = COALESCE(read_at, ?) WHERE id = ?', [Time::now(), $id]);
        $url = (string) ($n['url'] ?? '');
        // Only internal relative URLs (no open redirect)
        if ($url === '' || !str_starts_with($url, '/') || str_starts_with($url, '//')) {
            $url = url('/notifications');
        }
        return $this->redirect($url);
    }

    public function unreadCount(): Response
    {
        return $this->json(['count' => InAppNotifications::unreadCount($this->userId())]);
    }
}

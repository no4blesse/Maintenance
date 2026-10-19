<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\{Database,Response,HotelContext};
use App\Services\{KPIService,Audit};
use App\Helpers\Csv;

final class KPIController extends ModuleController
{
    private function data(bool $export = false): array
    {
        [$w,$p] = $this->scope($export ? 'reports.export' : 'reports.view', 't.hotel_id');
        if ($export) {
            [$v,$ids] = $this->scope('reports.view', 't.hotel_id');
            $w .= ' AND '.$v;
            array_push($p, ...$ids);
        }
        [$from,$to] = KPIService::range($this->request->str('from'), $this->request->str('to'));
        $filters = $this->request->only(['department_id','technician_id','asset_category_id','category_id','priority_id','status','type']);
        $kpi = new KPIService($w, $p, $from, $to, $filters);
        $hotelIds = HotelContext::scope('reports.view');
        $departments = $this->options('SELECT id,name_th AS name FROM departments WHERE hotel_id IN ('.Database::placeholders($hotelIds).') AND is_active=1', $hotelIds);
        $technicians = [];
        foreach ($hotelIds as $h) {
            foreach (\App\Services\TicketService::technicians($h) as $tech) {
                $technicians[$tech['id']] = $tech['name'];
            }
        }
        return ['title' => 'รายงาน KPI','from' => $from,'to' => $to,'filters' => $filters,'summary' => $kpi->summary(),'repeat' => $kpi->repeatRepair(),'pm' => $kpi->pmCompletion(),'hotels' => $kpi->byHotel(),'workload' => $kpi->technicianWorkload(),'status' => $kpi->byStatus(),'trend' => $kpi->monthlyTrend(),'priority' => $kpi->byPriority(),'category' => $kpi->byCategory(),'split' => $kpi->typeSplit(),'cost' => KPIService::costByHotel(array_values(array_intersect(HotelContext::scope('costs.view'), HotelContext::scope($export ? 'reports.export' : 'reports.view'))), $from, $to),
            'categories' => $this->options('SELECT id,name_th AS name FROM maintenance_categories WHERE is_active=1'),'priorities' => $this->options('SELECT id,name_th AS name FROM priorities WHERE is_active=1'),'assetCategories' => $this->options('SELECT id,name_th AS name FROM asset_categories WHERE is_active=1'),'departments' => $departments,'technicians' => $technicians];
    }
    public function index(): Response
    {
        return $this->view('reports/kpi', $this->data());
    }
    public function printView(): Response
    {
        return $this->view('reports/kpi', $this->data() + ['printMode' => true]);
    }
    public function export(): Response
    {
        $d = $this->data(true);
        $rows = [];
        foreach ($d['summary'] as $k => $v) {
            $rows[] = [$k,$v];
        }foreach ($d['hotels'] as $h) {
            $rows[] = ['hotel:'.$h['code'],json_encode($h, JSON_UNESCAPED_UNICODE)];
        }Audit::log('kpi.export', 'report', null);
        return Csv::response('kpi.csv', ['Metric','Value'], $rows);
    }
}

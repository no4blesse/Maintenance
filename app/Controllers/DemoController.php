<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\{Auth, Controller, Database, HotelContext, Response};
use App\Core\Exceptions\NotFoundException;
use App\Services\DemoShowcaseSeeder;

final class DemoController extends Controller
{
    public function index(): Response
    {
        if (!DemoShowcaseSeeder::isTestWorkspace() || !Auth::gate()->hasGlobal('users.manage')) {
            throw new NotFoundException();
        }
        $manifest = DemoShowcaseSeeder::manifest();
        if (!$manifest) {
            throw new NotFoundException();
        }
        $scope = HotelContext::scope('reports.view');
        $hotels = array_filter($manifest['hotels'], static fn ($h) => in_array((int) $h['id'], $scope, true));
        $code = $this->request->str('hotel');
        if (!isset($hotels[$code])) {
            $code = array_key_first($hotels);
        }
        if ($code === null) {
            throw new NotFoundException();
        }
        $hotel = $hotels[$code];
        $ids = array_values(array_unique(array_merge($hotel['history'], array_values($hotel['scenarios']), array_column($hotel['pm'], 'ticket_id'))));
        $tickets = [];
        foreach (Database::select('SELECT id,ticket_no,title,status FROM tickets WHERE hotel_id=? AND id IN ('.Database::placeholders($ids).')', array_merge([(int) $hotel['id']], $ids)) as $row) {
            $tickets[(int) $row['id']] = $row;
        }
        $closedId = (int) ($hotel['history'][0] ?? 0);
        $costs = Database::select("SELECT cc.name_th AS name,SUM(ce.total_cost) AS amount FROM cost_entries ce JOIN cost_categories cc ON cc.id=ce.category_id WHERE ce.ticket_id=? AND ce.hotel_id=? AND ce.status='APPROVED' GROUP BY cc.id,cc.name_th", [$closedId, $hotel['id']]);
        $title = 'เริ่มสาธิตระบบ';
        return $this->view('demo/index', compact('title', 'manifest', 'hotels', 'hotel', 'code', 'tickets', 'closedId', 'costs'));
    }
}

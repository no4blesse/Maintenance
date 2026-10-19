<?php

declare(strict_types=1);

namespace App\Controllers\Api;

use App\Core\Auth;
use App\Core\Controller;
use App\Core\Database;
use App\Core\Exceptions\ForbiddenException;
use App\Core\Response;
use App\Services\TicketService;

/** Hotel-scoped lookup data for dependent dropdowns. Never trusts hotel_id alone. */
final class LookupController extends Controller
{
    /** Permissions a form may declare as the context for loading options */
    private const CONTEXT_PERMS = ['tickets.create', 'tickets.update', 'assets.create', 'assets.update', 'assets.view', 'pm.manage', 'costs.create', 'calendar.view', 'reports.view', 'inventory.view', 'tickets.view'];

    public function hotelOptions(): Response
    {
        $hotelId = (int) $this->request->int('hotel_id', 0);
        $perm = $this->request->str('perm', 'tickets.create');
        if (!in_array($perm, self::CONTEXT_PERMS, true) || !Auth::gate()->can($perm, $hotelId)) {
            throw new ForbiddenException();
        }
        $data = [
            'buildings' => Database::select("SELECT id, CONCAT(name, ' (', code, ')') AS label FROM buildings WHERE hotel_id = ? AND is_active = 1 ORDER BY sort_order, name", [$hotelId]),
            'floors' => Database::select('SELECT f.id, f.name AS label, f.building_id FROM floors f WHERE f.hotel_id = ? AND f.is_active = 1 ORDER BY f.building_id, f.level_no, f.name', [$hotelId]),
            'locations' => Database::select("SELECT id, CONCAT(code, ' · ', name) AS label, building_id, floor_id FROM locations WHERE hotel_id = ? AND is_active = 1 ORDER BY code", [$hotelId]),
            'departments' => Database::select('SELECT id, name_th AS label FROM departments WHERE hotel_id = ? AND is_active = 1 ORDER BY name_th', [$hotelId]),
            'assets' => [],
            'technicians' => [],
        ];
        if (Auth::gate()->can('assets.view', $hotelId) || Auth::gate()->can('tickets.create', $hotelId)) {
            $data['assets'] = Database::select(
                "SELECT id, CONCAT(asset_code, ' · ', name) AS label, location_id, building_id, floor_id FROM assets
                 WHERE hotel_id = ? AND deleted_at IS NULL AND status <> 'DISPOSED' ORDER BY asset_code LIMIT 2000",
                [$hotelId]
            );
        }
        $g = Auth::gate();
        if ($g->can('tickets.assign', $hotelId) || $g->can('pm.manage', $hotelId) || $g->can('reports.view', $hotelId)) {
            $data['technicians'] = array_map(static fn ($t) => ['id' => $t['id'], 'label' => $t['name'] . ' (งานค้าง ' . $t['open_jobs'] . ')'], TicketService::technicians($hotelId));
        }
        return $this->json($data);
    }

    /** Part search with on-hand quantity at a hotel the user may view/issue in. */
    public function parts(): Response
    {
        $hotelId = (int) $this->request->int('hotel_id', 0);
        $g = Auth::gate();
        if (!$g->can('inventory.view', $hotelId) && !$g->can('inventory.issue', $hotelId)) {
            throw new ForbiddenException();
        }
        $q = mb_substr($this->request->str('q'), 0, 60);
        $like = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\%', '\_'], $q) . '%';
        $rows = Database::select(
            'SELECT sp.id, sp.sku, sp.name, sp.unit, COALESCE(hs.qty_on_hand, 0) AS qty, COALESCE(hs.avg_cost, sp.cost_price) AS cost
             FROM spare_parts sp LEFT JOIN hotel_stock hs ON hs.part_id = sp.id AND hs.hotel_id = ?
             WHERE sp.is_active = 1 AND (sp.sku LIKE ? OR sp.name LIKE ?)
             ORDER BY (COALESCE(hs.qty_on_hand,0) > 0) DESC, sp.sku LIMIT 30',
            [$hotelId, $like, $like]
        );
        return $this->json(array_map(static fn ($r) => [
            'id' => (int) $r['id'],
            'label' => $r['sku'] . ' · ' . $r['name'] . ' (คงเหลือ ' . qty($r['qty']) . ' ' . $r['unit'] . ')',
            'qty' => (string) $r['qty'], 'unit' => $r['unit'], 'cost' => (string) $r['cost'],
        ], $rows));
    }
}

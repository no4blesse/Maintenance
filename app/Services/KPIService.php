<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Helpers\Time;
use App\Models\TicketStatus as S;

/**
 * KPI & analytics over real records. See docs/KPI_DEFINITIONS.md.
 *
 * A KPIService instance represents a COHORT:
 *   base condition (tenant scope / visibility) + filters + request-date range.
 * Cohort = tickets whose requested_at falls in [from, to) (local dates converted to UTC).
 */
final class KPIService
{
    private string $where;
    /** @var list<mixed> */
    private array $params;
    private string $fromUtc;
    private string $toUtc;
    private int $offsetMin;

    /**
     * @param string $baseWhere SQL condition on alias t (already tenant-scoped)
     * @param array<string, mixed> $f filters: department_id, technician_id, asset_category_id, category_id, priority_id, status, type
     */
    public function __construct(string $baseWhere, array $baseParams, public readonly string $fromDate, public readonly string $toDate, array $f = [])
    {
        [$this->fromUtc, $this->toUtc] = Time::localDateRangeToUtc($fromDate, $toDate);
        $this->offsetMin = (int) (Time::nowImmutable()->setTimezone(Time::tz())->getOffset() / 60);
        $w = [$baseWhere];
        $p = $baseParams;
        $map = [
            'department_id' => 't.reporter_department_id = ?',
            'technician_id' => 't.assigned_to = ?',
            'category_id' => 't.category_id = ?',
            'priority_id' => 't.priority_id = ?',
            'asset_category_id' => 't.asset_id IN (SELECT id FROM assets WHERE category_id = ?)',
        ];
        foreach ($map as $k => $sql) {
            if (!empty($f[$k])) {
                $w[] = $sql;
                $p[] = (int) $f[$k];
            }
        }
        if (!empty($f['status']) && isset(S::LABELS[$f['status']])) {
            $w[] = 't.status = ?';
            $p[] = (string) $f['status'];
        } elseif (($f['status'] ?? '') === 'OPEN') {
            $w[] = "t.status NOT IN ('COMPLETED','CANCELLED')";
        }
        if (in_array($f['type'] ?? '', ['CORRECTIVE', 'PREVENTIVE'], true)) {
            $w[] = 't.ticket_type = ?';
            $p[] = $f['type'];
        }
        $this->where = implode(' AND ', $w);
        $this->params = $p;
    }

    /** Cohort condition (scope + filters + request date range) */
    private function cohort(): array
    {
        return [$this->where . ' AND t.requested_at >= ? AND t.requested_at < ?', [...$this->params, $this->fromUtc, $this->toUtc]];
    }

    private function localExpr(string $col): string
    {
        return "DATE_ADD($col, INTERVAL {$this->offsetMin} MINUTE)";
    }

    // ------------------------------------------------------------------ headline metrics

    public function summary(): array
    {
        [$w, $p] = $this->cohort();
        $now = Time::now();
        $mttrExpr = (bool) setting('kpi_mttr_exclude_waiting', false)
            ? 'GREATEST(0, TIMESTAMPDIFF(SECOND, t.requested_at, t.technically_completed_at) - t.waiting_seconds)'
            : 'TIMESTAMPDIFF(SECOND, t.requested_at, t.technically_completed_at)';
        $r = Database::first(
            "SELECT COUNT(*) AS total,
                SUM(t.status = 'NEW') AS new_count,
                SUM(t.status IN ('PENDING_REVIEW','ASSIGNED','REOPENED','WAITING_PARTS','WAITING_CONTRACTOR','WAITING_APPROVAL','PENDING_VERIFICATION')) AS pending,
                SUM(t.status = 'IN_PROGRESS') AS in_progress,
                SUM(t.status IN ('WAITING_PARTS','WAITING_CONTRACTOR','WAITING_APPROVAL')) AS waiting,
                SUM(t.status = 'PENDING_VERIFICATION') AS pending_verification,
                SUM(t.status = 'COMPLETED') AS completed,
                SUM(t.status = 'CANCELLED') AS cancelled,
                SUM(t.status NOT IN ('COMPLETED','CANCELLED')) AS open_count,
                SUM(t.status NOT IN ('COMPLETED','CANCELLED') AND t.sla_resolution_due IS NOT NULL AND t.sla_resolution_due < ?) AS overdue,
                SUM(t.reopen_count > 0) AS reopened,
                AVG(CASE WHEN t.status <> 'CANCELLED' AND t.acknowledged_at IS NOT NULL THEN TIMESTAMPDIFF(SECOND, t.requested_at, t.acknowledged_at) END) AS mtta,
                AVG(CASE WHEN t.status = 'COMPLETED' AND t.technically_completed_at IS NOT NULL THEN $mttrExpr END) AS mttr,
                SUM(t.status = 'COMPLETED' AND t.sla_resolution_due IS NOT NULL AND t.technically_completed_at IS NOT NULL) AS sla_eligible,
                SUM(t.status = 'COMPLETED' AND t.sla_resolution_due IS NOT NULL AND t.technically_completed_at IS NOT NULL AND t.technically_completed_at <= t.sla_resolution_due) AS sla_met,
                SUM(t.status <> 'CANCELLED' AND t.sla_response_due IS NOT NULL AND t.acknowledged_at IS NOT NULL) AS resp_eligible,
                SUM(t.status <> 'CANCELLED' AND t.sla_response_due IS NOT NULL AND t.acknowledged_at IS NOT NULL AND t.acknowledged_at <= t.sla_response_due) AS resp_met,
                COALESCE(SUM(CASE WHEN t.status = 'COMPLETED' THEN t.cost_total END), 0) AS completed_cost
             FROM tickets t WHERE $w",
            [$now, ...$p]
        ) ?? [];
        $i = static fn ($k) => (int) ($r[$k] ?? 0);
        $qualifying = $i('total') - $i('cancelled');
        return [
            'total' => $i('total'),
            'new' => $i('new_count'),
            'pending' => $i('pending'),
            'in_progress' => $i('in_progress'),
            'waiting' => $i('waiting'),
            'pending_verification' => $i('pending_verification'),
            'completed' => $i('completed'),
            'cancelled' => $i('cancelled'),
            'open' => $i('open_count'),
            'overdue' => $i('overdue'),
            'reopened' => $i('reopened'),
            'qualifying' => $qualifying,
            'completion_rate' => $qualifying > 0 ? round($i('completed') * 100 / $qualifying, 1) : null,
            'mtta' => $r['mtta'] !== null ? (float) $r['mtta'] : null,
            'mttr' => $r['mttr'] !== null ? (float) $r['mttr'] : null,
            'sla_eligible' => $i('sla_eligible'),
            'sla_met' => $i('sla_met'),
            'sla_compliance' => $i('sla_eligible') > 0 ? round($i('sla_met') * 100 / $i('sla_eligible'), 1) : null,
            'response_compliance' => $i('resp_eligible') > 0 ? round($i('resp_met') * 100 / $i('resp_eligible'), 1) : null,
            'completed_cost' => (string) ($r['completed_cost'] ?? '0'),
        ];
    }

    /**
     * Repeat repair rate: share of cohort corrective tickets (asset-linked, not
     * cancelled) for which the SAME asset had another corrective ticket
     * technically completed within N days before this ticket was requested.
     */
    public function repeatRepair(): array
    {
        [$w, $p] = $this->cohort();
        $days = max(1, (int) setting('kpi_repeat_window_days', 30));
        $r = Database::first(
            "SELECT COUNT(*) AS base,
                    SUM(EXISTS (SELECT 1 FROM tickets p WHERE p.asset_id = t.asset_id AND p.id <> t.id AND p.ticket_type = 'CORRECTIVE'
                                AND p.status <> 'CANCELLED' AND p.technically_completed_at IS NOT NULL
                                AND p.technically_completed_at <= t.requested_at
                                AND p.technically_completed_at >= DATE_SUB(t.requested_at, INTERVAL ? DAY))) AS repeats
             FROM tickets t WHERE $w AND t.ticket_type = 'CORRECTIVE' AND t.asset_id IS NOT NULL AND t.status <> 'CANCELLED'",
            [$days, ...$p]
        ) ?? [];
        $base = (int) ($r['base'] ?? 0);
        $rep = (int) ($r['repeats'] ?? 0);
        return ['base' => $base, 'repeats' => $rep, 'window_days' => $days, 'rate' => $base > 0 ? round($rep * 100 / $base, 1) : null];
    }

    /**
     * PM completion: PM work orders DUE (due_date in range, not cancelled).
     * On time = ticket technically completed on/before the due date (local) and verified.
     */
    public function pmCompletion(): array
    {
        $r = Database::first(
            "SELECT COUNT(*) AS due,
                    SUM(w.status = 'COMPLETED') AS completed,
                    SUM(w.status = 'COMPLETED' AND DATE(" . $this->localExpr('t.technically_completed_at') . ") <= w.due_date) AS on_time,
                    SUM(w.status = 'OPEN' AND w.due_date < ?) AS overdue
             FROM pm_work_orders w JOIN tickets t ON t.id = w.ticket_id
             WHERE {$this->where} AND w.status <> 'CANCELLED' AND w.due_date BETWEEN ? AND ?",
            [Time::today(), ...$this->params, $this->fromDate, $this->toDate]
        ) ?? [];
        $due = (int) ($r['due'] ?? 0);
        return [
            'due' => $due,
            'completed' => (int) ($r['completed'] ?? 0),
            'on_time' => (int) ($r['on_time'] ?? 0),
            'overdue' => (int) ($r['overdue'] ?? 0),
            'rate' => $due > 0 ? round((int) $r['on_time'] * 100 / $due, 1) : null,
        ];
    }

    // ------------------------------------------------------------------ breakdowns (charts)

    public function byStatus(): array
    {
        [$w, $p] = $this->cohort();
        $rows = Database::select("SELECT t.status, COUNT(*) AS n FROM tickets t WHERE $w GROUP BY t.status", $p);
        $out = [];
        foreach (S::LABELS as $code => $label) {
            foreach ($rows as $r) {
                if ($r['status'] === $code) {
                    $out[] = ['status' => $code, 'label' => S::label($code), 'n' => (int) $r['n'], 'color' => S::chartColor($code)];
                }
            }
        }
        return $out;
    }

    public function byHotel(): array
    {
        [$w, $p] = $this->cohort();
        return Database::select(
            "SELECT h.id, h.code, h.name_en, h.accent_color, COUNT(t.id) AS total,
                    SUM(t.status = 'COMPLETED') AS completed,
                    SUM(t.status NOT IN ('COMPLETED','CANCELLED')) AS open_count,
                    SUM(t.status NOT IN ('COMPLETED','CANCELLED') AND t.sla_resolution_due < ?) AS overdue,
                    SUM(t.status = 'CANCELLED') AS cancelled,
                    AVG(CASE WHEN t.status = 'COMPLETED' THEN TIMESTAMPDIFF(SECOND, t.requested_at, t.technically_completed_at) END) AS mttr,
                    SUM(t.status = 'COMPLETED' AND t.sla_resolution_due IS NOT NULL AND t.technically_completed_at <= t.sla_resolution_due) AS sla_met,
                    SUM(t.status = 'COMPLETED' AND t.sla_resolution_due IS NOT NULL AND t.technically_completed_at IS NOT NULL) AS sla_eligible
             FROM tickets t JOIN hotels h ON h.id = t.hotel_id WHERE $w GROUP BY h.id, h.code, h.name_en, h.accent_color ORDER BY h.sort_order",
            [Time::now(), ...$p]
        );
    }

    public function byCategory(int $limit = 12): array
    {
        [$w, $p] = $this->cohort();
        return Database::select(
            "SELECT mc.id, mc.name_th AS label, mc.color, COUNT(*) AS n, COALESCE(SUM(t.cost_total),0) AS cost
             FROM tickets t JOIN maintenance_categories mc ON mc.id = t.category_id WHERE $w
             GROUP BY mc.id, mc.name_th, mc.color ORDER BY n DESC LIMIT " . (int) $limit,
            $p
        );
    }

    public function byPriority(): array
    {
        [$w, $p] = $this->cohort();
        return Database::select(
            "SELECT pr.id, pr.code, pr.name_th AS label, pr.color, COUNT(t.id) AS n,
                    SUM(t.status = 'COMPLETED' AND t.technically_completed_at <= t.sla_resolution_due) AS sla_met,
                    SUM(t.status = 'COMPLETED' AND t.sla_resolution_due IS NOT NULL AND t.technically_completed_at IS NOT NULL) AS sla_eligible,
                    AVG(CASE WHEN t.status = 'COMPLETED' THEN TIMESTAMPDIFF(SECOND, t.requested_at, t.technically_completed_at) END) AS mttr
             FROM tickets t JOIN priorities pr ON pr.id = t.priority_id WHERE $w
             GROUP BY pr.id, pr.code, pr.name_th, pr.color, pr.level ORDER BY pr.level",
            $p
        );
    }

    public function typeSplit(): array
    {
        [$w, $p] = $this->cohort();
        $rows = Database::select("SELECT t.ticket_type, COUNT(*) AS n FROM tickets t WHERE $w GROUP BY t.ticket_type", $p);
        $out = ['CORRECTIVE' => 0, 'PREVENTIVE' => 0];
        foreach ($rows as $r) {
            $out[$r['ticket_type']] = (int) $r['n'];
        }
        return $out;
    }

    /** Created vs completed per local month for the cohort range */
    public function monthlyTrend(): array
    {
        $months = [];
        $cur = new \DateTimeImmutable(substr($this->fromDate, 0, 7) . '-01');
        $end = new \DateTimeImmutable(substr($this->toDate, 0, 7) . '-01');
        $guard = 0;
        while ($cur <= $end && $guard++ < 60) {
            $months[$cur->format('Y-m')] = ['month' => $cur->format('Y-m'), 'created' => 0, 'completed' => 0, 'cost' => '0'];
            $cur = $cur->modify('+1 month');
        }
        [$w, $p] = $this->cohort();
        foreach (Database::select("SELECT DATE_FORMAT(" . $this->localExpr('t.requested_at') . ", '%Y-%m') AS m, COUNT(*) AS n FROM tickets t WHERE $w GROUP BY m", $p) as $r) {
            if (isset($months[$r['m']])) {
                $months[$r['m']]['created'] = (int) $r['n'];
            }
        }
        // completions are counted in the month they were technically completed
        foreach (Database::select(
            "SELECT DATE_FORMAT(" . $this->localExpr('t.technically_completed_at') . ", '%Y-%m') AS m, COUNT(*) AS n
             FROM tickets t WHERE {$this->where} AND t.status = 'COMPLETED' AND t.technically_completed_at >= ? AND t.technically_completed_at < ? GROUP BY m",
            [...$this->params, $this->fromUtc, $this->toUtc]
        ) as $r) {
            if (isset($months[$r['m']])) {
                $months[$r['m']]['completed'] = (int) $r['n'];
            }
        }
        return array_values($months);
    }

    public function technicianWorkload(int $limit = 15): array
    {
        [$w, $p] = $this->cohort();
        $rows = Database::select(
            "SELECT u.id, u.name, COUNT(t.id) AS assigned,
                    SUM(t.status = 'COMPLETED') AS completed,
                    SUM(t.status NOT IN ('COMPLETED','CANCELLED')) AS open_count,
                    SUM(t.status NOT IN ('COMPLETED','CANCELLED') AND t.sla_resolution_due < ?) AS overdue,
                    AVG(CASE WHEN t.status = 'COMPLETED' THEN TIMESTAMPDIFF(SECOND, t.requested_at, t.technically_completed_at) END) AS mttr,
                    SUM(t.status = 'COMPLETED' AND t.sla_resolution_due IS NOT NULL AND t.technically_completed_at <= t.sla_resolution_due) AS sla_met,
                    SUM(t.status = 'COMPLETED' AND t.sla_resolution_due IS NOT NULL AND t.technically_completed_at IS NOT NULL) AS sla_eligible
             FROM tickets t JOIN users u ON u.id = t.assigned_to WHERE $w
             GROUP BY u.id, u.name ORDER BY assigned DESC LIMIT " . (int) $limit,
            [Time::now(), ...$p]
        );
        if ($rows) {
            $ids = array_map(static fn ($r) => (int) $r['id'], $rows);
            $hours = Database::select(
                'SELECT wl.technician_id, SUM(wl.hours) AS h FROM work_logs wl JOIN tickets t ON t.id = wl.ticket_id
                 WHERE ' . $this->where . ' AND wl.started_at >= ? AND wl.started_at < ? AND wl.technician_id IN (' . Database::placeholders($ids) . ')
                 GROUP BY wl.technician_id',
                [...$this->params, $this->fromUtc, $this->toUtc, ...$ids]
            );
            $hm = array_column($hours, 'h', 'technician_id');
            foreach ($rows as &$r) {
                $r['hours'] = (float) ($hm[$r['id']] ?? 0);
            }
            unset($r);
        }
        return $rows;
    }

    public function recent(int $limit = 8): array
    {
        return Database::select(
            "SELECT t.id, t.ticket_no, t.hotel_id, t.title, t.status, t.requested_at, t.sla_resolution_due, t.ticket_type,
                    p.name_th AS priority_name, p.level AS priority_level, p.color AS priority_color,
                    l.name AS location_name, t.location_text, u.name AS assignee_name
             FROM tickets t JOIN priorities p ON p.id = t.priority_id
             LEFT JOIN locations l ON l.id = t.location_id LEFT JOIN users u ON u.id = t.assigned_to
             WHERE {$this->where} ORDER BY t.requested_at DESC, t.id DESC LIMIT " . (int) $limit,
            $this->params
        );
    }

    // ------------------------------------------------------------------ costs (ledger based)

    /**
     * Approved maintenance costs by hotel within the date range (expense_date, local).
     * @param list<int> $hotelIds hotels where the user holds costs.view/reports.view
     */
    public static function costByHotel(array $hotelIds, string $from, string $to): array
    {
        if (!$hotelIds) {
            return [];
        }
        return Database::select(
            "SELECT h.id, h.code, h.name_en, h.accent_color,
                    COALESCE(SUM(CASE WHEN cc.code = 'PARTS' THEN ce.total_cost END),0) AS parts,
                    COALESCE(SUM(CASE WHEN cc.code = 'LABOR' THEN ce.total_cost END),0) AS labor,
                    COALESCE(SUM(CASE WHEN cc.code = 'CONTRACTOR' THEN ce.total_cost END),0) AS contractor,
                    COALESCE(SUM(CASE WHEN cc.code IN ('REPLACEMENT','OTHER') THEN ce.total_cost END),0) AS other,
                    COALESCE(SUM(ce.total_cost),0) AS total
             FROM hotels h
             LEFT JOIN cost_entries ce ON ce.hotel_id = h.id AND ce.status = 'APPROVED' AND ce.expense_date BETWEEN ? AND ?
             LEFT JOIN cost_categories cc ON cc.id = ce.category_id
             WHERE h.id IN (" . Database::placeholders($hotelIds) . ")
             GROUP BY h.id, h.code, h.name_en, h.accent_color, h.sort_order ORDER BY h.sort_order",
            [$from, $to, ...$hotelIds]
        );
    }

    public static function costTotal(array $hotelIds, string $from, string $to): string
    {
        if (!$hotelIds) {
            return '0.00';
        }
        return (string) Database::value(
            "SELECT COALESCE(SUM(total_cost),0) FROM cost_entries WHERE status = 'APPROVED' AND expense_date BETWEEN ? AND ? AND hotel_id IN (" . Database::placeholders($hotelIds) . ')',
            [$from, $to, ...$hotelIds]
        );
    }

    public static function monthlyCost(array $hotelIds, string $from, string $to): array
    {
        if (!$hotelIds) {
            return [];
        }
        return Database::select(
            "SELECT DATE_FORMAT(expense_date, '%Y-%m') AS m, COALESCE(SUM(total_cost),0) AS total
             FROM cost_entries WHERE status = 'APPROVED' AND expense_date BETWEEN ? AND ? AND hotel_id IN (" . Database::placeholders($hotelIds) . ')
             GROUP BY m ORDER BY m',
            [$from, $to, ...$hotelIds]
        );
    }

    /** Default range: first day of the month 5 months ago .. today */
    public static function defaultRange(): array
    {
        $today = Time::today();
        $from = (new \DateTimeImmutable($today))->modify('first day of this month')->modify('-5 months')->format('Y-m-d');
        return [$from, $today];
    }

    /** Sanitises user-supplied date range (max 3 years) */
    public static function range(?string $from, ?string $to): array
    {
        [$df, $dt] = self::defaultRange();
        $ok = static fn ($d) => is_string($d) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $d) && \DateTimeImmutable::createFromFormat('!Y-m-d', $d) !== false;
        $from = $ok($from) ? $from : $df;
        $to = $ok($to) ? $to : $dt;
        if ($to < $from) {
            [$from, $to] = [$to, $from];
        }
        $min = (new \DateTimeImmutable($to))->modify('-3 years')->format('Y-m-d');
        if ($from < $min) {
            $from = $min;
        }
        return [$from, $to];
    }
}

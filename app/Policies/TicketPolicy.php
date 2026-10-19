<?php

declare(strict_types=1);

namespace App\Policies;

use App\Core\Auth;
use App\Core\Database;
use App\Core\Exceptions\NotFoundException;
use App\Core\HotelContext;

/**
 * Ticket visibility:
 *   tickets.view      -> every ticket of that hotel
 *   tickets.view_own  -> tickets the user reported
 *   tickets.work      -> own assigned work and unclaimed NEW/PENDING_REVIEW/REOPENED queue
 * Always intersected with the hotel switcher selection.
 * Unauthorized lookups return 404 (no existence disclosure / IDOR).
 */
final class TicketPolicy
{
    /** @return array{0:string, 1:list<mixed>} SQL fragment + params */
    public static function visibility(string $alias = 't'): array
    {
        $uid = (int) Auth::id();
        $parts = [];
        $params = [];
        $all = HotelContext::scope('tickets.view');
        if ($all) {
            $parts[] = "$alias.hotel_id IN (" . Database::placeholders($all) . ')';
            array_push($params, ...$all);
        }
        $own = array_values(array_diff(HotelContext::scope('tickets.view_own'), $all));
        if ($own) {
            $parts[] = "($alias.hotel_id IN (" . Database::placeholders($own) . ") AND $alias.reporter_id = ?)";
            array_push($params, ...$own);
            $params[] = $uid;
        }
        $work = array_values(array_diff(HotelContext::scope('tickets.work'), $all));
        if ($work) {
            $parts[] = "($alias.hotel_id IN (" . Database::placeholders($work) . ") AND ($alias.assigned_to = ? OR ($alias.assigned_to IS NULL AND $alias.status IN ('NEW','PENDING_REVIEW','REOPENED'))))";
            array_push($params, ...$work);
            $params[] = $uid;
        }
        if (!$parts) {
            return ['1 = 0', []];
        }
        return ['(' . implode(' OR ', $parts) . ')', $params];
    }

    /** Loads a ticket the current user may see, else 404. */
    public static function findVisible(int $id): array
    {
        [$vis, $params] = self::visibility('t');
        $t = Database::first("SELECT t.* FROM tickets t WHERE t.id = ? AND $vis", [$id, ...$params]);
        if (!$t) {
            throw new NotFoundException();
        }
        return $t;
    }

    public static function canView(array $ticket): bool
    {
        $g = Auth::gate();
        $h = (int) $ticket['hotel_id'];
        $uid = (int) Auth::id();
        return $g->can('tickets.view', $h)
            || ($g->can('tickets.view_own', $h) && (int) $ticket['reporter_id'] === $uid)
            || ($g->can('tickets.work', $h) && ((int) $ticket['assigned_to'] === $uid || ($ticket['assigned_to'] === null && in_array($ticket['status'], ['NEW','PENDING_REVIEW','REOPENED'], true))));
    }

    public static function isAssignee(array $ticket): bool
    {
        return $ticket['assigned_to'] !== null && (int) $ticket['assigned_to'] === (int) Auth::id();
    }
}

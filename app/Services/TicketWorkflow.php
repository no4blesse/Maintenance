<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Exceptions\BusinessRuleException;
use App\Core\Exceptions\ForbiddenException;
use App\Core\Permissions;
use App\Models\TicketStatus as S;

/**
 * Work-order state machine. Every status change goes through an ACTION
 * that defines allowed source states, target state and who may run it.
 *
 * actor:
 *   'perm'     -> needs the permission at the ticket's hotel
 *   'assignee' -> needs tickets.work at the hotel AND be the current assignee
 *   'either'   -> assignee rule OR 'alt' permission
 *   'claim'    -> tickets.work and no assignee yet (or the existing assignee)
 */
final class TicketWorkflow
{
    public const ACTIONS = [
        'claim' => ['from' => [S::NEW, S::PENDING_REVIEW, S::REOPENED, S::ASSIGNED], 'to' => S::IN_PROGRESS, 'actor' => 'claim', 'label' => 'รับเรื่องและเริ่มงาน', 'icon' => 'hand', 'tone' => 'primary'],
        'review' => ['from' => [S::NEW], 'to' => S::PENDING_REVIEW, 'actor' => 'perm', 'perm' => 'tickets.assign', 'label' => 'รับเรื่อง/ตรวจสอบ', 'icon' => 'eye', 'tone' => 'light'],
        'assign' => ['from' => [S::NEW, S::PENDING_REVIEW, S::REOPENED, S::ASSIGNED, S::IN_PROGRESS, S::WAITING_PARTS, S::WAITING_CONTRACTOR, S::WAITING_APPROVAL], 'to' => S::ASSIGNED, 'actor' => 'perm', 'perm' => 'tickets.assign', 'label' => 'มอบหมายช่าง', 'icon' => 'user-plus', 'tone' => 'primary'],
        'accept' => ['from' => [S::ASSIGNED], 'to' => S::ASSIGNED, 'actor' => 'assignee', 'label' => 'รับงาน', 'icon' => 'hand', 'tone' => 'soft-primary'],
        'start' => ['from' => [S::ASSIGNED, S::REOPENED], 'to' => S::IN_PROGRESS, 'actor' => 'assignee', 'label' => 'เริ่มดำเนินการ', 'icon' => 'play', 'tone' => 'accent'],
        'wait_parts' => ['from' => [S::IN_PROGRESS], 'to' => S::WAITING_PARTS, 'actor' => 'either', 'alt' => 'tickets.assign', 'label' => 'รออะไหล่', 'icon' => 'package-search', 'tone' => 'light'],
        'wait_contractor' => ['from' => [S::IN_PROGRESS], 'to' => S::WAITING_CONTRACTOR, 'actor' => 'either', 'alt' => 'tickets.assign', 'label' => 'รอผู้รับเหมา', 'icon' => 'hard-hat', 'tone' => 'light'],
        'request_approval' => ['from' => [S::IN_PROGRESS], 'to' => S::WAITING_APPROVAL, 'actor' => 'either', 'alt' => 'tickets.assign', 'label' => 'ขออนุมัติ', 'icon' => 'stamp', 'tone' => 'light'],
        'resume' => ['from' => [S::WAITING_PARTS, S::WAITING_CONTRACTOR], 'to' => S::IN_PROGRESS, 'actor' => 'either', 'alt' => 'tickets.assign', 'label' => 'ดำเนินการต่อ', 'icon' => 'play', 'tone' => 'accent'],
        'submit' => ['from' => [S::IN_PROGRESS], 'to' => S::PENDING_VERIFICATION, 'actor' => 'assignee', 'label' => 'แจ้งซ่อมเสร็จ', 'icon' => 'check-check', 'tone' => 'soft-success'],
        'verify' => ['from' => [S::PENDING_VERIFICATION], 'to' => S::COMPLETED, 'actor' => 'perm', 'perm' => 'tickets.verify', 'label' => 'ตรวจรับและปิดงาน', 'icon' => 'badge-check', 'tone' => 'soft-success'],
        'reject' => ['from' => [S::PENDING_VERIFICATION], 'to' => S::IN_PROGRESS, 'actor' => 'perm', 'perm' => 'tickets.verify', 'label' => 'ไม่ผ่านการตรวจรับ', 'icon' => 'undo-2', 'tone' => 'soft-danger'],
        'reopen' => ['from' => [S::COMPLETED], 'to' => S::REOPENED, 'actor' => 'perm', 'perm' => 'tickets.verify', 'label' => 'เปิดงานใหม่', 'icon' => 'rotate-ccw', 'tone' => 'light'],
        'cancel' => ['from' => [S::NEW, S::PENDING_REVIEW, S::ASSIGNED, S::IN_PROGRESS, S::WAITING_PARTS, S::WAITING_CONTRACTOR, S::WAITING_APPROVAL, S::PENDING_VERIFICATION, S::REOPENED], 'to' => S::CANCELLED, 'actor' => 'perm', 'perm' => 'tickets.cancel', 'label' => 'ยกเลิกงาน', 'icon' => 'x-circle', 'tone' => 'soft-danger'],
    ];

    /** Actions that require a note/reason */
    public const NOTE_REQUIRED = ['reject', 'reopen', 'cancel', 'submit', 'request_approval', 'wait_contractor'];

    public static function allowed(string $action, array $ticket, Permissions $gate, int $userId): bool
    {
        $def = self::ACTIONS[$action] ?? null;
        if (!$def || !in_array($ticket['status'], $def['from'], true)) {
            return false;
        }
        $hotel = (int) $ticket['hotel_id'];
        $isAssignee = $ticket['assigned_to'] !== null && (int) $ticket['assigned_to'] === $userId;

        // Business rules beyond source state
        if ($action === 'accept' && !empty($ticket['_assignment_accepted'])) {
            return false;
        }
        if ($action === 'start' && $ticket['assigned_to'] === null) {
            return false;
        }
        // Segregation of duties: the technician who did the work cannot verify it
        if (in_array($action, ['verify', 'reject'], true) && $isAssignee) {
            return false;
        }

        return match ($def['actor']) {
            'claim' => ($ticket['assigned_to'] === null || $isAssignee) && $gate->can('tickets.work', $hotel),
            'perm' => $gate->can($def['perm'], $hotel),
            'assignee' => $isAssignee && $gate->can('tickets.work', $hotel),
            'either' => ($isAssignee && $gate->can('tickets.work', $hotel)) || $gate->can($def['alt'], $hotel),
            default => false,
        };
    }

    public static function assertAllowed(string $action, array $ticket, Permissions $gate, int $userId): void
    {
        if (!isset(self::ACTIONS[$action])) {
            throw new BusinessRuleException('ไม่รู้จักการดำเนินการนี้');
        }
        if (!in_array($ticket['status'], self::ACTIONS[$action]['from'], true)) {
            throw new BusinessRuleException('ไม่สามารถ "' . self::ACTIONS[$action]['label'] . '" ได้ในสถานะปัจจุบัน (' . S::label((string) $ticket['status']) . ')');
        }
        if (!self::allowed($action, $ticket, $gate, $userId)) {
            throw new ForbiddenException('คุณไม่มีสิทธิ์ดำเนินการ "' . self::ACTIONS[$action]['label'] . '" สำหรับงานนี้');
        }
    }

    /** @return list<string> */
    public static function availableActions(array $ticket, Permissions $gate, int $userId): array
    {
        // Legacy assignment actions remain for existing integrations and history, not the daily UI.
        return array_values(array_filter(array_keys(self::ACTIONS), static fn ($a) => !in_array($a, ['assign', 'review', 'accept'], true) && !($a === 'start' && self::allowed('claim', $ticket, $gate, $userId)) && self::allowed($a, $ticket, $gate, $userId)));
    }

    /** Resulting status (assign keeps active work states) */
    public static function target(string $action, string $from): string
    {
        if ($action === 'assign' && in_array($from, [S::IN_PROGRESS, S::WAITING_PARTS, S::WAITING_CONTRACTOR, S::WAITING_APPROVAL], true)) {
            return $from; // re-assignment during work keeps current state
        }
        return self::ACTIONS[$action]['to'];
    }
}

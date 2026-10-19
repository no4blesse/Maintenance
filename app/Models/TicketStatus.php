<?php

declare(strict_types=1);

namespace App\Models;

/** Work order status catalogue. Transitions live in App\Services\TicketWorkflow. */
final class TicketStatus
{
    public const NEW = 'NEW';
    public const PENDING_REVIEW = 'PENDING_REVIEW';
    public const ASSIGNED = 'ASSIGNED';
    public const IN_PROGRESS = 'IN_PROGRESS';
    public const WAITING_PARTS = 'WAITING_PARTS';
    public const WAITING_CONTRACTOR = 'WAITING_CONTRACTOR';
    public const WAITING_APPROVAL = 'WAITING_APPROVAL';
    public const PENDING_VERIFICATION = 'PENDING_VERIFICATION';
    public const COMPLETED = 'COMPLETED';
    public const CANCELLED = 'CANCELLED';
    public const REOPENED = 'REOPENED';

    public const LABELS = [
        self::NEW => 'แจ้งใหม่',
        self::PENDING_REVIEW => 'รอตรวจสอบ',
        self::ASSIGNED => 'มอบหมายแล้ว',
        self::IN_PROGRESS => 'กำลังดำเนินการ',
        self::WAITING_PARTS => 'รออะไหล่',
        self::WAITING_CONTRACTOR => 'รอผู้รับเหมา',
        self::WAITING_APPROVAL => 'รออนุมัติ',
        self::PENDING_VERIFICATION => 'รอตรวจรับงาน',
        self::COMPLETED => 'เสร็จสมบูรณ์',
        self::CANCELLED => 'ยกเลิกแล้ว',
        self::REOPENED => 'เปิดงานใหม่',
    ];

    /** Terminal states */
    public const CLOSED = [self::COMPLETED, self::CANCELLED];
    public const WAITING = [self::WAITING_PARTS, self::WAITING_CONTRACTOR, self::WAITING_APPROVAL];
    /** "Pending" bucket on dashboards = acknowledged but not actively being worked */
    public const PENDING_BUCKET = [self::PENDING_REVIEW, self::ASSIGNED, self::REOPENED, self::WAITING_PARTS, self::WAITING_CONTRACTOR, self::WAITING_APPROVAL, self::PENDING_VERIFICATION];
    /** States in which technicians may log work / consume parts */
    public const WORKABLE = [self::ASSIGNED, self::IN_PROGRESS, self::WAITING_PARTS, self::WAITING_CONTRACTOR, self::WAITING_APPROVAL, self::PENDING_VERIFICATION, self::REOPENED];

    public static function label(string $status): string
    {
        return __(self::LABELS[$status] ?? $status);
    }

    public static function isOpen(string $status): bool
    {
        return !in_array($status, self::CLOSED, true);
    }

    /** @return list<string> */
    public static function open(): array
    {
        return array_values(array_diff(array_keys(self::LABELS), self::CLOSED));
    }

    public static function chartColor(string $status): string
    {
        return match ($status) {
            self::NEW => '#2563EB',
            self::PENDING_REVIEW => '#64748B',
            self::ASSIGNED => '#0891B2',
            self::IN_PROGRESS => '#F59E0B',
            self::WAITING_PARTS, self::WAITING_CONTRACTOR, self::WAITING_APPROVAL => '#8B5CF6',
            self::PENDING_VERIFICATION => '#14B8A6',
            self::COMPLETED => '#22C55E',
            self::CANCELLED => '#9CA3AF',
            self::REOPENED => '#EA580C',
            default => '#94A3B8',
        };
    }
}

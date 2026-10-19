<?php

declare(strict_types=1);

namespace App\Helpers;

use App\Core\HotelContext;
use App\Helpers\Time;
use App\Models\TicketStatus;

/** Small reusable HTML fragments. All dynamic values are escaped. */
final class UI
{
    public static function status(string $status, bool $overdue = false): string
    {
        $html = '<span class="badge-status st-' . e($status) . '">' . e(TicketStatus::label($status)) . '</span>';
        if ($overdue) {
            $html .= ' <span class="badge-status st-OVERDUE">' . e(__('เกินกำหนด')) . '</span>';
        }
        return $html;
    }

    public static function isOverdue(array $t): bool
    {
        return TicketStatus::isOpen((string) $t['status'])
            && !empty($t['sla_resolution_due'])
            && $t['sla_resolution_due'] < Time::now();
    }

    /** $level 1..4, $label text, $color hex */
    public static function priority(?string $label, int $level, string $color): string
    {
        $bars = '';
        for ($i = 1; $i <= 4; $i++) {
            $bars .= '<b class="' . ($i <= $level ? 'on' : '') . '"></b>';
        }
        return '<span class="prio" style="color:' . e($color) . '"><i class="bar">' . $bars . '</i><span>' . e($label) . '</span></span>';
    }

    public static function hotel(?int $hotelId): string
    {
        $h = HotelContext::hotel($hotelId);
        if (!$h) {
            return '';
        }
        $c = e($h['accent_color']);
        return '<span class="hotel-chip" style="color:' . $c . ';background:color-mix(in srgb,' . $c . ' 12%, white)" title="' . e($h['name_en']) . '">' . e($h['code']) . '</span>';
    }

    public static function initials(?string $name): string
    {
        $name = trim((string) $name);
        if ($name === '') {
            return '?';
        }
        $parts = preg_split('/\s+/u', $name) ?: [$name];
        $s = mb_substr($parts[0], 0, 1) . (isset($parts[1]) ? mb_substr($parts[1], 0, 1) : '');
        return mb_strtoupper($s);
    }

    public static function soft(string $text, string $tone = 'gray'): string
    {
        return '<span class="badge-soft soft-' . e($tone) . '">' . e($text) . '</span>';
    }

    public static function empty(string $title, string $text = '', string $icon = 'inbox', string $actionHtml = ''): string
    {
        return '<div class="empty-state"><div class="empty-icon">' . icon($icon) . '</div><h4>' . e($title) . '</h4>'
            . ($text !== '' ? '<p class="mb-3">' . e($text) . '</p>' : '') . $actionHtml . '</div>';
    }

    /** Bootstrap pagination preserving current query string */
    public static function pagination(int $total, int $page, int $perPage, array $query = []): string
    {
        $pages = (int) ceil($total / max(1, $perPage));
        $from = $total ? ($page - 1) * $perPage + 1 : 0;
        $to = min($total, $page * $perPage);
        $info = '<div class="text-muted small">แสดง ' . num($from) . '–' . num($to) . ' จาก ' . num($total) . ' รายการ</div>';
        if ($pages <= 1) {
            return '<div class="d-flex justify-content-between align-items-center flex-wrap gap-2">' . $info . '</div>';
        }
        unset($query['page']);
        $link = static fn (int $p) => '?' . http_build_query($query + ['page' => $p]);
        $html = '<nav aria-label="pagination"><ul class="pagination pagination-sm">';
        $html .= '<li class="page-item' . ($page <= 1 ? ' disabled' : '') . '"><a class="page-link" href="' . e($link(max(1, $page - 1))) . '">‹</a></li>';
        $start = max(1, $page - 2);
        $end = min($pages, $page + 2);
        if ($start > 1) {
            $html .= '<li class="page-item"><a class="page-link" href="' . e($link(1)) . '">1</a></li>' . ($start > 2 ? '<li class="page-item disabled"><span class="page-link">…</span></li>' : '');
        }
        for ($p = $start; $p <= $end; $p++) {
            $html .= '<li class="page-item' . ($p === $page ? ' active' : '') . '"><a class="page-link" href="' . e($link($p)) . '">' . $p . '</a></li>';
        }
        if ($end < $pages) {
            $html .= ($end < $pages - 1 ? '<li class="page-item disabled"><span class="page-link">…</span></li>' : '') . '<li class="page-item"><a class="page-link" href="' . e($link($pages)) . '">' . $pages . '</a></li>';
        }
        $html .= '<li class="page-item' . ($page >= $pages ? ' disabled' : '') . '"><a class="page-link" href="' . e($link(min($pages, $page + 1))) . '">›</a></li>';
        $html .= '</ul></nav>';
        return '<div class="d-flex justify-content-between align-items-center flex-wrap gap-2">' . $info . $html . '</div>';
    }

    /** JSON for <script type="application/json"> blocks (safe against </script>) */
    public static function json(mixed $data): string
    {
        return (string) json_encode($data, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
    }
}

<?php

declare(strict_types=1);

namespace App\Services\Line;

/**
 * Builds LINE message objects (text + Flex) for notification events.
 * URL buttons are only included for https URLs (LINE requirement).
 */
final class MessageBuilder
{
    public const EVENTS = [
        'TICKET_CREATED' => ['🔧', 'แจ้งซ่อมรายการใหม่'],
        'TICKET_EMERGENCY' => ['🚨', 'แจ้งซ่อมฉุกเฉิน'],
        'TICKET_ASSIGNED' => ['👷', 'มอบหมายงานซ่อม'],
        'TICKET_OVERDUE' => ['⏰', 'งานซ่อมเกินกำหนด SLA'],
        'TICKET_COMPLETED' => ['✅', 'ปิดงานซ่อมเรียบร้อย'],
        'EXPENSE_APPROVAL' => ['💰', 'รออนุมัติค่าใช้จ่าย'],
        'STOCK_LOW' => ['📦', 'อะไหล่ต่ำกว่าจุดสั่งซื้อ'],
        'PM_UPCOMING' => ['🗓️', 'งาน PM ที่ใกล้ถึงกำหนด'],
        'PM_OVERDUE' => ['⚠️', 'งาน PM เกินกำหนด'],
        'DAILY_SUMMARY' => ['📋', 'สรุปงานค้างประจำวัน'],
        'TEST' => ['🧪', 'ทดสอบการแจ้งเตือน'],
    ];

    /**
     * @param array{hotel_name?:string, accent?:string, lines?:list<array{0:string,1:string}>, url?:?string, footer?:string} $data
     * @return list<array<string, mixed>>
     */
    public static function build(string $event, array $data): array
    {
        [$emoji, $heading] = self::EVENTS[$event] ?? ['🔔', $event];
        $lines = $data['lines'] ?? [];
        $hotel = (string) ($data['hotel_name'] ?? '');
        $url = $data['url'] ?? null;

        $text = $emoji . ' ' . $heading . "\n\n";
        if ($hotel !== '') {
            $text .= '🏨 ' . $hotel . "\n";
        }
        foreach ($lines as [$label, $value]) {
            $text .= $label . ' ' . $value . "\n";
        }
        if ($url) {
            $text .= "\n🔗 ดูรายละเอียด:\n" . $url;
        }
        $text = mb_substr(rtrim($text), 0, 4900);

        $accent = preg_match('/^#[0-9A-Fa-f]{6}$/', (string) ($data['accent'] ?? '')) ? $data['accent'] : '#2563EB';
        $rows = [];
        foreach ($lines as [$label, $value]) {
            $rows[] = [
                'type' => 'box', 'layout' => 'baseline', 'spacing' => 'sm',
                'contents' => [
                    ['type' => 'text', 'text' => mb_substr($label, 0, 40) ?: ' ', 'size' => 'sm', 'color' => '#64748B', 'flex' => 2, 'wrap' => true],
                    ['type' => 'text', 'text' => mb_substr((string) $value, 0, 300) ?: '-', 'size' => 'sm', 'color' => '#111827', 'flex' => 5, 'wrap' => true],
                ],
            ];
        }
        $bubble = [
            'type' => 'bubble',
            'header' => [
                'type' => 'box', 'layout' => 'vertical', 'backgroundColor' => $accent, 'paddingAll' => '16px',
                'contents' => [
                    ['type' => 'text', 'text' => $emoji . ' ' . $heading, 'color' => '#FFFFFF', 'weight' => 'bold', 'size' => 'md', 'wrap' => true],
                    ['type' => 'text', 'text' => $hotel ?: 'SUNGROUP Maintenance', 'color' => '#FFFFFFCC', 'size' => 'xs', 'wrap' => true],
                ],
            ],
            'body' => ['type' => 'box', 'layout' => 'vertical', 'spacing' => 'md', 'contents' => $rows ?: [['type' => 'text', 'text' => '-']]],
        ];
        if ($url && str_starts_with($url, 'https://')) {
            $bubble['footer'] = [
                'type' => 'box', 'layout' => 'vertical',
                'contents' => [[
                    'type' => 'button', 'style' => 'primary', 'color' => $accent, 'height' => 'sm',
                    'action' => ['type' => 'uri', 'label' => 'ดูรายละเอียด', 'uri' => $url],
                ]],
            ];
        }
        // Flex message; altText (shown in chat list / old clients) carries the text version
        return [[
            'type' => 'flex',
            'altText' => mb_substr($text, 0, 390),
            'contents' => $bubble,
        ]];
    }

    /** Plain text rendering (used for logs / UI preview) */
    public static function plainText(array $messages): string
    {
        $m = $messages[0] ?? [];
        return (string) ($m['type'] === 'text' ? $m['text'] : ($m['altText'] ?? ''));
    }
}

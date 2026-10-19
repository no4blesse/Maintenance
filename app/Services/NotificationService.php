<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Config;
use App\Core\Database;
use App\Core\HotelContext;
use App\Core\Logger;
use App\Helpers\Time;
use App\Services\Line\LineTransport;
use App\Services\Line\MessageBuilder;
use App\Services\Line\MockTransport;
use App\Services\Line\Transport;

/**
 * Durable notification queue.
 *   event -> queue() rows (one per BOUND destination, deduplicated)
 *         -> cron/process_notifications.php -> process() -> Transport -> logs
 */
final class NotificationService
{
    private static ?Transport $transportOverride = null;

    public static function useTransport(?Transport $t): void
    {
        self::$transportOverride = $t;
    }

    public static function isConnected(): bool
    {
        return Config::get('line.transport') === 'line'
            && Config::get('line.channel_access_token') !== ''
            && Config::get('line.channel_secret') !== '';
    }

    public static function transport(): Transport
    {
        if (self::$transportOverride) {
            return self::$transportOverride;
        }
        return self::isConnected()
            ? new LineTransport((string) Config::get('line.channel_access_token'))
            : new MockTransport();
    }

    public static function isEnabled(string $event, ?int $hotelId): bool
    {
        if ($event === 'TEST') {
            return true;
        }
        $row = Database::first(
            'SELECT is_enabled FROM notification_rules WHERE event_type = ? AND hotel_key IN (0, ?) ORDER BY hotel_key DESC LIMIT 1',
            [$event, $hotelId ?? 0]
        );
        return $row === null ? true : (bool) $row['is_enabled'];
    }

    /**
     * Queue a notification for every bound LINE destination of the hotel.
     * $dedupeKey must uniquely identify the business event (e.g. "TICKET_CREATED:123").
     * @param array{lines?:list<array{0:string,1:string}>, url?:?string} $data
     * @return int rows queued
     */
    public static function queue(string $event, ?int $hotelId, array $data, string $dedupeKey, ?int $onlyDestinationId = null): int
    {
        if (!self::isEnabled($event, $hotelId)) {
            return 0;
        }
        $hotel = HotelContext::hotel($hotelId);
        $data['hotel_name'] ??= $hotel['name_en'] ?? '';
        $data['accent'] ??= $hotel['accent_color'] ?? '#2563EB';
        $messages = MessageBuilder::build($event, $data);

        if ($onlyDestinationId !== null) {
            $dests = Database::select("SELECT id, group_id FROM line_destinations WHERE id = ? AND hotel_id = ? AND status = 'BOUND'", [$onlyDestinationId, $hotelId]);
        } else {
            $dests = Database::select("SELECT id, group_id FROM line_destinations WHERE hotel_id = ? AND status = 'BOUND'", [$hotelId]);
        }
        $now = Time::now();
        $n = 0;
        if (!$dests) {
            // Visible in delivery history: event happened but no group is mapped
            $n += Database::execute(
                "INSERT IGNORE INTO notification_queue (hotel_id, event_type, destination_id, dedupe_key, payload, status, next_attempt_at, last_error, created_at)
                 VALUES (?, ?, NULL, ?, ?, 'SKIPPED', ?, 'ยังไม่ได้ผูกกลุ่ม LINE กับโรงแรมนี้', ?)",
                [$hotelId, $event, mb_substr($dedupeKey . ':none', 0, 191), json_encode(['messages' => $messages], JSON_UNESCAPED_UNICODE), $now, $now]
            );
            return 0;
        }
        foreach ($dests as $d) {
            $n += Database::execute(
                "INSERT IGNORE INTO notification_queue (hotel_id, event_type, destination_id, dedupe_key, payload, status, next_attempt_at, created_at)
                 VALUES (?, ?, ?, ?, ?, 'PENDING', ?, ?)",
                [$hotelId, $event, (int) $d['id'], mb_substr($dedupeKey . ':' . $d['id'], 0, 191), json_encode(['messages' => $messages], JSON_UNESCAPED_UNICODE), $now, $now]
            );
        }
        return $n;
    }

    /** Process due queue rows. Safe to run concurrently (atomic claim). */
    public static function process(int $limit = 50, ?int $onlyId = null): array
    {
        $now = Time::now();
        // Recover rows stuck in PROCESSING (worker crashed)
        Database::execute(
            "UPDATE notification_queue SET status = 'FAILED', locked_at = NULL, last_error = 'worker timeout'
             WHERE status = 'PROCESSING' AND locked_at < ?" . ($onlyId ? ' AND id = ?' : ''),
            [gmdate('Y-m-d H:i:s', strtotime($now . ' UTC') - 600), ...($onlyId ? [$onlyId] : [])]
        );
        $sql = "SELECT q.id FROM notification_queue q
                WHERE q.status IN ('PENDING','FAILED') AND q.next_attempt_at <= ? AND q.attempts < q.max_attempts"
            . ($onlyId ? ' AND q.id = ' . (int) $onlyId : '') . ' ORDER BY q.id LIMIT ' . max(1, $limit);
        $ids = Database::column($sql, [$now]);
        $stats = ['sent' => 0, 'failed' => 0, 'dead' => 0];
        $transport = self::transport();
        foreach ($ids as $id) {
            $claimed = Database::execute(
                "UPDATE notification_queue SET status = 'PROCESSING', locked_at = ?, attempts = attempts + 1
                 WHERE id = ? AND status IN ('PENDING','FAILED')",
                [$now, $id]
            );
            if ($claimed !== 1) {
                continue; // another worker took it
            }
            $row = Database::first(
                'SELECT q.*, d.group_id, d.hotel_id AS dest_hotel_id, d.status AS dest_status FROM notification_queue q
                 LEFT JOIN line_destinations d ON d.id = q.destination_id WHERE q.id = ?',
                [$id]
            );
            if (!$row || !$row['group_id'] || $row['dest_status'] !== 'BOUND' || (int) $row['dest_hotel_id'] !== (int) $row['hotel_id']) {
                Database::execute("UPDATE notification_queue SET status = 'SKIPPED', locked_at = NULL, last_error = 'destination not bound' WHERE id = ?", [$id]);
                continue;
            }
            $payload = json_decode((string) $row['payload'], true) ?: [];
            $retryKey = self::uuidFromId((int) $id);
            try {
                $res = $transport->push((string) $row['group_id'], $payload['messages'] ?? [], $retryKey);
            } catch (\Throwable $e) {
                Logger::error('LINE push exception', ['id' => $id, 'msg' => $e->getMessage()]);
                $res = new Line\DeliveryResult(false, null, 'Exception: ' . $e->getMessage(), true, $transport->name());
            }
            Database::insert('notification_logs', [
                'queue_id' => $id,
                'attempt_no' => (int) $row['attempts'],
                'transport' => $res->transport,
                'http_status' => $res->httpStatus,
                'success' => $res->success ? 1 : 0,
                'response_excerpt' => mb_substr($res->excerpt, 0, 1000),
                'created_at' => Time::now(),
            ]);
            if ($res->success) {
                Database::execute("UPDATE notification_queue SET status = 'SENT', sent_at = ?, locked_at = NULL, last_error = NULL WHERE id = ?", [Time::now(), $id]);
                $stats['sent']++;
            } elseif (!$res->retryable || (int) $row['attempts'] >= (int) $row['max_attempts']) {
                Database::execute("UPDATE notification_queue SET status = 'DEAD', locked_at = NULL, last_error = ? WHERE id = ?", [mb_substr($res->excerpt, 0, 1000), $id]);
                $stats['dead']++;
            } else {
                $backoff = min(3600, 60 * (2 ** max(0, (int) $row['attempts'] - 1)));
                Database::execute(
                    "UPDATE notification_queue SET status = 'FAILED', locked_at = NULL, last_error = ?, next_attempt_at = ? WHERE id = ?",
                    [mb_substr($res->excerpt, 0, 1000), gmdate('Y-m-d H:i:s', time() + $backoff), $id]
                );
                $stats['failed']++;
            }
        }
        return $stats;
    }

    /** Deterministic UUIDv4-shaped key per queue row so LINE de-duplicates retries */
    public static function uuidFromId(int $id): string
    {
        $h = hash('sha256', 'sgmc-line-' . $id . '-' . (string) Config::get('app.key'));
        return sprintf('%s-%s-4%s-a%s-%s', substr($h, 0, 8), substr($h, 8, 4), substr($h, 13, 3), substr($h, 17, 3), substr($h, 20, 12));
    }

    // ---------------- Domain helpers ----------------

    public static function ticketCreated(array $t): void
    {
        $data = self::ticketLines($t, $t['priority_code'] === 'EMERGENCY' ? 'TICKET_EMERGENCY' : 'TICKET_CREATED');
        self::queue('TICKET_CREATED', (int) $t['hotel_id'], $data, 'TICKET_CREATED:' . $t['id']);
        if (($t['priority_code'] ?? '') === 'EMERGENCY') {
            self::queue('TICKET_EMERGENCY', (int) $t['hotel_id'], $data, 'TICKET_EMERGENCY:' . $t['id']);
        }
    }

    public static function ticketLines(array $t, string $event = ''): array
    {
        $loc = trim(($t['location_name'] ?? '') . ' ' . (!empty($t['floor_name']) ? 'ชั้น ' . $t['floor_name'] : ''));
        return [
            'lines' => [
                ['🆔', (string) $t['ticket_no']],
                ['📍', $loc !== '' ? $loc : (string) ($t['location_text'] ?? '-')],
                ['⚠️', (string) $t['title']],
                ['🚨 ความเร่งด่วน:', (string) ($t['priority_name'] ?? '-')],
                ['👨‍🔧 ผู้รับผิดชอบ:', (string) ($t['assignee_name'] ?? 'รอมอบหมาย')],
                ['📋 สถานะ:', \App\Models\TicketStatus::label((string) $t['status'])],
            ],
            'url' => absolute_url('tickets/' . $t['id']),
        ];
    }
}

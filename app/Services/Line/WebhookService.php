<?php

declare(strict_types=1);

namespace App\Services\Line;

use App\Core\Database;
use App\Helpers\Time;
use App\Services\Audit;

/**
 * LINE webhook processing.
 *  - Signature: base64(HMAC-SHA256(raw body, channel secret)) == x-line-signature
 *  - Group discovery only; message text is never stored.
 *  - Duplicate deliveries are ignored via webhookEventId.
 */
final class WebhookService
{
    public static function verifySignature(string $rawBody, ?string $signature, string $secret): bool
    {
        if ($secret === '' || $signature === null || $signature === '') {
            return false;
        }
        $expected = base64_encode(hash_hmac('sha256', $rawBody, $secret, true));
        return hash_equals($expected, $signature);
    }

    /** @return array{processed:int, duplicates:int} */
    public static function handle(array $payload): array
    {
        return Database::transaction(static function () use ($payload): array {
        $processed = 0;
        $dups = 0;
        foreach (($payload['events'] ?? []) as $ev) {
            if (!is_array($ev)) {
                continue;
            }
            $eventId = (string) ($ev['webhookEventId'] ?? '');
            $type = mb_substr((string) ($ev['type'] ?? 'unknown'), 0, 30);
            $sourceType = (string) ($ev['source']['type'] ?? '');
            $groupId = $sourceType === 'group' ? (string) ($ev['source']['groupId'] ?? '') : '';
            if (!preg_match('/^[CR][0-9a-f]{32}$/', $groupId)) {
                $groupId = $sourceType === 'room' ? '' : ($groupId !== '' && preg_match('/^[A-Za-z0-9]{10,64}$/', $groupId) ? $groupId : '');
            }
            if ($eventId !== '') {
                $inserted = Database::execute(
                    'INSERT IGNORE INTO line_webhook_events (webhook_event_id, event_type, source_type, group_id, received_at) VALUES (?, ?, ?, ?, ?)',
                    [mb_substr($eventId, 0, 64), $type, mb_substr($sourceType, 0, 20), $groupId ?: null, Time::now()]
                );
                if ($inserted === 0) {
                    $dups++;
                    continue;
                }
            }
            if ($groupId === '') {
                continue;
            }
            $now = Time::now();
            if ($type === 'leave') {
                Database::execute("UPDATE line_destinations SET status = 'LEFT', last_event_at = ? WHERE group_id = ?", [$now, $groupId]);
            } else {
                $existing = Database::first('SELECT id, status FROM line_destinations WHERE group_id = ?', [$groupId]);
                if (!$existing) {
                    $id = Database::insert('line_destinations', [
                        'group_id' => $groupId, 'status' => 'DISCOVERED', 'discovered_at' => $now, 'last_event_at' => $now,
                    ]);
                    Audit::log('line.group_discovered', 'line_destination', $id, null, null, ['event' => $type]);
                } else {
                    $status = $existing['status'] === 'LEFT' ? 'DISCOVERED' : $existing['status'];
                    Database::execute('UPDATE line_destinations SET last_event_at = ?, status = ? WHERE id = ?', [$now, $status, $existing['id']]);
                }
            }
            $processed++;
        }
        return ['processed' => $processed, 'duplicates' => $dups];
        });
    }
}

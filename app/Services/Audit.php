<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Auth;
use App\Core\Database;
use App\Helpers\Time;

/** Append-only audit trail for critical operations. */
final class Audit
{
    public static function log(string $action, string $entityType, ?int $entityId, ?int $hotelId = null, ?array $old = null, ?array $new = null): void
    {
        foreach (['password', 'password_hash', 'token', 'channel_secret', 'channel_access_token'] as $k) {
            if (isset($old[$k])) {
                $old[$k] = '***';
            }
            if (isset($new[$k])) {
                $new[$k] = '***';
            }
        }
        Database::insert('audit_logs', [
            'user_id' => Auth::id(),
            'hotel_id' => $hotelId,
            'action' => $action,
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'old_values' => $old !== null ? json_encode($old, JSON_UNESCAPED_UNICODE) : null,
            'new_values' => $new !== null ? json_encode($new, JSON_UNESCAPED_UNICODE) : null,
            'ip' => PHP_SAPI === 'cli' ? 'cli' : ($_SERVER['REMOTE_ADDR'] ?? null),
            'user_agent' => PHP_SAPI === 'cli' ? 'cli' : mb_substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255),
            'created_at' => Time::now(),
        ]);
    }
}

<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Helpers\Time;

/** In-app (bell) notifications for individual users. */
final class InAppNotifications
{
    public static function unreadCount(int $userId): int
    {
        try {
            return (int) Database::value('SELECT COUNT(*) FROM user_notifications WHERE user_id = ? AND read_at IS NULL', [$userId]);
        } catch (\Throwable) {
            return 0;
        }
    }

    /** @param list<int> $userIds */
    public static function send(array $userIds, ?int $hotelId, string $type, string $title, ?string $body, ?string $url): void
    {
        foreach (array_unique(array_filter($userIds)) as $uid) {
            Database::insert('user_notifications', [
                'user_id' => (int) $uid,
                'hotel_id' => $hotelId,
                'type' => $type,
                'title' => mb_substr($title, 0, 200),
                'body' => $body !== null ? mb_substr($body, 0, 500) : null,
                'url' => $url,
                'created_at' => Time::now(),
            ]);
        }
    }

    /**
     * Active users holding $permission at $hotelId (directly or globally).
     * @return list<int>
     */
    public static function usersWithPermission(string $permission, int $hotelId, bool $excludeGlobal = true): array
    {
        $sql = 'SELECT DISTINCT u.id FROM users u
                JOIN user_hotel_roles uhr ON uhr.user_id = u.id
                JOIN role_permissions rp ON rp.role_id = uhr.role_id
                JOIN permissions p ON p.id = rp.permission_id
                WHERE u.is_active = 1 AND u.deleted_at IS NULL AND p.code = ? AND '
            . ($excludeGlobal ? 'uhr.hotel_id = ?' : '(uhr.hotel_id = ? OR uhr.hotel_id IS NULL)');
        return array_map('intval', Database::column($sql, [$permission, $hotelId]));
    }
}

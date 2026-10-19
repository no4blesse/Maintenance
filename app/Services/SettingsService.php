<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use Throwable;

/** DB-backed settings with in-request cache and typed values. */
final class SettingsService
{
    /** @var array<string, mixed>|null */
    private static ?array $cache = null;

    public static function all(): array
    {
        if (self::$cache === null) {
            self::$cache = [];
            try {
                foreach (Database::select('SELECT setting_key, setting_value, value_type FROM settings') as $r) {
                    self::$cache[$r['setting_key']] = self::cast($r['setting_value'], $r['value_type']);
                }
            } catch (Throwable) {
                // DB not migrated yet: fall back to defaults
            }
        }
        return self::$cache;
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        return self::all()[$key] ?? $default;
    }

    public static function set(string $key, mixed $value, ?int $userId = null): void
    {
        $type = Database::value('SELECT value_type FROM settings WHERE setting_key = ?', [$key]) ?? 'string';
        $stored = is_bool($value) ? ($value ? '1' : '0') : (string) $value;
        Database::execute(
            'INSERT INTO settings (setting_key, setting_value, value_type, updated_by) VALUES (?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), updated_by = VALUES(updated_by)',
            [$key, $stored, $type, $userId]
        );
        self::$cache = null;
    }

    public static function flush(): void
    {
        self::$cache = null;
    }

    private static function cast(?string $v, string $type): mixed
    {
        return match ($type) {
            'int' => (int) $v,
            'bool' => $v === '1' || $v === 'true',
            'decimal' => (string) $v,
            default => $v,
        };
    }
}

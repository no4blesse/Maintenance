<?php

declare(strict_types=1);

namespace App\Core;

use App\Core\Exceptions\ForbiddenException;

/**
 * Central authorization layer (permission-based, hotel-scoped).
 * A user holds roles per hotel (or globally when hotel_id IS NULL).
 * Permissions are resolved from roles; never check role names for access.
 */
final class Permissions
{
    /** @var array<int, array<string, true>> hotelKey(0=global) => perms */
    private array $perms = [];
    /** @var array<int, list<string>> hotelKey => role codes */
    private array $roles = [];
    /** @var list<int>|null */
    private static ?array $activeHotelIds = null;

    public function __construct(public readonly int $userId)
    {
        $rows = Database::select(
            'SELECT IFNULL(uhr.hotel_id, 0) AS hk, r.code AS role, p.code AS perm
             FROM user_hotel_roles uhr
             JOIN roles r ON r.id = uhr.role_id
             LEFT JOIN role_permissions rp ON rp.role_id = r.id
             LEFT JOIN permissions p ON p.id = rp.permission_id
             WHERE uhr.user_id = ?',
            [$userId]
        );
        foreach ($rows as $row) {
            $hk = (int) $row['hk'];
            $this->roles[$hk] ??= [];
            if (!in_array($row['role'], $this->roles[$hk], true)) {
                $this->roles[$hk][] = $row['role'];
            }
            if ($row['perm'] !== null) {
                $this->perms[$hk][$row['perm']] = true;
            }
        }
    }

    public static function resetCache(): void
    {
        self::$activeHotelIds = null;
    }

    /** @return list<int> */
    public static function activeHotelIds(): array
    {
        if (self::$activeHotelIds === null) {
            self::$activeHotelIds = array_map('intval', Database::column('SELECT id FROM hotels WHERE is_active = 1 ORDER BY sort_order, id'));
        }
        return self::$activeHotelIds;
    }

    /** If $hotelId is null: does the user hold this permission anywhere? */
    public function can(string $permission, ?int $hotelId = null): bool
    {
        if (isset($this->perms[0][$permission])) {
            return $hotelId === null || in_array($hotelId, self::activeHotelIds(), true);
        }
        if ($hotelId === null) {
            foreach ($this->perms as $hk => $set) {
                if ($hk !== 0 && isset($set[$permission]) && in_array($hk, self::activeHotelIds(), true)) {
                    return true;
                }
            }
            return false;
        }
        return isset($this->perms[$hotelId][$permission]) && in_array($hotelId, self::activeHotelIds(), true);
    }

    public function authorize(string $permission, ?int $hotelId = null): void
    {
        if (!$this->can($permission, $hotelId)) {
            throw new ForbiddenException();
        }
    }

    /** @return list<int> active hotel ids where the permission is granted */
    public function hotelsWith(string $permission): array
    {
        if (isset($this->perms[0][$permission])) {
            return self::activeHotelIds();
        }
        $ids = [];
        foreach ($this->perms as $hk => $set) {
            if ($hk !== 0 && isset($set[$permission])) {
                $ids[] = $hk;
            }
        }
        return array_values(array_intersect(self::activeHotelIds(), $ids));
    }

    public function hasGlobal(string $permission): bool
    {
        return isset($this->perms[0][$permission]);
    }

    /** @return list<int> hotels where user has any role */
    public function accessibleHotelIds(): array
    {
        if (isset($this->roles[0])) {
            return self::activeHotelIds();
        }
        return array_values(array_intersect(self::activeHotelIds(), array_keys($this->roles)));
    }

    /** @return list<string> role codes at a hotel (including global roles) */
    public function rolesAt(?int $hotelId): array
    {
        $r = $this->roles[0] ?? [];
        if ($hotelId !== null) {
            $r = [...$r, ...($this->roles[$hotelId] ?? [])];
        }
        return array_values(array_unique($r));
    }

    /** @return array<int, list<string>> */
    public function allRoles(): array
    {
        return $this->roles;
    }
}

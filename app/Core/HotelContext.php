<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Current hotel workspace selection ("ทุกโรงแรม" or one hotel).
 * The selection only NARROWS what a user may see; it never grants access.
 * Every query must use scope($permission), which intersects the selection
 * with the hotels where the user actually holds the permission.
 */
final class HotelContext
{
    /** @var array<int, array<string, mixed>>|null */
    private static ?array $hotels = null;

    public static function reset(): void
    {
        self::$hotels = null;
        Permissions::resetCache();
    }

    /** @return array<int, array<string, mixed>> all hotels keyed by id */
    public static function all(): array
    {
        if (self::$hotels === null) {
            self::$hotels = [];
            foreach (Database::select('SELECT * FROM hotels ORDER BY sort_order, id') as $h) {
                self::$hotels[(int) $h['id']] = $h;
            }
        }
        return self::$hotels;
    }

    public static function hotel(?int $id): ?array
    {
        return $id === null ? null : (self::all()[$id] ?? null);
    }

    /** @return list<array<string, mixed>> hotels the current user may access */
    public static function accessibleHotels(): array
    {
        if (!Auth::check()) {
            return [];
        }
        $ids = Auth::gate()->accessibleHotelIds();
        return array_values(array_filter(self::all(), static fn ($h) => in_array((int) $h['id'], $ids, true)));
    }

    public static function selectedId(): ?int
    {
        if (!Auth::check()) {
            return null;
        }
        $accessible = Auth::gate()->accessibleHotelIds();
        if (count($accessible) === 1) {
            return $accessible[0];
        }
        $sel = Session::get('hotel_scope');
        if ($sel === null || $sel === 'all') {
            return null;
        }
        return in_array((int) $sel, $accessible, true) ? (int) $sel : null;
    }

    public static function selectedHotel(): ?array
    {
        return self::hotel(self::selectedId());
    }

    public static function select(?int $hotelId): bool
    {
        if ($hotelId === null) {
            Session::set('hotel_scope', 'all');
            return true;
        }
        if (!in_array($hotelId, Auth::gate()->accessibleHotelIds(), true)) {
            return false;
        }
        Session::set('hotel_scope', $hotelId);
        return true;
    }

    /**
     * Hotel ids to use in WHERE hotel_id IN (...) for a permission.
     * Returns [] (deny) when nothing qualifies.
     * @return list<int>
     */
    public static function scope(string $permission): array
    {
        if (!Auth::check()) {
            return [];
        }
        $allowed = Auth::gate()->hotelsWith($permission);
        $sel = self::selectedId();
        if ($sel !== null) {
            return in_array($sel, $allowed, true) ? [$sel] : [];
        }
        return $allowed;
    }

    /** Hotels (rows) where the user holds a permission – for <select> options */
    public static function hotelsFor(string $permission): array
    {
        $ids = self::scope($permission);
        return array_values(array_filter(self::all(), static fn ($h) => in_array((int) $h['id'], $ids, true)));
    }
}

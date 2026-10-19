<?php

declare(strict_types=1);

namespace App\Helpers;

/**
 * Exact decimal arithmetic for money/quantities using bcmath strings.
 * Never use float for monetary values.
 */
final class Dec
{
    public static function norm(mixed $v, int $scale = 4): string
    {
        if ($v === null || $v === '') {
            return bcadd('0', '0', $scale);
        }
        $s = is_float($v) ? sprintf('%.6F', $v) : trim((string) $v);
        if (!preg_match('/^-?\d+(\.\d+)?$/', $s)) {
            throw new \InvalidArgumentException('Invalid decimal: ' . $s);
        }
        return bcadd($s, '0', $scale);
    }

    public static function add(mixed $a, mixed $b, int $scale = 4): string
    {
        return bcadd(self::norm($a, 8), self::norm($b, 8), $scale);
    }

    public static function sub(mixed $a, mixed $b, int $scale = 4): string
    {
        return bcsub(self::norm($a, 8), self::norm($b, 8), $scale);
    }

    public static function mul(mixed $a, mixed $b, int $scale = 4): string
    {
        return bcmul(self::norm($a, 8), self::norm($b, 8), $scale);
    }

    public static function div(mixed $a, mixed $b, int $scale = 4): string
    {
        if (self::cmp($b, '0') === 0) {
            return self::norm('0', $scale);
        }
        return bcdiv(self::norm($a, 8), self::norm($b, 8), $scale);
    }

    public static function cmp(mixed $a, mixed $b): int
    {
        return bccomp(self::norm($a, 8), self::norm($b, 8), 8);
    }

    /** Round half-up to $scale decimals */
    public static function round(mixed $v, int $scale = 2): string
    {
        $n = self::norm($v, 8);
        $offset = '0.' . str_repeat('0', $scale) . '5';
        return str_starts_with($n, '-') ? bcsub($n, $offset, $scale) : bcadd($n, $offset, $scale);
    }

    /** Money: multiply then round to 2 decimals */
    public static function money(mixed $qty, mixed $unit): string
    {
        return self::round(self::mul($qty, $unit, 8), 2);
    }
}

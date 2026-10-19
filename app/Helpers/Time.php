<?php

declare(strict_types=1);

namespace App\Helpers;

use DateTimeImmutable;
use DateTimeZone;

/**
 * Timezone policy:
 *  - DATETIME columns store UTC.
 *  - DATE columns (purchase_date, pm due_date, expense_date, budgets) are
 *    calendar dates in the hotel's local timezone (Asia/Bangkok).
 *  - User input from <input type=datetime-local> is local time -> toUtc().
 */
final class Time
{
    private static ?string $frozenNow = null;

    public static function tz(): DateTimeZone
    {
        return new DateTimeZone((string) (config('app.timezone') ?: 'Asia/Bangkok'));
    }

    /** For tests: freeze "now" (UTC 'Y-m-d H:i:s'). */
    public static function freeze(?string $utc): void
    {
        self::$frozenNow = $utc;
    }

    public static function now(): string
    {
        return self::$frozenNow ?? gmdate('Y-m-d H:i:s');
    }

    public static function nowImmutable(): DateTimeImmutable
    {
        return new DateTimeImmutable(self::now(), new DateTimeZone('UTC'));
    }

    /** Today's calendar date in operations timezone */
    public static function today(): string
    {
        return self::nowImmutable()->setTimezone(self::tz())->format('Y-m-d');
    }

    public static function toUtc(?string $local): ?string
    {
        if ($local === null || trim($local) === '') {
            return null;
        }
        $d = new DateTimeImmutable(str_replace('T', ' ', $local), self::tz());
        return $d->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    }

    public static function toLocal(?string $utc, string $format = 'Y-m-d H:i:s'): ?string
    {
        if ($utc === null || $utc === '') {
            return null;
        }
        return (new DateTimeImmutable($utc, new DateTimeZone('UTC')))->setTimezone(self::tz())->format($format);
    }

    /** Value suitable for <input type="datetime-local"> */
    public static function inputValue(?string $utc): string
    {
        return self::toLocal($utc, 'Y-m-d\TH:i') ?? '';
    }

    /** UTC range [start, end) covering local calendar dates $from..$to inclusive */
    public static function localDateRangeToUtc(string $from, string $to): array
    {
        $start = (new DateTimeImmutable($from . ' 00:00:00', self::tz()))->setTimezone(new DateTimeZone('UTC'));
        $end = (new DateTimeImmutable($to . ' 00:00:00', self::tz()))->modify('+1 day')->setTimezone(new DateTimeZone('UTC'));
        return [$start->format('Y-m-d H:i:s'), $end->format('Y-m-d H:i:s')];
    }

    public static function addMinutes(string $utc, int $minutes): string
    {
        return (new DateTimeImmutable($utc, new DateTimeZone('UTC')))->modify("+{$minutes} minutes")->format('Y-m-d H:i:s');
    }

    public static function diffSeconds(string $fromUtc, string $toUtc): int
    {
        return strtotime($toUtc . ' UTC') - strtotime($fromUtc . ' UTC');
    }

    public static function display(?string $utc, ?string $format = null): string
    {
        if (!$utc) {
            return '-';
        }
        $format ??= (string) setting('date_format', 'd/m/Y') . ' H:i';
        $local = (new DateTimeImmutable($utc, new DateTimeZone('UTC')))->setTimezone(self::tz());
        return self::applyEra($local, $format);
    }

    public static function displayDate(?string $date): string
    {
        if (!$date) {
            return '-';
        }
        $d = new DateTimeImmutable($date, self::tz());
        return self::applyEra($d, (string) setting('date_format', 'd/m/Y'));
    }

    private static function applyEra(DateTimeImmutable $d, string $format): string
    {
        if ((bool) setting('use_buddhist_year', false)) {
            $format = str_replace('Y', (string) ((int) $d->format('Y') + 543), $format);
        }
        return $d->format($format);
    }

    public static function ago(?string $utc): string
    {
        if (!$utc) {
            return '-';
        }
        $s = self::diffSeconds($utc, self::now());
        if ($s < 60) {
            return __('time.just_now');
        }
        if ($s < 3600) {
            return __('time.minutes_ago', ['n' => intdiv($s, 60)]);
        }
        if ($s < 86400) {
            return __('time.hours_ago', ['n' => intdiv($s, 3600)]);
        }
        if ($s < 86400 * 30) {
            return __('time.days_ago', ['n' => intdiv($s, 86400)]);
        }
        return self::display($utc);
    }

    /** Human duration from seconds, e.g. "2 ชม. 15 นาที" */
    public static function duration(?float $seconds): string
    {
        if ($seconds === null) {
            return '-';
        }
        $m = (int) round($seconds / 60);
        if ($m < 60) {
            return $m . ' ' . __('time.min');
        }
        $h = intdiv($m, 60);
        if ($h < 48) {
            return $h . ' ' . __('time.hr') . ($m % 60 ? ' ' . ($m % 60) . ' ' . __('time.min') : '');
        }
        return round($h / 24, 1) . ' ' . __('time.day');
    }
}

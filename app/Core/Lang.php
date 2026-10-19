<?php

declare(strict_types=1);

namespace App\Core;

/** i18n string lookup. Thai is default; English supported via lang/en.php. */
final class Lang
{
    private static string $locale = 'th';
    /** @var array<string, array<string, string>> */
    private static array $lines = [];

    public static function setLocale(string $locale): void
    {
        self::$locale = in_array($locale, ['th', 'en'], true) ? $locale : 'th';
    }

    public static function locale(): string
    {
        return self::$locale;
    }

    /** @param array<string, string|int> $replace */
    public static function get(string $key, array $replace = [], ?string $fallback = null): string
    {
        $line = self::lines(self::$locale)[$key] ?? self::lines('th')[$key] ?? $fallback ?? $key;
        foreach ($replace as $k => $v) {
            $line = str_replace(':' . $k, (string) $v, $line);
        }
        return $line;
    }

    /** @return array<string, string> */
    private static function lines(string $locale): array
    {
        if (!isset(self::$lines[$locale])) {
            $file = BASE_PATH . '/lang/' . $locale . '.php';
            self::$lines[$locale] = is_file($file) ? (require $file) : [];
        }
        return self::$lines[$locale];
    }
}

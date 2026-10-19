<?php

declare(strict_types=1);

namespace App\Core;

/** Daily-rotated file logger under storage/logs (outside public root). */
final class Logger
{
    public static function log(string $level, string $message, array $context = []): void
    {
        $dir = BASE_PATH . '/storage/logs';
        if (!is_dir($dir)) {
            @mkdir($dir, 0750, true);
        }
        // never log secrets
        foreach (['password', 'password_hash', 'token', 'secret', 'access_token', '_token'] as $k) {
            if (isset($context[$k])) {
                $context[$k] = '***';
            }
        }
        $line = sprintf(
            "[%s] %s: %s %s\n",
            gmdate('Y-m-d H:i:s'),
            strtoupper($level),
            str_replace(["\r", "\n"], ' ', $message),
            $context ? json_encode($context, JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR) : ''
        );
        @file_put_contents($dir . '/app-' . gmdate('Y-m-d') . '.log', $line, FILE_APPEND | LOCK_EX);
    }

    public static function error(string $m, array $c = []): void
    {
        self::log('error', $m, $c);
    }

    public static function warning(string $m, array $c = []): void
    {
        self::log('warning', $m, $c);
    }

    public static function info(string $m, array $c = []): void
    {
        self::log('info', $m, $c);
    }
}

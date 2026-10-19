<?php

declare(strict_types=1);

namespace App\Core;

/** Secure session wrapper with flash data and idle timeout. */
final class Session
{
    public static function start(Request $request): void
    {
        if (session_status() === PHP_SESSION_ACTIVE || PHP_SAPI === 'cli') {
            self::ageFlash();
            return;
        }
        $savePath = Config::get('session.save_path');
        if ($savePath) {
            if (!is_dir($savePath)) {
                @mkdir($savePath, 0700, true);
            }
            session_save_path($savePath);
        }
        ini_set('session.use_strict_mode', '1');
        ini_set('session.use_only_cookies', '1');
        ini_set('session.use_trans_sid', '0');
        ini_set('session.gc_maxlifetime', (string) (Config::get('session.lifetime_minutes', 120) * 60));
        session_name((string) Config::get('session.name', 'sgmc_session'));
        $secure = (bool) Config::get('session.secure_cookie', true) || $request->isSecure();
        session_set_cookie_params([
            'lifetime' => 0,
            'path' => (Request::detectBasePath() ?: '') . '/',
            'secure' => $secure,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        session_start();

        $idle = (int) Config::get('session.lifetime_minutes', 120) * 60;
        $last = $_SESSION['_last_activity'] ?? null;
        if ($last !== null && (time() - (int) $last) > $idle) {
            $_SESSION = [];
            session_regenerate_id(true);
            $_SESSION['_flash_next']['warning'] = 'หมดเวลาการใช้งาน กรุณาเข้าสู่ระบบอีกครั้ง';
        }
        $_SESSION['_last_activity'] = time();
        self::ageFlash();
    }

    private static function ageFlash(): void
    {
        $_SESSION['_flash'] = $_SESSION['_flash_next'] ?? [];
        unset($_SESSION['_flash_next']);
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        return $_SESSION[$key] ?? $default;
    }

    public static function set(string $key, mixed $value): void
    {
        $_SESSION[$key] = $value;
    }

    public static function forget(string $key): void
    {
        unset($_SESSION[$key]);
    }

    public static function flash(string $key, mixed $value): void
    {
        $_SESSION['_flash_next'][$key] = $value;
    }

    public static function getFlash(string $key, mixed $default = null): mixed
    {
        return $_SESSION['_flash'][$key] ?? $default;
    }

    public static function regenerate(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_regenerate_id(true);
        }
        unset($_SESSION['_csrf']);
    }

    public static function destroy(): void
    {
        $_SESSION = [];
        if (session_status() === PHP_SESSION_ACTIVE) {
            $p = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
            session_destroy();
        }
    }
}

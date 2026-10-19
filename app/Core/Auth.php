<?php

declare(strict_types=1);

namespace App\Core;

use App\Helpers\Time;

/** Authentication with password_hash/verify, login rate limiting and session fixation protection. */
final class Auth
{
    private static ?array $user = null;
    private static ?Permissions $gate = null;
    private static bool $loaded = false;

    /** @return array{ok: bool, error?: string, user?: array} */
    public static function attempt(string $email, string $password, string $ip): array
    {
        $email = mb_strtolower(trim($email));
        $max = (int) Config::get('session.login_max_attempts', 5);
        $lockMin = (int) Config::get('session.login_lock_minutes', 15);
        $since = gmdate('Y-m-d H:i:s', strtotime(Time::now() . ' UTC') - $lockMin * 60);

        $failsByEmail = (int) Database::value(
            'SELECT COUNT(*) FROM login_attempts WHERE email = ? AND success = 0 AND created_at >= ?',
            [$email, $since]
        );
        $failsByIp = (int) Database::value(
            'SELECT COUNT(*) FROM login_attempts WHERE ip = ? AND success = 0 AND created_at >= ?',
            [$ip, $since]
        );
        if ($failsByEmail >= $max || $failsByIp >= $max * 4) {
            return ['ok' => false, 'error' => __('auth.throttled', ['minutes' => $lockMin])];
        }

        $user = Database::first('SELECT * FROM users WHERE email = ? AND deleted_at IS NULL', [$email]);
        // Constant-ish time: always run password_verify
        $hash = $user['password_hash'] ?? '$2y$12$abcdefghijklmnopqrstuuJ0e5mR1Tq1F1c0rU0mBf6m8b5n2lZ1e';
        $valid = password_verify($password, $hash);

        $ok = $user && $valid && (int) $user['is_active'] === 1
            && (!$user['locked_until'] || $user['locked_until'] < Time::now());

        Database::insert('login_attempts', ['email' => $email, 'ip' => $ip, 'success' => $ok ? 1 : 0, 'created_at' => Time::now()]);

        if (!$ok) {
            if ($user && (int) $user['is_active'] !== 1) {
                return ['ok' => false, 'error' => __('auth.inactive')];
            }
            return ['ok' => false, 'error' => __('auth.failed')];
        }

        if (password_needs_rehash($user['password_hash'], PASSWORD_DEFAULT)) {
            Database::update('users', ['password_hash' => password_hash($password, PASSWORD_DEFAULT)], 'id = ?', [$user['id']]);
        }
        Database::update('users', ['last_login_at' => Time::now(), 'last_login_ip' => $ip, 'failed_logins' => 0], 'id = ?', [$user['id']]);
        return ['ok' => true, 'user' => $user];
    }

    public static function login(array $user): void
    {
        Session::regenerate();
        Session::forget('_local_super_recovery');
        Session::set('user_id', (int) $user['id']);
        Session::set('login_at', time());
        Session::forget('hotel_scope');
        self::$user = null;
        self::$gate = null;
        self::$loaded = false;
    }

    public static function logout(): void
    {
        Session::destroy();
        self::$user = null;
        self::$gate = null;
        self::$loaded = false;
    }

    public static function user(): ?array
    {
        if (!self::$loaded) {
            self::$loaded = true;
            $id = Session::get('user_id');
            if ($id) {
                $u = Database::first(
                    'SELECT id, name, email, phone, locale, is_active, must_change_password, line_user_id
                     FROM users WHERE id = ? AND deleted_at IS NULL',
                    [(int) $id]
                );
                self::$user = ($u && (int) $u['is_active'] === 1) ? $u : null;
                if (!self::$user) {
                    Session::forget('user_id');
                }
            }
        }
        return self::$user;
    }

    public static function id(): ?int
    {
        $u = self::user();
        return $u ? (int) $u['id'] : null;
    }

    public static function check(): bool
    {
        return self::user() !== null;
    }

    public static function gate(): Permissions
    {
        if (self::$gate === null) {
            $id = self::id();
            if ($id === null) {
                throw new Exceptions\HttpException(401, 'Unauthenticated');
            }
            self::$gate = new Permissions($id);
        }
        return self::$gate;
    }

    /** For CLI jobs and tests: act as a given user (or nobody). */
    public static function actingAs(?array $user): void
    {
        self::$user = $user;
        self::$gate = null;
        self::$loaded = true;
        HotelContext::reset();
        if ($user) {
            Session::set('user_id', (int) $user['id']);
        } else {
            Session::forget('user_id');
        }
    }
}

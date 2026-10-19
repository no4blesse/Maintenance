<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\{Auth, Config, Database, Request, Session};
use App\Core\Exceptions\HttpException;
use App\Helpers\Time;

/** Explicit, loopback-only development recovery. This route is unavailable in production. */
final class LocalSuperAccess
{
    private static function allowed(Request $request): bool
    {
        $host = strtolower((string) parse_url('http://' . ($request->server['HTTP_HOST'] ?? ''), PHP_URL_HOST));
        return Config::get('app.env') === 'development'
            && Config::get('local_access.enabled') === true
            && in_array($request->ip(), ['127.0.0.1', '::1'], true)
            && in_array($host, ['localhost', '127.0.0.1', '[::1]'], true);
    }

    public static function enter(Request $request): int
    {
        if (!self::allowed($request)) {
            throw new HttpException(404, 'Not Found');
        }
        $since = Time::nowImmutable()->modify('-15 minutes')->format('Y-m-d H:i:s');
        $attemptKey = 'local-super-recovery';
        $failures = (int) Database::value('SELECT COUNT(*) FROM login_attempts WHERE email=? AND ip=? AND success=0 AND created_at>=?', [$attemptKey, $request->ip(), $since]);
        if ($failures >= 5) {
            throw new HttpException(429, 'กรุณารอ 15 นาทีแล้วลองใหม่');
        }
        $hash = (string) Config::get('local_access.token_hash');
        $token = $request->str('token');
        $valid = preg_match('/^[a-f0-9]{64}$/', $hash) && $token !== '' && strlen($token) <= 256 && hash_equals($hash, hash('sha256', $token));
        Database::insert('login_attempts', ['email' => $attemptKey, 'ip' => $request->ip(), 'success' => $valid ? 1 : 0, 'created_at' => Time::now()]);
        if (!$valid) {
            throw new HttpException(403, 'ลิงก์กู้คืนไม่ถูกต้อง');
        }
        $user = Database::transaction(static function (): array {
            // Serialize first-use setup so simultaneous recovery requests create one account.
            $role = Database::first("SELECT id FROM roles WHERE code='SUPER_ADMIN' FOR UPDATE");
            if (!$role) {
                throw new HttpException(503, 'กรุณารัน database migrations ก่อน');
            }
            $email = mb_strtolower(trim((string) Config::get('local_access.email')));
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                throw new HttpException(503, 'ตั้งค่าอีเมลบัญชีกู้คืนไม่ถูกต้อง');
            }
            $user = Database::first('SELECT * FROM users WHERE email=? FOR UPDATE', [$email]);
            if (!$user) {
                $id = Database::insert('users', ['name' => 'Super Admin', 'email' => $email, 'password_hash' => password_hash(bin2hex(random_bytes(32)), PASSWORD_DEFAULT), 'is_active' => 1]);
            } else {
                $id = (int) $user['id'];
                Database::update('users', ['is_active' => 1, 'deleted_at' => null, 'locked_until' => null, 'failed_logins' => 0], 'id=?', [$id]);
            }
            Database::execute('INSERT IGNORE INTO user_hotel_roles (user_id,hotel_id,role_id) VALUES (?,NULL,?)', [$id, $role['id']]);
            Database::update('users', ['last_login_at' => Time::now(), 'last_login_ip' => '127.0.0.1'], 'id=?', [$id]);
            return Database::first('SELECT * FROM users WHERE id=?', [$id]);
        });
        Auth::login($user);
        Session::forget('intended');
        Session::set('_local_super_recovery', ['user_id' => (int) $user['id'], 'expires_at' => time() + 60 * (int) Config::get('local_access.reset_minutes', 10)]);
        Audit::log('auth.local_super_login', 'user', (int) $user['id'], null, null, ['mode' => 'local-development']);
        return (int) $user['id'];
    }

    public static function canResetPassword(int $userId, Request $request): bool
    {
        $grant = Session::get('_local_super_recovery');
        return self::allowed($request) && is_array($grant)
            && ($grant['user_id'] ?? null) === $userId
            && (int) ($grant['expires_at'] ?? 0) > time();
    }
}

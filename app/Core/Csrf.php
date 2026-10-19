<?php

declare(strict_types=1);

namespace App\Core;

/** Synchroniser-token CSRF protection. */
final class Csrf
{
    public static function token(): string
    {
        $t = Session::get('_csrf');
        if (!is_string($t) || strlen($t) < 32) {
            $t = bin2hex(random_bytes(32));
            Session::set('_csrf', $t);
        }
        return $t;
    }

    public static function verify(Request $request): bool
    {
        $sent = $request->post['_token'] ?? $request->header('X-CSRF-Token') ?? ($request->json()['_token'] ?? null);
        $expected = Session::get('_csrf');
        return is_string($sent) && is_string($expected) && $expected !== '' && hash_equals($expected, $sent);
    }
}

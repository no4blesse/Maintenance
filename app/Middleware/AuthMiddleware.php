<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Core\Auth;
use App\Core\Exceptions\HttpException;
use App\Core\Request;
use App\Core\Response;

final class AuthMiddleware
{
    public function handle(Request $request, callable $next, ?string $arg = null): Response
    {
        if (!Auth::check()) {
            throw new HttpException(401, 'Unauthenticated');
        }
        if (!empty(Auth::user()['must_change_password']) && !in_array($request->path, ['/profile', '/profile/password', '/logout'], true)) {
            return $request->wantsJson()
                ? Response::json(['ok' => false, 'message' => 'กรุณาเปลี่ยนรหัสผ่านที่หน้าโปรไฟล์'], 403)
                : Response::redirect('/profile');
        }
        return $next($request);
    }
}

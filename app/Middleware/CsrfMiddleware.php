<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Core\Csrf;
use App\Core\Exceptions\HttpException;
use App\Core\Request;
use App\Core\Response;

/** Verifies CSRF token for all state-changing requests. */
final class CsrfMiddleware
{
    public function handle(Request $request, callable $next, ?string $arg = null): Response
    {
        if (!in_array($request->method, ['GET', 'HEAD', 'OPTIONS'], true) && !Csrf::verify($request)) {
            throw new HttpException(419, __('error.csrf'));
        }
        return $next($request);
    }
}

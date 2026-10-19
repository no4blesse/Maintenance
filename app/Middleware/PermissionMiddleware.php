<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Core\Auth;
use App\Core\Exceptions\ForbiddenException;
use App\Core\Request;
use App\Core\Response;

/**
 * Route-level gate: user must hold the permission in at least one hotel.
 * Hotel-specific checks are enforced again in services/policies.
 * Syntax: perm:tickets.view  or  perm:tickets.view|tickets.view_own (any)
 */
final class PermissionMiddleware
{
    public function handle(Request $request, callable $next, ?string $arg = null): Response
    {
        $any = false;
        foreach (explode('|', (string) $arg) as $perm) {
            if ($perm !== '' && Auth::gate()->can($perm)) {
                $any = true;
                break;
            }
        }
        if (!$any) {
            throw new ForbiddenException();
        }
        return $next($request);
    }
}

<?php

declare(strict_types=1);

namespace App\Core;

use App\Core\Exceptions\BusinessRuleException;
use App\Core\Exceptions\HttpException;
use App\Core\Exceptions\ValidationException;
use App\Middleware\AuthMiddleware;
use App\Middleware\CsrfMiddleware;
use App\Middleware\GuestMiddleware;
use App\Middleware\PermissionMiddleware;
use Throwable;

final class Application
{
    private Router $router;

    public function __construct()
    {
        $this->router = new Router([
            'auth' => AuthMiddleware::class,
            'guest' => GuestMiddleware::class,
            'csrf' => CsrfMiddleware::class,
            'perm' => PermissionMiddleware::class,
        ]);
        $router = $this->router;
        require BASE_PATH . '/routes/web.php';
        require BASE_PATH . '/routes/api.php';
    }

    public function router(): Router
    {
        return $this->router;
    }

    public function run(): void
    {
        $request = Request::capture();
        Session::start($request);
        $response = $this->handle($request);
        $response->send();
    }

    public function handle(Request $request): Response
    {
        try {
            $user = Auth::user();
            if ($user && in_array($user['locale'] ?? '', ['th', 'en'], true)) {
                Lang::setLocale($user['locale']);
            }
            $response = $this->router->dispatch($request);
        } catch (Throwable $e) {
            $response = $this->renderException($e, $request);
        }
        return $this->secureHeaders($response, $request);
    }

    private function secureHeaders(Response $r, Request $request): Response
    {
        $nonce = View::nonce();
        $r->header('X-Content-Type-Options', 'nosniff')
            ->header('X-Frame-Options', 'SAMEORIGIN')
            ->header('Referrer-Policy', 'strict-origin-when-cross-origin')
            ->header('Permissions-Policy', 'camera=(self), geolocation=(), microphone=()')
            ->header(
                'Content-Security-Policy',
                "default-src 'self'; script-src 'self' 'nonce-{$nonce}'; style-src 'self' 'unsafe-inline'; "
                . "img-src 'self' data: blob:; font-src 'self' data:; connect-src 'self'; frame-ancestors 'self'; "
                . "form-action 'self'; base-uri 'self'; object-src 'none'"
            );
        if ($request->isSecure()) {
            $r->header('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
        }
        if ($r->getHeader('Cache-Control') === null) {
            $r->header('Cache-Control', 'no-store, private');
        }
        return $r;
    }

    private function renderException(Throwable $e, Request $request): Response
    {
        if ($e instanceof ValidationException) {
            if ($request->wantsJson()) {
                return Response::json(['ok' => false, 'message' => $e->getMessage(), 'errors' => $e->errors], 422);
            }
            Session::flash('errors', $e->errors);
            Session::flash('old', array_diff_key($request->post, array_flip(['_token', 'password', 'password_confirmation', 'current_password'])));
            Session::flash('error', __('validation.failed'));
            return Response::redirect(Controller::safeBack($request));
        }
        if ($e instanceof BusinessRuleException) {
            if ($request->wantsJson()) {
                return Response::json(['ok' => false, 'message' => $e->getMessage()], 422);
            }
            Session::flash('error', $e->getMessage());
            Session::flash('old', array_diff_key($request->post, array_flip(['_token', 'password'])));
            return Response::redirect(Controller::safeBack($request));
        }
        if ($e instanceof HttpException) {
            if ($e->status === 401) {
                if ($request->wantsJson()) {
                    return Response::json(['ok' => false, 'message' => 'Unauthenticated'], 401);
                }
                if ($request->method === 'GET') {
                    Session::set('intended', $request->path);
                }
                return Response::redirect('/login');
            }
            if ($e->status === 403) {
                Logger::warning('Forbidden', ['path' => $request->path, 'user' => Auth::id(), 'ip' => $request->ip()]);
            }
            return $this->errorPage($request, $e->status, $e->getMessage());
        }

        $id = substr(bin2hex(random_bytes(4)), 0, 8);
        Logger::error($e::class . ': ' . $e->getMessage(), [
            'ref' => $id, 'file' => $e->getFile() . ':' . $e->getLine(), 'path' => $request->path, 'user' => Auth::id(),
            'trace' => mb_substr($e->getTraceAsString(), 0, 4000),
        ]);
        $message = Config::get('app.debug')
            ? $e::class . ': ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine()
            : __('error.server', ['ref' => $id]);
        return $this->errorPage($request, 500, $message);
    }

    private function errorPage(Request $request, int $status, string $message): Response
    {
        if ($request->wantsJson()) {
            return Response::json(['ok' => false, 'message' => $message], $status);
        }
        try {
            $view = new View(BASE_PATH . '/app/Views');
            $layout = Auth::check() ? 'layouts/app' : 'layouts/guest';
            return Response::html($view->render('errors/error', [
                'status' => $status,
                'message' => $message,
                'title' => (string) $status,
            ], $layout), $status);
        } catch (Throwable $inner) {
            Logger::error('Error page failed: ' . $inner->getMessage());
            return Response::html('<h1>' . $status . '</h1><p>' . e($message) . '</p>', $status);
        }
    }
}

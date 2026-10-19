<?php

declare(strict_types=1);

namespace App\Core;

use App\Core\Exceptions\ForbiddenException;
use App\Core\Exceptions\ValidationException;

abstract class Controller
{
    public function __construct(protected Request $request)
    {
    }

    protected function view(string $template, array $data = [], ?string $layout = 'layouts/app'): Response
    {
        $view = new View(BASE_PATH . '/app/Views');
        return Response::html($view->render($template, $data, $layout));
    }

    protected function json(mixed $data, int $status = 200): Response
    {
        return Response::json($data, $status);
    }

    protected function redirect(string $to): Response
    {
        return Response::redirect($to);
    }

    protected function back(string $fallback = '/'): Response
    {
        return Response::redirect(self::safeBack($this->request, $fallback));
    }

    /** Same-origin referer only (prevents open redirects). */
    public static function safeBack(Request $request, string $fallback = '/'): string
    {
        $ref = (string) ($request->server['HTTP_REFERER'] ?? '');
        $host = (string) ($request->server['HTTP_HOST'] ?? '');
        if ($ref !== '' && $host !== '' && parse_url($ref, PHP_URL_HOST) . (parse_url($ref, PHP_URL_PORT) ? ':' . parse_url($ref, PHP_URL_PORT) : '') === $host) {
            $path = (string) parse_url($ref, PHP_URL_PATH);
            $q = parse_url($ref, PHP_URL_QUERY);
            return $path . ($q ? '?' . $q : '');
        }
        return url($fallback);
    }

    /**
     * @param array<string, string|list<string>> $rules
     * @return array<string, mixed>
     */
    protected function validate(array $rules, array $labels = [], ?array $data = null): array
    {
        return Validator::validate($data ?? $this->request->all(), $rules, $labels);
    }

    protected function authorize(string $permission, ?int $hotelId = null): void
    {
        Auth::gate()->authorize($permission, $hotelId);
    }

    protected function user(): array
    {
        return Auth::user() ?? throw new ForbiddenException();
    }

    protected function userId(): int
    {
        return (int) $this->user()['id'];
    }

    protected function flash(string $type, string $message): void
    {
        Session::flash($type, $message);
    }

    /**
     * Resolves the hotel targeted by a create/write action. The submitted
     * hotel id is accepted only if the user holds $permission there.
     */
    protected function hotelForWrite(string $permission, string $field = 'hotel_id'): int
    {
        $requested = $this->request->int($field);
        $allowed = Auth::gate()->hotelsWith($permission);
        if ($requested !== null) {
            if (!in_array($requested, $allowed, true)) {
                throw new ForbiddenException();
            }
            return $requested;
        }
        $sel = HotelContext::selectedId();
        if ($sel !== null && in_array($sel, $allowed, true)) {
            return $sel;
        }
        if (count($allowed) === 1) {
            return $allowed[0];
        }
        throw new ValidationException([$field => __('validation.hotel_required')]);
    }

    protected function page(): int
    {
        return max(1, (int) ($this->request->query['page'] ?? 1));
    }

    protected function perPage(): int
    {
        return max(5, min(100, (int) setting('pagination_size', 20)));
    }
}

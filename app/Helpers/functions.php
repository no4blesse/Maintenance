<?php

declare(strict_types=1);

use App\Core\Auth;
use App\Core\Config;
use App\Core\Csrf;
use App\Core\HotelContext;
use App\Core\Lang;
use App\Core\Request;
use App\Core\Session;
use App\Helpers\Time;
use App\Services\SettingsService;

/** HTML-escape (always use for untrusted output). */
function e(mixed $value): string
{
    return htmlspecialchars((string) ($value ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function __(string $key, array $replace = [], ?string $fallback = null): string
{
    return Lang::get($key, $replace, $fallback);
}

function config(string $key, mixed $default = null): mixed
{
    return Config::get($key, $default);
}

function setting(string $key, mixed $default = null): mixed
{
    return SettingsService::get($key, $default);
}

function url(string $path = '', array $query = []): string
{
    $base = PHP_SAPI === 'cli' ? '' : Request::detectBasePath();
    $u = $base . '/' . ltrim($path, '/');
    if ($query) {
        $u .= (str_contains($u, '?') ? '&' : '?') . http_build_query($query);
    }
    return $u;
}

/** Absolute URL for external use (LINE messages, QR codes). */
function absolute_url(string $path = ''): string
{
    return rtrim((string) config('app.url'), '/') . '/' . ltrim($path, '/');
}

function asset(string $path): string
{
    $file = BASE_PATH . '/public/assets/' . ltrim($path, '/');
    $v = is_file($file) ? (string) filemtime($file) : '1';
    return url('assets/' . ltrim($path, '/')) . '?v=' . $v;
}

function csrf_token(): string
{
    return Csrf::token();
}

function csrf_field(): string
{
    return '<input type="hidden" name="_token" value="' . e(Csrf::token()) . '">';
}

function old(string $key, mixed $default = ''): mixed
{
    $old = Session::getFlash('old', []);
    return $old[$key] ?? $default;
}

function field_error(string $key): ?string
{
    $errors = Session::getFlash('errors', []);
    return $errors[$key] ?? null;
}

/** Bootstrap invalid class helper */
function invalid(string $key): string
{
    return field_error($key) ? ' is-invalid' : '';
}

function auth_user(): ?array
{
    return Auth::user();
}

function can(string $permission, ?int $hotelId = null): bool
{
    return Auth::check() && Auth::gate()->can($permission, $hotelId);
}

function money(mixed $value, int $decimals = 2): string
{
    return '฿' . number_format((float) ($value ?? 0), $decimals);
}

function num(mixed $value, int $decimals = 0): string
{
    return number_format((float) ($value ?? 0), $decimals);
}

/** Qty display: trims trailing zeros (12.50 -> 12.5, 3.00 -> 3) */
function qty(mixed $value): string
{
    $s = number_format((float) ($value ?? 0), 2, '.', ',');
    return str_contains($s, '.') ? rtrim(rtrim($s, '0'), '.') : $s;
}

/** Format a UTC datetime for display in the operations timezone. */
function dt(?string $utc, ?string $format = null): string
{
    return Time::display($utc, $format);
}

/** Format a DATE column (no timezone conversion). */
function d(?string $date): string
{
    return Time::displayDate($date);
}

function ago(?string $utc): string
{
    return Time::ago($utc);
}

function icon(string $name, string $class = ''): string
{
    return '<i data-lucide="' . e($name) . '" class="icon ' . e($class) . '" aria-hidden="true"></i>';
}

function selected(mixed $a, mixed $b): string
{
    return (string) $a === (string) $b ? ' selected' : '';
}

function checked(mixed $cond): string
{
    return $cond ? ' checked' : '';
}

function hotel_name(?array $hotel): string
{
    if (!$hotel) {
        return '';
    }
    return Lang::locale() === 'en' ? (string) $hotel['name_en'] : (string) $hotel['name_en'];
}

function current_hotel_id(): ?int
{
    return HotelContext::selectedId();
}

/** Label for bilingual master data rows having name_th/name_en */
function label(?array $row, string $prefix = 'name'): string
{
    if (!$row) {
        return '';
    }
    $key = $prefix . '_' . Lang::locale();
    return (string) ($row[$key] ?? $row[$prefix . '_th'] ?? '');
}

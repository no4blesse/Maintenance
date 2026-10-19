<?php

declare(strict_types=1);

namespace App\Core;

final class Response
{
    /** @var array<string, string> */
    private array $headers = [];
    /** @var (callable(): void)|null */
    private $streamer = null;

    public function __construct(private string $body = '', private int $status = 200)
    {
    }

    public static function html(string $body, int $status = 200): self
    {
        return (new self($body, $status))->header('Content-Type', 'text/html; charset=UTF-8');
    }

    public static function json(mixed $data, int $status = 200): self
    {
        return (new self((string) json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE), $status))
            ->header('Content-Type', 'application/json; charset=UTF-8');
    }

    public static function redirect(string $to, int $status = 302): self
    {
        if (!preg_match('#^https?://#i', $to)) {
            $base = PHP_SAPI === 'cli' ? '' : Request::detectBasePath();
            if ($base === '' || !str_starts_with($to, $base . '/')) {
                $to = url($to);
            }
        }
        return (new self('', $status))->header('Location', $to);
    }

    /** Streams a private file. $filename is sanitised for the header. */
    public static function file(string $path, string $filename, string $mime, bool $inline = true): self
    {
        $r = new self('', 200);
        $safe = preg_replace('/[^\w.\-ก-๙ ]+/u', '_', $filename) ?: 'download';
        $r->header('Content-Type', $mime)
            ->header('Content-Length', (string) filesize($path))
            ->header('Content-Disposition', ($inline ? 'inline' : 'attachment') . "; filename=\"download\"; filename*=UTF-8''" . rawurlencode($safe))
            ->header('X-Content-Type-Options', 'nosniff')
            ->header('Cache-Control', 'private, max-age=600');
        $r->streamer = static function () use ($path): void {
            readfile($path);
        };
        return $r;
    }

    /** @param callable(): void $fn */
    public static function stream(callable $fn, string $mime, string $filename): self
    {
        $r = new self('', 200);
        $r->header('Content-Type', $mime)
            ->header('Content-Disposition', "attachment; filename=\"export.csv\"; filename*=UTF-8''" . rawurlencode($filename))
            ->header('Cache-Control', 'no-store');
        $r->streamer = $fn;
        return $r;
    }

    public function header(string $name, string $value): self
    {
        $this->headers[$name] = $value;
        return $this;
    }

    public function status(): int
    {
        return $this->status;
    }

    public function body(): string
    {
        return $this->body;
    }

    public function getHeader(string $name): ?string
    {
        return $this->headers[$name] ?? null;
    }

    public function send(): void
    {
        if (!headers_sent()) {
            http_response_code($this->status);
            foreach ($this->headers as $name => $value) {
                header($name . ': ' . $value);
            }
        }
        if ($this->streamer !== null) {
            ($this->streamer)();
            return;
        }
        echo $this->body;
    }
}

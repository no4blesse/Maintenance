<?php

declare(strict_types=1);

namespace App\Core;

use RuntimeException;

/**
 * PHP template renderer. Templates run inside this object's scope so they
 * can use $this->start()/stop()/section(). Always escape output with e().
 */
final class View
{
    /** @var array<string, string> */
    private array $sections = [];
    private ?string $current = null;
    private static string $nonce = '';

    public function __construct(private string $basePath)
    {
    }

    public static function nonce(): string
    {
        if (self::$nonce === '') {
            self::$nonce = base64_encode(random_bytes(16));
        }
        return self::$nonce;
    }

    /** @param array<string, mixed> $data */
    public function render(string $template, array $data = [], ?string $layout = 'layouts/app'): string
    {
        $content = $this->renderFile($template, $data);
        if ($layout === null) {
            return $content;
        }
        $this->sections['content'] = $content;
        return $this->renderFile($layout, $data);
    }

    /** @param array<string, mixed> $data */
    public function partial(string $template, array $data = []): string
    {
        return $this->renderFile($template, $data);
    }

    /** @param array<string, mixed> $__data */
    private function renderFile(string $__template, array $__data): string
    {
        $__file = $this->basePath . '/' . str_replace('.', '/', $__template) . '.php';
        if (!preg_match('#^[a-z0-9_/\-.]+$#i', $__template) || !is_file($__file)) {
            throw new RuntimeException('View not found: ' . $__template);
        }
        extract($__data, EXTR_SKIP);
        ob_start();
        try {
            include $__file;
        } catch (\Throwable $e) {
            ob_end_clean();
            throw $e;
        }
        return (string) ob_get_clean();
    }

    public function start(string $name): void
    {
        $this->current = $name;
        ob_start();
    }

    public function stop(): void
    {
        if ($this->current === null) {
            return;
        }
        $this->sections[$this->current] = ($this->sections[$this->current] ?? '') . ob_get_clean();
        $this->current = null;
    }

    public function section(string $name, string $default = ''): string
    {
        return $this->sections[$name] ?? $default;
    }
}

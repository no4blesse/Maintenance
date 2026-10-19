<?php

declare(strict_types=1);

namespace App\Core;

use App\Core\Exceptions\HttpException;

/**
 * Simple regex router. Patterns: /tickets/{id} where {id} matches digits,
 * any other {name} matches one path segment.
 */
final class Router
{
    /** @var list<array{method:string, regex:string, handler:mixed, middleware:list<string>, path:string}> */
    private array $routes = [];
    /** @var list<string> */
    private array $groupMiddleware = [];
    /** @var array<string, class-string> */
    private array $aliases = [];

    /** @param array<string, class-string> $aliases middleware alias => class */
    public function __construct(array $aliases = [])
    {
        $this->aliases = $aliases;
    }

    public function get(string $path, mixed $handler, array $middleware = []): void
    {
        $this->add('GET', $path, $handler, $middleware);
    }

    public function post(string $path, mixed $handler, array $middleware = []): void
    {
        $this->add('POST', $path, $handler, $middleware);
    }

    public function group(array $middleware, callable $routes): void
    {
        $prev = $this->groupMiddleware;
        $this->groupMiddleware = [...$prev, ...$middleware];
        $routes($this);
        $this->groupMiddleware = $prev;
    }

    private function add(string $method, string $path, mixed $handler, array $middleware): void
    {
        $regex = preg_replace_callback('#\{(\w+)\}#', static function (array $m): string {
            $isId = $m[1] === 'id' || str_ends_with($m[1], 'Id');
            return '(?P<' . $m[1] . '>' . ($isId ? '\d+' : '[^/]+') . ')';
        }, rtrim($path, '/') ?: '/');
        $this->routes[] = [
            'method' => $method,
            'regex' => '#^' . $regex . '$#',
            'handler' => $handler,
            'middleware' => [...$this->groupMiddleware, ...$middleware],
            'path' => $path,
        ];
    }

    public function dispatch(Request $request): Response
    {
        $methodMismatch = false;
        $method = $request->method === 'HEAD' ? 'GET' : $request->method;
        foreach ($this->routes as $route) {
            if (!preg_match($route['regex'], $request->path, $m)) {
                continue;
            }
            if ($route['method'] !== $method) {
                $methodMismatch = true;
                continue;
            }
            $request->params = array_filter($m, 'is_string', ARRAY_FILTER_USE_KEY);
            return $this->runPipeline($request, $route);
        }
        throw new HttpException($methodMismatch ? 405 : 404, $methodMismatch ? 'Method Not Allowed' : 'Not Found');
    }

    /** @param array{handler:mixed, middleware:list<string>} $route */
    private function runPipeline(Request $request, array $route): Response
    {
        $core = function (Request $req) use ($route): Response {
            return $this->callHandler($req, $route['handler']);
        };
        $pipeline = array_reduce(
            array_reverse($route['middleware']),
            function (callable $next, string $mw): callable {
                [$name, $arg] = array_pad(explode(':', $mw, 2), 2, null);
                $class = $this->aliases[$name] ?? $name;
                return static fn (Request $req): Response => (new $class())->handle($req, $next, $arg);
            },
            $core
        );
        return $pipeline($request);
    }

    private function callHandler(Request $request, mixed $handler): Response
    {
        if (is_callable($handler)) {
            $result = $handler($request);
        } else {
            [$class, $method] = $handler;
            $controller = new $class($request);
            $args = array_map(
                static fn (string $v) => ctype_digit($v) ? (int) $v : $v,
                array_values($request->params)
            );
            $result = $controller->$method(...$args);
        }
        return $result instanceof Response ? $result : Response::html((string) $result);
    }

    /** @return list<array{method:string, path:string, middleware:list<string>}> */
    public function routes(): array
    {
        return array_map(static fn ($r) => ['method' => $r['method'], 'path' => $r['path'], 'middleware' => $r['middleware']], $this->routes);
    }
}

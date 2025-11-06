<?php

namespace App\Http;

final class Router
{
    private array $routes = ['GET' => [], 'POST' => []];
    private array $groupMiddleware = [];

    public function get(string $pattern, callable|array $handler): void
    {
        $this->add('GET', $pattern, $handler);
    }
    public function post(string $pattern, callable|array $handler): void
    {
        $this->add('POST', $pattern, $handler);
    }

    public function group(array $middleware, \Closure $callback): void
    {
        array_push($this->groupMiddleware, $middleware);
        $callback($this);
        array_pop($this->groupMiddleware);
    }

    private function add(string $method, string $pattern, callable|array $handler): void
    {
        // Convert patterns like /projects/{uuid}/days/{date} to regex
        $regex = preg_replace('#\{([a-zA-Z_][a-zA-Z0-9_]*)\}#', '(?P<$1>[^/]+)', $pattern);
        $regex = '#^' . rtrim($regex, '/') . '/?$#';
        $this->routes[$method][] = [
            'regex' => $regex,
            'handler' => $handler,
            'middleware' => $this->flattenMiddleware($this->groupMiddleware),
        ];
    }

    private function flattenMiddleware(array $stack): array
    {
        $out = [];
        foreach ($stack as $group) foreach ($group as $mw) $out[] = $mw;
        return $out;
    }

    public function run(): void
    {
        $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
        $uri = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
        foreach ($this->routes[$method] ?? [] as $route) {
            if (preg_match($route['regex'], $uri, $m)) {
                $params = [];
                foreach ($m as $k => $v) if (is_string($k)) $params[$k] = $v;

                $next = function () use ($route, $params) {
                    $handler = $route['handler'];

                    if (is_array($handler)) {
                        [$target, $method] = $handler;
                        // allow either [$object, 'method'] OR [ClassName::class, 'method']
                        $obj = is_object($target) ? $target : new $target();
                        return call_user_func_array([$obj, $method], $params);
                    }

                    return call_user_func_array($handler, $params);
                };


                // run middleware chain
                $runner = array_reduce(
                    array_reverse($route['middleware']),
                    function ($next, $mwClass) {
                        return function () use ($next, $mwClass) {
                            $mw = new $mwClass();
                            return $mw->handle($next);
                        };
                    },
                    $next
                );
                $runner();
                return;
            }
        }
        http_response_code(404);
        echo "<h1 style='font-family:system-ui;color:#e9eef3'>404</h1>";
    }
}

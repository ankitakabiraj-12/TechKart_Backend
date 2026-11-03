<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Fast Regex-supported Router for REST API endpoints
 */
class Router
{
    private array $routes = [];
    private array $middlewares = [];

    public function add(string $method, string $path, callable|array $handler, array $middlewares = []): void
    {
        $this->routes[] = [
            'method' => strtoupper($method),
            'path' => $this->normalizePath($path),
            'handler' => $handler,
            'middlewares' => $middlewares,
        ];
    }

    public function get(string $path, callable|array $handler, array $middlewares = []): void
    {
        $this->add('GET', $path, $handler, $middlewares);
    }

    public function post(string $path, callable|array $handler, array $middlewares = []): void
    {
        $this->add('POST', $path, $handler, $middlewares);
    }

    public function put(string $path, callable|array $handler, array $middlewares = []): void
    {
        $this->add('PUT', $path, $handler, $middlewares);
    }

    public function patch(string $path, callable|array $handler, array $middlewares = []): void
    {
        $this->add('PATCH', $path, $handler, $middlewares);
    }

    public function delete(string $path, callable|array $handler, array $middlewares = []): void
    {
        $this->add('DELETE', $path, $handler, $middlewares);
    }

    public function options(string $path, callable|array $handler, array $middlewares = []): void
    {
        $this->add('OPTIONS', $path, $handler, $middlewares);
    }

    public function dispatch(Request $request): void
    {
        $requestMethod = $request->getMethod();
        $requestUri = $this->normalizePath($request->getUri());

        // Strip query string if any and clean up path
        $path = $request->getUri();

        // Strip subfolder prefix if backend is hosted in a subfolder (e.g. /techkart_backend/public or /techkart_backend)
        $scriptDir = str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? ''));
        if ($scriptDir !== '/' && $scriptDir !== '' && str_starts_with($path, $scriptDir)) {
            $path = substr($path, strlen($scriptDir));
        }

        // Also handle cases where Apache rewrites without public/ in scriptDir
        if (str_starts_with($path, '/public/')) {
            $path = substr($path, 7);
        }

        $requestUri = $this->normalizePath($path);

        foreach ($this->routes as $route) {
            if ($route['method'] !== $requestMethod) {
                continue;
            }

            $pattern = $this->convertRouteToRegex($route['path']);

            if (preg_match($pattern, $requestUri, $matches)) {
                $params = array_filter($matches, 'is_string', ARRAY_FILTER_USE_KEY);

                // Execute middlewares
                foreach ($route['middlewares'] as $middleware) {
                    if (is_string($middleware)) {
                        $middlewareInstance = new $middleware();
                        $middlewareInstance->handle($request);
                    } elseif (is_object($middleware) && method_exists($middleware, 'handle')) {
                        $middleware->handle($request);
                    }
                }

                $handler = $route['handler'];

                if (is_array($handler)) {
                    [$class, $method] = $handler;
                    $controller = new $class();
                    $controller->$method($request, $params);
                    return;
                }

                if (is_callable($handler)) {
                    call_user_func($handler, $request, $params);
                    return;
                }
            }
        }

        Response::error("Endpoint '{$requestMethod} {$requestUri}' not found", 404);
    }

    private function normalizePath(string $path): string
    {
        $path = trim($path, '/');
        return $path === '' ? '/' : '/' . $path;
    }

    private function convertRouteToRegex(string $route): string
    {
        $pattern = preg_replace('/\{([a-zA-Z0-9_]+)\}/', '(?P<$1>[^/]+)', $route);
        return '#^' . $pattern . '$#';
    }
}

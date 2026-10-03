<?php
declare(strict_types=1);

namespace PymeHub\Core;

/**
 * Enrutador mínimo: método + patrón con parámetros {id}, y una cadena
 * de middlewares por ruta.
 */
final class Router
{
    /** @var array<int, array{0:string,1:string,2:callable,3:array}> */
    private array $routes = [];

    public function add(string $method, string $pattern, callable $handler, array $middlewares = []): void
    {
        $regex = '#^' . preg_replace('#\{(\w+)\}#', '(?P<$1>[^/]+)', $pattern) . '$#';
        $this->routes[] = [$method, $regex, $handler, $middlewares];
    }

    public function dispatch(Request $request): void
    {
        $methodMismatch = false;
        foreach ($this->routes as [$method, $regex, $handler, $middlewares]) {
            if (!preg_match($regex, $request->path, $m)) {
                continue;
            }
            if ($method !== $request->method) {
                $methodMismatch = true;
                continue;
            }
            $request->params = array_filter($m, 'is_string', ARRAY_FILTER_USE_KEY);
            foreach ($middlewares as $mw) {
                $mw($request);
            }
            $handler($request);
            return;
        }
        if ($methodMismatch) {
            throw new ApiException('PH-VAL-001', 'Método no permitido', 405);
        }
        throw new ApiException('PH-TENANT-001', 'Ruta no encontrada', 404);
    }
}

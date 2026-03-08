<?php

declare(strict_types=1);

namespace Hopheartsceo\PostmanExporter\Services;

use Hopheartsceo\PostmanExporter\Contracts\RouteScannerInterface;
use Illuminate\Routing\Route;
use Illuminate\Routing\Router;

/**
 * Scans Laravel routes and extracts structured route information.
 */
class RouteScannerService implements RouteScannerInterface

{
    public function __construct(
        protected Router $router,
        protected array $config,
    ) {}

    /**
     * Scan all routes and return structured route data.
     *
     * @return array<int, array{
     *     method: string,
     *     methods: array<int, string>,
     *     uri: string,
     *     name: string|null,
     *     action: string|null,
     *     controller: string|null,
     *     controller_method: string|null,
     *     middleware: array<int, string>,
     *     prefix: string,
     *     parameters: array<int, string>,
     *     is_api: bool,
     * }>
     */
    public function scan(): array
    {
        $routes = $this->router->getRoutes();
        $scannedRoutes = [];

        foreach ($routes as $route) {
            /** @var Route $route */
            if ($this->shouldInclude($route)) {
                $scannedRoutes[] = $this->extractRouteData($route);
            }
        }

        return $scannedRoutes;
    }

    /**
     * Extract structured data from a single route.
     *
     * @return array<string, mixed>
     */
    protected function extractRouteData(Route $route): array
    {
        $methods = array_diff($route->methods(), ['HEAD']);
        $action = $route->getActionName();
        $controller = null;
        $controllerMethod = null;

        if ($action && $action !== 'Closure' && str_contains($action, '@')) {
            [$controller, $controllerMethod] = explode('@', $action, 2);
        } elseif ($action && $action !== 'Closure' && ! str_contains($action, '@')) {
            // Invokable controller
            $controller = $action;
            $controllerMethod = '__invoke';
        }

        $uri = $route->uri();
        $prefix = $this->extractPrefix($uri);
        $middleware = $this->getMiddleware($route);
        $parameters = $route->parameterNames();
        $isApi = str_starts_with($uri, 'api') || in_array('api', $middleware, true);

        return [
            'method' => $methods[0] ?? 'GET',
            'methods' => array_values($methods),
            'uri' => $uri,
            'name' => $route->getName(),
            'action' => $action !== 'Closure' ? $action : null,
            'controller' => $controller,
            'controller_method' => $controllerMethod,
            'middleware' => $middleware,
            'prefix' => $prefix,
            'parameters' => $parameters,
            'is_api' => $isApi,
        ];
    }

    /**
     * Determine if a route should be included based on config filters.
     */
    protected function shouldInclude(Route $route): bool
    {
        $uri = $route->uri();
        $action = $route->getActionName();
        $middleware = $this->getMiddleware($route);

        // Skip closure routes
        if ($action === 'Closure') {
            return false;
        }

        // Check include/exclude web routes
        $isApi = str_starts_with($uri, 'api') || in_array('api', $middleware, true);
        if (! $isApi && ! ($this->config['include_web_routes'] ?? false)) {
            return false;
        }

        // Apply prefix filters
        $filters = $this->config['route_filters'] ?? [];

        // Exclude prefixes
        $excludePrefixes = $filters['exclude_prefixes'] ?? [];
        foreach ($excludePrefixes as $prefix) {
            if (str_starts_with($uri, $prefix)) {
                return false;
            }
        }

        // Include prefixes (if specified, only include matching)
        $includePrefixes = $filters['include_prefixes'] ?? [];
        if (! empty($includePrefixes)) {
            $matches = false;
            foreach ($includePrefixes as $prefix) {
                if (str_starts_with($uri, $prefix)) {
                    $matches = true;
                    break;
                }
            }
            if (! $matches) {
                return false;
            }
        }

        // Middleware filters
        $excludeMiddleware = $filters['exclude_middleware'] ?? [];
        foreach ($excludeMiddleware as $mw) {
            if (in_array($mw, $middleware, true)) {
                return false;
            }
        }

        $includeMiddleware = $filters['include_middleware'] ?? [];
        if (! empty($includeMiddleware)) {
            $hasMatch = false;
            foreach ($includeMiddleware as $mw) {
                if (in_array($mw, $middleware, true)) {
                    $hasMatch = true;
                    break;
                }
            }
            if (! $hasMatch) {
                return false;
            }
        }

        return true;
    }

    /**
     * Get middleware for a route.
     *
     * @return array<int, string>
     */
    protected function getMiddleware(Route $route): array
    {
        try {
            return $route->gatherMiddleware();
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * Extract the first URI segment as the prefix for grouping.
     */
    protected function extractPrefix(string $uri): string
    {
        $segments = explode('/', trim($uri, '/'));

        if (count($segments) >= 2) {
            // Use first two segments for prefix (e.g. "api/users")
            return $segments[0] . '/' . $segments[1];
        }

        return $segments[0] ?? '';
    }
}

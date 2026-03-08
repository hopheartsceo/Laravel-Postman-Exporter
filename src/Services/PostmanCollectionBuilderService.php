<?php

declare(strict_types=1);

namespace Wessaal\PostmanExporter\Services;

use Wessaal\PostmanExporter\Contracts\CollectionBuilderInterface;

/**
 * Builds a Postman Collection v2.1 JSON structure from analyzed route data.
 */
class PostmanCollectionBuilderService implements CollectionBuilderInterface

{
    public function __construct(
        protected array $config,
    ) {}

    /**
     * Build the complete Postman Collection v2.1 structure.
     *
     * @param  array<int, array<string, mixed>>  $analyzedRoutes
     * @return array<string, mixed>
     */
    public function build(array $analyzedRoutes): array
    {
        $groupRoutes = $this->config['group_routes'] ?? true;

        if ($groupRoutes) {
            $items = $this->buildGroupedItems($analyzedRoutes);
        } else {
            $items = $this->buildFlatItems($analyzedRoutes);
        }

        return [
            'info' => [
                'name' => $this->config['collection_name'] ?? 'Laravel API Collection',
                'description' => $this->config['collection_description'] ?? 'Auto-generated API collection.',
                'schema' => 'https://schema.getpostman.com/json/collection/v2.1.0/collection.json',
                '_postman_id' => $this->generateId(),
            ],
            'item' => $items,
            'variable' => [
                [
                    'key' => 'base_url',
                    'value' => $this->config['base_url'] ?? 'http://localhost',
                    'type' => 'string',
                ],
                [
                    'key' => 'token',
                    'value' => 'your-auth-token-here',
                    'type' => 'string',
                ],
            ],
        ];
    }

    /**
     * Build items grouped by route prefix (folders).
     *
     * @param  array<int, array<string, mixed>>  $routes
     * @return array<int, array<string, mixed>>
     */
    protected function buildGroupedItems(array $routes): array
    {
        $groups = [];

        foreach ($routes as $route) {
            $prefix = $route['prefix'] ?? 'Other';
            if (! isset($groups[$prefix])) {
                $groups[$prefix] = [];
            }
            $groups[$prefix][] = $route;
        }

        $items = [];
        foreach ($groups as $prefix => $groupRoutes) {
            $folderItems = [];
            foreach ($groupRoutes as $route) {
                $folderItems[] = $this->buildRequestItem($route);
            }

            $items[] = [
                'name' => $this->humanizePrefix($prefix),
                'item' => $folderItems,
                'description' => 'Routes for ' . $prefix,
            ];
        }

        return $items;
    }

    /**
     * Build items as a flat list (no folders).
     *
     * @param  array<int, array<string, mixed>>  $routes
     * @return array<int, array<string, mixed>>
     */
    protected function buildFlatItems(array $routes): array
    {
        $items = [];
        foreach ($routes as $route) {
            $items[] = $this->buildRequestItem($route);
        }
        return $items;
    }

    /**
     * Build a single Postman request item.
     *
     * @param  array<string, mixed>  $route
     * @return array<string, mixed>
     */
    protected function buildRequestItem(array $route): array
    {
        $method = strtoupper($route['method']);
        $uri = $route['uri'];
        $name = $route['name'] ?? $this->generateRequestName($method, $uri);

        $item = [
            'name' => $name,
            'request' => [
                'method' => $method,
                'header' => $this->buildHeaders($route),
                'url' => $this->buildUrl($route),
            ],
            'response' => [],
        ];

        // Add body for POST/PUT/PATCH
        if (in_array($method, ['POST', 'PUT', 'PATCH'], true) && ! empty($route['body_params'])) {
            $item['request']['body'] = [
                'mode' => 'raw',
                'raw' => json_encode($route['body_params'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
                'options' => [
                    'raw' => [
                        'language' => 'json',
                    ],
                ],
            ];
        }

        // Add description
        $item['request']['description'] = $this->buildDescription($route);

        return $item;
    }

    /**
     * Build the URL structure for a request.
     *
     * @param  array<string, mixed>  $route
     * @return array<string, mixed>
     */
    protected function buildUrl(array $route): array
    {
        $uri = $route['uri'];

        // Replace route parameters with Postman variable syntax
        $rawPath = preg_replace('/\{(\w+?)\??}/', ':$1', $uri);

        // Build path segments
        $pathSegments = array_filter(explode('/', $rawPath));

        // Build path variables
        $pathVariables = [];
        foreach ($route['path_params'] ?? [] as $paramName => $exampleValue) {
            $pathVariables[] = [
                'key' => $paramName,
                'value' => (string) $exampleValue,
                'description' => 'Path parameter: ' . $paramName,
            ];
        }

        $url = [
            'raw' => '{{base_url}}/' . $rawPath,
            'host' => ['{{base_url}}'],
            'path' => array_values($pathSegments),
        ];

        if (! empty($pathVariables)) {
            $url['variable'] = $pathVariables;
        }

        // Add query parameters
        if (! empty($route['query_params'])) {
            $queryParams = [];
            foreach ($route['query_params'] as $key => $info) {
                $queryParams[] = [
                    'key' => $key,
                    'value' => is_array($info) ? (string) ($info['value'] ?? '') : (string) $info,
                    'description' => is_array($info) ? ($info['description'] ?? '') : '',
                    'disabled' => false,
                ];
            }
            $url['query'] = $queryParams;
        }

        return $url;
    }

    /**
     * Build headers for a request.
     *
     * @param  array<string, mixed>  $route
     * @return array<int, array<string, string>>
     */
    protected function buildHeaders(array $route): array
    {
        $headers = [];

        // Add default headers
        $defaultHeaders = $this->config['default_headers'] ?? [];
        foreach ($defaultHeaders as $key => $value) {
            $headers[] = [
                'key' => $key,
                'value' => $value,
                'type' => 'text',
            ];
        }

        // Add auth headers from middleware
        $authHeaders = $route['auth_headers'] ?? [];
        foreach ($authHeaders as $key => $value) {
            // Avoid duplicates
            $exists = false;
            foreach ($headers as $h) {
                if ($h['key'] === $key) {
                    $exists = true;
                    break;
                }
            }
            if (! $exists) {
                $headers[] = [
                    'key' => $key,
                    'value' => $value,
                    'type' => 'text',
                ];
            }
        }

        return $headers;
    }

    /**
     * Generate a human-readable name for a request.
     */
    protected function generateRequestName(string $method, string $uri): string
    {
        $segments = array_filter(explode('/', $uri));
        $lastSegment = end($segments) ?: $uri;

        // Clean up parameter segments
        $lastSegment = preg_replace('/\{.*?}/', '', $lastSegment);
        $lastSegment = trim($lastSegment, '/');

        if (empty($lastSegment)) {
            $lastSegment = implode('/', array_slice(array_values($segments), 0, 3));
        }

        $name = ucfirst(str_replace(['-', '_'], ' ', $lastSegment));

        return $method . ' ' . $name;
    }

    /**
     * Build a description for the request.
     *
     * @param  array<string, mixed>  $route
     */
    protected function buildDescription(array $route): string
    {
        $parts = [];

        if ($route['name'] ?? null) {
            $parts[] = '**Route Name:** `' . $route['name'] . '`';
        }

        if ($route['action'] ?? null) {
            $parts[] = '**Action:** `' . $route['action'] . '`';
        }

        if (! empty($route['middleware'])) {
            $parts[] = '**Middleware:** ' . implode(', ', $route['middleware']);
        }

        return implode("\n\n", $parts);
    }

    /**
     * Convert a route prefix to a human-readable folder name.
     */
    protected function humanizePrefix(string $prefix): string
    {
        $prefix = trim($prefix, '/');
        $segments = explode('/', $prefix);

        // Capitalize each segment
        $humanized = array_map(function ($segment) {
            return ucfirst(str_replace(['-', '_'], ' ', $segment));
        }, $segments);

        return implode(' / ', $humanized);
    }

    /**
     * Generate a simple unique ID for the collection.
     */
    protected function generateId(): string
    {
        return sprintf(
            '%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
            mt_rand(0, 0xffff),
            mt_rand(0, 0xffff),
            mt_rand(0, 0xffff),
            mt_rand(0, 0x0fff) | 0x4000,
            mt_rand(0, 0x3fff) | 0x8000,
            mt_rand(0, 0xffff),
            mt_rand(0, 0xffff),
            mt_rand(0, 0xffff)
        );
    }
}

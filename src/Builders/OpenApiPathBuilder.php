<?php

declare(strict_types=1);

namespace Hopheartsceo\PostmanExporter\Builders;

use Hopheartsceo\PostmanExporter\Mappers\ValidationToJsonSchemaMapper;

/**
 * Builds individual paths and operations for OpenAPI spec.
 */
class OpenApiPathBuilder
{
    public function __construct(
        protected ValidationToJsonSchemaMapper $mapper,
    ) {}

    /**
     * Build the paths array for OpenAPI.
     *
     * @param  array<int, array<string, mixed>>  $routes
     * @return array<string, mixed>
     */
    public function build(array $routes): array
    {
        $paths = [];

        foreach ($routes as $route) {
            $uri = $this->normalizeUri($route['uri']);
            
            if (! isset($paths[$uri])) {
                $paths[$uri] = [];
            }

            $method = strtolower($route['method']);
            $paths[$uri][$method] = $this->buildOperation($route);
        }

        return $paths;
    }

    /**
     * Normalize URI to OpenAPI format (ensure leading slash).
     */
    protected function normalizeUri(string $uri): string
    {
        return '/' . ltrim($uri, '/');
    }

    /**
     * Build an individual operation (GET/POST/etc).
     */
    protected function buildOperation(array $route): array
    {
        $operation = [
            'summary' => $route['name'] ?? '',
            'description' => "Controller: {$route['controller']}@{$route['controller_method']}",
            'parameters' => $this->buildParameters($route),
            'responses' => [
                '200' => [
                    'description' => 'Successful response',
                    'content' => [
                        'application/json' => [
                            'schema' => [
                                'type' => 'object',
                            ],
                        ],
                    ],
                ],
            ],
        ];

        // Add request body for mutation methods
        if (in_array(strtoupper($route['method']), ['POST', 'PUT', 'PATCH'])) {
            $schema = $this->mapper->map($route['body_params_parsed'] ?? []);
            if (! empty($schema['properties'])) {
                $operation['requestBody'] = [
                    'required' => true,
                    'content' => [
                        'application/json' => [
                            'schema' => $schema,
                        ],
                    ],
                ];
            }
        }

        // Add security if auth middleware detected
        $security = $this->detectSecurity($route['middleware'] ?? []);
        if (! empty($security)) {
            $operation['security'] = $security;
        }

        return $operation;
    }

    /**
     * Build path parameters.
     */
    protected function buildParameters(array $route): array
    {
        $parameters = [];

        foreach ($route['parameters'] ?? [] as $param) {
            $parameters[] = [
                'name' => $param,
                'in' => 'path',
                'required' => true,
                'schema' => [
                    'type' => 'string', // Default to string, refine if possible
                ],
            ];
        }

        // Add query parameters if any
        foreach ($route['query_params'] ?? [] as $name => $info) {
            $parameters[] = [
                'name' => $name,
                'in' => 'query',
                'required' => false,
                'description' => $info['description'] ?? '',
                'schema' => [
                    'type' => 'string',
                    'example' => $info['value'] ?? null,
                ],
            ];
        }

        return $parameters;
    }

    /**
     * Detect security requirements from middleware.
     */
    protected function detectSecurity(array $middleware): array
    {
        $security = [];
        
        $authMiddleware = [
            'auth:sanctum' => 'bearerAuth',
            'auth:api' => 'bearerAuth',
            'auth' => 'bearerAuth',
            'App\Http\Middleware\Authenticate' => 'bearerAuth',
        ];

        foreach ($middleware as $m) {
            if (isset($authMiddleware[$m])) {
                return [[$authMiddleware[$m] => []]];
            }
        }

        return $security;
    }
}

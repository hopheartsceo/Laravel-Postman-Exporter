<?php

declare(strict_types=1);

namespace Hopheartsceo\PostmanExporter\Builders;

use Hopheartsceo\PostmanExporter\Mappers\ValidationToJsonSchemaMapper;
use Hopheartsceo\PostmanExporter\Services\ResponseExtractorService;

/**
 * Builds individual paths and operations for OpenAPI spec.
 */
class OpenApiPathBuilder
{
    public function __construct(
        protected ValidationToJsonSchemaMapper $mapper,
        protected ResponseExtractorService $responseExtractor,
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
            'responses' => $this->buildResponses($route),
        ];

        // Add request body for mutation methods
        if (in_array(strtoupper($route['method']), ['POST', 'PUT', 'PATCH'])) {
            $schema = $this->mapper->map($route['body_params_parsed'] ?? []);

            if (! empty($schema['properties'])) {
                $contentType = $this->hasFileFields($route)
                    ? 'multipart/form-data'
                    : 'application/json';

                $operation['requestBody'] = [
                    'required' => true,
                    'content' => [
                        $contentType => [
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
     * Build OpenAPI responses from the controller response information.
     *
     * @return array<string, mixed>
     */
    protected function buildResponses(array $route): array
    {
        $responses = [];

        foreach ($this->responseExtractor->extract($route) as $response) {
            $statusCode = (string) ($response['status_code'] ?? 200);
            $statusText = $response['status_text'] ?? 'Response';
            $body = $response['body'] ?? null;

            $openApiResponse = [
                'description' => $statusText,
            ];

            if ($body !== null && $statusCode !== '204') {
                $openApiResponse['content'] = [
                    'application/json' => [
                        'schema' => $this->inferResponseSchema($body),
                        'example' => $body,
                    ],
                ];
            }

            $responses[$statusCode] = $openApiResponse;
        }

        return $responses;
    }

    /**
     * Infer a basic OpenAPI schema from an extracted response example.
     *
     * @return array<string, mixed>
     */
    protected function inferResponseSchema(mixed $value): array
    {
        if (is_array($value)) {
            if (array_is_list($value)) {
                $schema = [
                    'type' => 'array',
                    'items' => [],
                ];

                if ($value !== []) {
                    $schema['items'] = $this->inferResponseSchema($value[0]);
                }

                return $schema;
            }

            $properties = [];

            foreach ($value as $key => $item) {
                $properties[(string) $key] = $this->inferResponseSchema($item);
            }

            return [
                'type' => 'object',
                'properties' => $properties,
            ];
        }

        if (is_bool($value)) {
            return ['type' => 'boolean'];
        }

        if (is_int($value)) {
            return ['type' => 'integer'];
        }

        if (is_float($value)) {
            return ['type' => 'number', 'format' => 'float'];
        }

        return ['type' => 'string'];
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
     * Determine whether the request contains file fields.
     */
    protected function hasFileFields(array $route): bool
    {
        foreach ($route['body_params_parsed'] ?? [] as $field) {
            if (($field['type'] ?? null) === 'file') {
                return true;
            }
        }

        return false;
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

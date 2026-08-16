<?php

declare(strict_types=1);

namespace Hopheartsceo\PostmanExporter\Services;

use Hopheartsceo\PostmanExporter\Contracts\ResponseExtractorInterface;
use ReflectionClass;
use ReflectionMethod;
use ReflectionNamedType;

/**
 * Analyzes controller return types and extracts response structures.
 *
 * Distinct HTTP status codes are combined from PHPDoc annotations,
 * API Resources, direct Laravel responses, response helper pipelines,
 * Eloquent model returns, and framework-inferred responses.
 *
 * When multiple sources describe the same status code, the earlier
 * explicit source is kept.
 */
class ResponseExtractorService implements ResponseExtractorInterface
{
    public function __construct(
        protected ?ResourceSchemaAnalyzerService $resourceAnalyzer = null,
        protected ?ResponsePipelineTracerService $pipelineTracer = null,
        protected ?FrameworkErrorResponseService $frameworkErrorAnalyzer = null,
    ) {}

    /**
     * Extract response information from a controller method.
     *
     * @param  array<string, mixed>  $routeData
     * @return array<int, array<string, mixed>>
     */
    public function extract(array $routeData): array
    {
        $controller = $routeData['controller'] ?? null;
        $method = $routeData['controller_method'] ?? null;

        if (! $controller || ! $method || ! class_exists($controller)) {
            return [$this->buildFallbackResponse()];
        }

        try {
            $reflection = new ReflectionMethod($controller, $method);
        } catch (\ReflectionException) {
            return [$this->buildFallbackResponse()];
        }

        $responses = $this->extractAllFromPhpDoc($reflection);

        $resourceResponse = $this->extractFromApiResource($reflection);
        if ($resourceResponse !== null) {
            $responses[] = $resourceResponse;
        }

        $pipelineResponses = $this->extractAllFromResponsePipeline($reflection);

        if ($pipelineResponses !== []) {
            $responses = array_merge($responses, $pipelineResponses);
        } else {
            // Keep the legacy source parser only as a fallback for response
            // forms that the AST tracer does not understand yet, such as
            // response()->noContent().
            $jsonResponse = $this->extractFromJsonResponse($reflection);

            if ($jsonResponse !== null) {
                $responses[] = $jsonResponse;
            }
        }

        $modelResponse = $this->extractFromModelReturn($reflection);
        if ($modelResponse !== null) {
            $responses[] = $modelResponse;
        }

        if ($responses === []) {
            $responses[] = $this->buildFallbackResponse();
        }

        $frameworkAnalyzer = $this->frameworkErrorAnalyzer
            ?? new FrameworkErrorResponseService();

        $responses = array_merge(
            $responses,
            $frameworkAnalyzer->analyze($routeData, $reflection),
        );

        return $this->deduplicateResponses($responses);
    }

    /**
     * Extract response from PHPDoc @response annotation.
     *
     * @return array<string, mixed>|null
     */
    protected function extractFromPhpDoc(ReflectionMethod $method): ?array
    {
        $responses = $this->extractAllFromPhpDoc($method);

        return $responses[0] ?? null;
    }

    /**
     * Extract every valid @response annotation from a controller method.
     *
     * @return array<int, array<string, mixed>>
     */
    protected function extractAllFromPhpDoc(ReflectionMethod $method): array
    {
        $docComment = $method->getDocComment();

        if ($docComment === false) {
            return [];
        }

        $normalized = preg_replace('/^\s*\/?\*+\s?/m', '', $docComment);
        $normalized = preg_replace('/^\s*\*\s?/m', '', (string) $normalized);
        $responses = [];

        if (! preg_match_all(
            '/@response\s+(?:(\d{3})\s+)?/m',
            (string) $normalized,
            $matches,
            PREG_OFFSET_CAPTURE,
        )) {
            return [];
        }

        foreach ($matches[0] as $index => $match) {
            $start = $match[1] + strlen($match[0]);
            $jsonStart = $this->findJsonStart((string) $normalized, $start);

            if ($jsonStart === null) {
                continue;
            }

            $json = $this->extractBalancedJson((string) $normalized, $jsonStart);

            if ($json === null) {
                continue;
            }

            try {
                $decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
            } catch (\Throwable) {
                continue;
            }

            $statusMatch = $matches[1][$index][0] ?? '';
            $statusCode = $statusMatch !== '' ? (int) $statusMatch : 200;

            $responses[] = [
                'source' => 'phpdoc',
                'status_code' => $statusCode,
                'status_text' => $this->getStatusText($statusCode),
                'body' => $decoded,
                'name' => $statusCode >= 400 ? 'Error Response' : 'Success Response',
            ];
        }

        return $responses;
    }

    protected function findJsonStart(string $source, int $offset): ?int
    {
        $length = strlen($source);

        for ($i = $offset; $i < $length; $i++) {
            if ($source[$i] === '{' || $source[$i] === '[') {
                return $i;
            }

            if (substr($source, $i, 9) === '@response') {
                return null;
            }
        }

        return null;
    }

    protected function extractBalancedJson(string $source, int $start): ?string
    {
        $open = $source[$start] ?? null;

        if ($open !== '{' && $open !== '[') {
            return null;
        }

        $close = $open === '{' ? '}' : ']';
        $depth = 0;
        $inString = false;
        $escaped = false;
        $length = strlen($source);

        for ($i = $start; $i < $length; $i++) {
            $char = $source[$i];

            if ($inString) {
                if ($escaped) {
                    $escaped = false;
                    continue;
                }

                if ($char === '\\') {
                    $escaped = true;
                    continue;
                }

                if ($char === '"') {
                    $inString = false;
                }

                continue;
            }

            if ($char === '"') {
                $inString = true;
                continue;
            }

            if ($char === $open) {
                $depth++;
            } elseif ($char === $close) {
                $depth--;

                if ($depth === 0) {
                    return substr($source, $start, $i - $start + 1);
                }
            }
        }

        return null;
    }

    /**
     * Extract response from an API Resource.
     *
     * @return array<string, mixed>|null
     */
    protected function extractFromApiResource(ReflectionMethod $method): ?array
    {
        $source = $this->getMethodSource($method);
        $paginated = $source !== null && $this->isPaginatedResourceSource($source);
        $statusCode = $source !== null ? $this->extractResourceStatusCode($source) : 200;

        $returnType = $method->getReturnType();
        if ($returnType instanceof ReflectionNamedType && ! $returnType->isBuiltin()) {
            $className = $returnType->getName();

            if ($this->isApiResource($className)) {
                return $this->buildResourceResponse(
                    $className,
                    $this->isResourceCollection($className),
                    $paginated,
                    $statusCode,
                );
            }
        }

        if ($source === null) {
            return null;
        }

        if (preg_match('/return\s+([A-Z][\w\\\\]*Resource)::collection\s*\(/s', $source, $matches)) {
            $resolvedClass = $this->resolveClassName($matches[1], $method);

            if ($resolvedClass && $this->isApiResource($resolvedClass)) {
                return $this->buildResourceResponse(
                    $resolvedClass,
                    true,
                    $paginated,
                    $statusCode,
                );
            }
        }

        if (preg_match('/return\s+\(?\s*new\s+([A-Z][\w\\\\]*)\s*\(/s', $source, $matches)) {
            $resolvedClass = $this->resolveClassName($matches[1], $method);

            if ($resolvedClass && $this->isApiResource($resolvedClass)) {
                return $this->buildResourceResponse(
                    $resolvedClass,
                    $this->isResourceCollection($resolvedClass),
                    $paginated,
                    $statusCode,
                );
            }
        }

        return null;
    }

    /**
     * @return array<string, mixed>|null
     */
    protected function buildResourceResponse(
        string $resourceClass,
        bool $collection,
        bool $paginated,
        int $statusCode,
    ): ?array {
        $analyzer = $this->resourceAnalyzer ?? new ResourceSchemaAnalyzerService();
        $contract = $analyzer->analyzeResponse($resourceClass, $collection, $paginated);

        if ($contract === null) {
            return null;
        }

        return [
            'source' => 'api_resource',
            'status_code' => $statusCode,
            'status_text' => $this->getStatusText($statusCode),
            'body' => $contract['example'],
            'schema' => $contract['schema'],
            'name' => 'Success Response',
        ];
    }

    protected function isResourceCollection(string $className): bool
    {
        return class_exists($className)
            && is_subclass_of($className, 'Illuminate\\Http\\Resources\\Json\\ResourceCollection');
    }

    protected function isPaginatedResourceSource(string $source): bool
    {
        return (bool) preg_match('/(?:paginate|simplePaginate|cursorPaginate)\s*\(/', $source);
    }

    protected function extractResourceStatusCode(string $source): int
    {
        if (preg_match('/->\s*(?:response|toResponse)\s*\([^)]*\)\s*->\s*setStatusCode\s*\(\s*(\d{3})\s*\)/s', $source, $matches)) {
            return (int) $matches[1];
        }

        return 200;
    }

    /**
     * Extract response from response()->json() calls.
     *
     * @return array<string, mixed>|null
     */
    protected function extractFromJsonResponse(ReflectionMethod $method): ?array
    {
        $source = $this->getMethodSource($method);
        if ($source === null) {
            return null;
        }

        // Match Laravel no-content responses.
        if (preg_match('/return\s+response\(\)\s*->\s*noContent\s*\(\s*(?:(\d{3}))?[^)]*\)/s', $source, $matches)) {
            $statusCode = ! empty($matches[1]) ? (int) $matches[1] : 204;

            return [
                'source' => 'json_response',
                'status_code' => $statusCode,
                'status_text' => $this->getStatusText($statusCode),
                'body' => null,
                'name' => 'Response',
            ];
        }

        // Match JSON responses with an explicit null body, such as a 204 No Content response.
        if (preg_match('/return\s+(?:response\(\)\s*->\s*json|Response::json)\s*\(\s*null\s*(?:,\s*(\d{3}))?\s*\)/s', $source, $matches)) {
            $statusCode = isset($matches[1]) ? (int) $matches[1] : 200;

            return [
                'source' => 'json_response',
                'status_code' => $statusCode,
                'status_text' => $this->getStatusText($statusCode),
                'body' => null,
                'name' => 'Response',
            ];
        }

        // Match: return response()->json([...]) or return response()->json([...], 201)
        if (preg_match('/return\s+response\(\)\s*->\s*json\s*\(\s*(\[[\s\S]*?\])\s*(?:,\s*(\d{3}))?\s*\)/s', $source, $matches)) {
            $arrayStr = $matches[1];
            $statusCode = isset($matches[2]) ? (int) $matches[2] : 200;

            $decoded = $this->safeEvalArray($arrayStr);
            if ($decoded !== null) {
                return [
                    'source' => 'json_response',
                    'status_code' => $statusCode,
                    'status_text' => $this->getStatusText($statusCode),
                    'body' => $decoded,
                    'name' => 'Success Response',
                ];
            }
        }

        // Match: return Response::json([...])
        if (preg_match('/return\s+Response::json\s*\(\s*(\[[\s\S]*?\])\s*(?:,\s*(\d{3}))?\s*\)/s', $source, $matches)) {
            $arrayStr = $matches[1];
            $statusCode = isset($matches[2]) ? (int) $matches[2] : 200;

            $decoded = $this->safeEvalArray($arrayStr);
            if ($decoded !== null) {
                return [
                    'source' => 'json_response',
                    'status_code' => $statusCode,
                    'status_text' => $this->getStatusText($statusCode),
                    'body' => $decoded,
                    'name' => 'Success Response',
                ];
            }
        }

        return null;
    }

    /**
     * Extract a response by statically tracing custom response helper methods.
     *
     * @return array<string, mixed>|null
     */
    protected function extractFromResponsePipeline(ReflectionMethod $method): ?array
    {
        $responses = $this->extractAllFromResponsePipeline($method);

        return $responses[0] ?? null;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    protected function extractAllFromResponsePipeline(ReflectionMethod $method): array
    {
        $tracer = $this->pipelineTracer
            ?? new ResponsePipelineTracerService(
                $this->resourceAnalyzer ?? new ResourceSchemaAnalyzerService(),
            );

        $responses = [];

        foreach ($tracer->traceAll($method) as $contract) {
            $statusCode = $contract['status_code'];

            $responses[] = [
                'source' => $contract['source'] ?? 'response_pipeline',
                'status_code' => $statusCode,
                'status_text' => $this->getStatusText($statusCode),
                'body' => $contract['example'],
                'schema' => $contract['schema'],
                'name' => $statusCode >= 400 ? 'Error Response' : 'Success Response',
            ];
        }

        return $responses;
    }

    /**
     * Extract response from Model return type.
     *
     * @return array<string, mixed>|null
     */
    protected function extractFromModelReturn(ReflectionMethod $method): ?array
    {
        // Check return type hint for Eloquent model
        $returnType = $method->getReturnType();
        if ($returnType instanceof ReflectionNamedType && ! $returnType->isBuiltin()) {
            $className = $returnType->getName();
            if ($this->isEloquentModel($className)) {
                $fields = $this->extractModelFields($className);
                if (! empty($fields)) {
                    return [
                        'source' => 'model',
                        'status_code' => 200,
                        'status_text' => 'OK',
                        'body' => $fields,
                        'name' => 'Success Response',
                    ];
                }
            }
        }

        // Check source for Model::find(), Model::create(), etc.
        $source = $this->getMethodSource($method);
        if ($source === null) {
            return null;
        }

        // Match: return ModelName::find(...) / ::create(...) / ::first()
        if (preg_match('/return\s+([A-Z][\w\\\\]*)::(?:find|create|first|findOrFail)\s*\(/s', $source, $matches)) {
            $modelClass = $matches[1];
            $resolvedClass = $this->resolveClassName($modelClass, $method);

            if ($resolvedClass && $this->isEloquentModel($resolvedClass)) {
                $fields = $this->extractModelFields($resolvedClass);
                if (! empty($fields)) {
                    return [
                        'source' => 'model',
                        'status_code' => 200,
                        'status_text' => 'OK',
                        'body' => $fields,
                        'name' => 'Success Response',
                    ];
                }
            }
        }

        return null;
    }

    /**
     * Keep the first response for each status code. Source order is deliberate:
     * annotations and explicit code win over framework-inferred responses.
     *
     * @param  array<int, array<string, mixed>>  $responses
     * @return array<int, array<string, mixed>>
     */
    protected function deduplicateResponses(array $responses): array
    {
        $unique = [];
        $seen = [];

        foreach ($responses as $response) {
            $statusCode = (int) ($response['status_code'] ?? 200);

            if (isset($seen[$statusCode])) {
                continue;
            }

            $seen[$statusCode] = true;
            $unique[] = $response;
        }

        return $unique;
    }

    /**
     * Build the fallback default response.
     *
     * @return array<string, mixed>
     */
    protected function buildFallbackResponse(): array
    {
        $fallbackStatus = (int) config('postman-exporter.responses.fallback_status', 200);
        $fallbackBody = config('postman-exporter.responses.fallback_body');

        return [
            'source' => 'fallback',
            'status_code' => $fallbackStatus,
            'status_text' => $this->getStatusText($fallbackStatus),
            'body' => $fallbackBody,
            'name' => 'Fallback Response',
        ];
    }

    /**
     * Check if a class is an API Resource.
     */
    protected function isApiResource(string $className): bool
    {
        if (! class_exists($className)) {
            return false;
        }

        try {
            $reflection = new ReflectionClass($className);
            $parent = $reflection->getParentClass();

            while ($parent) {
                if ($parent->getName() === 'Illuminate\Http\Resources\Json\JsonResource'
                    || $parent->getName() === 'Illuminate\Http\Resources\Json\ResourceCollection') {
                    return true;
                }
                $parent = $parent->getParentClass();
            }
        } catch (\Throwable) {
            // ignore
        }

        return false;
    }


    /**
     * Check if a class is an Eloquent Model.
     */
    protected function isEloquentModel(string $className): bool
    {
        if (! class_exists($className)) {
            return false;
        }

        try {
            $reflection = new ReflectionClass($className);
            $parent = $reflection->getParentClass();

            while ($parent) {
                if ($parent->getName() === 'Illuminate\Database\Eloquent\Model') {
                    return true;
                }
                $parent = $parent->getParentClass();
            }
        } catch (\Throwable) {
            // ignore
        }

        return false;
    }

    /**
     * Extract fillable fields from an Eloquent Model and generate example data.
     *
     * @return array<string, mixed>
     */
    protected function extractModelFields(string $className): array
    {
        if (! class_exists($className)) {
            return [];
        }

        try {
            $reflection = new ReflectionClass($className);
            $instance = $reflection->newInstanceWithoutConstructor();

            $exampleGenerator = new ExampleDataGeneratorService();
            $fields = [];

            // Get fillable fields
            if (method_exists($instance, 'getFillable')) {
                $fillable = $instance->getFillable();
                foreach ($fillable as $field) {
                    $fields[$field] = $exampleGenerator->inferFromFieldName($field);
                }
            }

            // Add common model fields
            if (! empty($fields)) {
                $fields = array_merge(['id' => 1], $fields);
                $fields['created_at'] = '2025-01-15T10:30:00.000000Z';
                $fields['updated_at'] = '2025-01-15T10:30:00.000000Z';
            }

            return $fields;
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * Get source code of a method.
     */
    protected function getMethodSource(ReflectionMethod $method): ?string
    {
        try {
            $filename = $method->getFileName();
            $startLine = $method->getStartLine();
            $endLine = $method->getEndLine();

            if (! $filename || ! $startLine || ! $endLine) {
                return null;
            }

            $source = file_get_contents($filename);
            if ($source === false) {
                return null;
            }

            $lines = array_slice(explode("\n", $source), $startLine - 1, $endLine - $startLine + 1);

            return implode("\n", $lines);
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Resolve a short class name to fully qualified using the file's use statements.
     */
    protected function resolveClassName(string $shortName, ReflectionMethod $method): ?string
    {
        // If already fully qualified
        if (class_exists($shortName)) {
            return $shortName;
        }

        try {
            $filename = $method->getDeclaringClass()->getFileName();
            if (! $filename) {
                return null;
            }

            $source = file_get_contents($filename);
            if ($source === false) {
                return null;
            }

            // Extract use statements
            if (preg_match_all('/^use\s+([\w\\\\]+(?:\s+as\s+\w+)?)\s*;/m', $source, $matches)) {
                foreach ($matches[1] as $useStatement) {
                    $parts = preg_split('/\s+as\s+/', $useStatement);
                    $fullClass = $parts[0];
                    $alias = $parts[1] ?? class_basename($fullClass);

                    if ($alias === $shortName || class_basename($fullClass) === $shortName) {
                        if (class_exists($fullClass)) {
                            return $fullClass;
                        }
                    }
                }
            }

            // Try same namespace
            $namespace = $method->getDeclaringClass()->getNamespaceName();
            $candidate = $namespace . '\\' . $shortName;
            if (class_exists($candidate)) {
                return $candidate;
            }
        } catch (\Throwable) {
            // ignore
        }

        return null;
    }

    /**
     * Safely evaluate a PHP array string.
     *
     * @return array<string, mixed>
     */
    protected function safeEvalArray(string $arrayStr): ?array
    {
        // Replace $variables and method calls with placeholder strings
        $cleaned = preg_replace('/\$[\w\->()]+/', "'example'", $arrayStr);
        $cleaned = preg_replace('/[A-Z][\w\\\\]*::[\w]+\([^)]*\)/', "'example'", $cleaned);

        // Only allow simple literal values
        if (preg_match('/[\\\\]/', str_replace(['\'', '"', ',', '|', ' ', "\n", "\r", "\t", '[', ']', '=>', ':', '.', '_', '*'], '', $cleaned))) {
            return null;
        }

        try {
            $result = eval('return ' . $cleaned . ';');
            return is_array($result) ? $result : null;
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Get HTTP status text for a given status code.
     */
    protected function getStatusText(int $code): string
    {
        $texts = [
            200 => 'OK',
            201 => 'Created',
            202 => 'Accepted',
            204 => 'No Content',
            301 => 'Moved Permanently',
            302 => 'Found',
            400 => 'Bad Request',
            401 => 'Unauthorized',
            403 => 'Forbidden',
            404 => 'Not Found',
            405 => 'Method Not Allowed',
            422 => 'Unprocessable Entity',
            429 => 'Too Many Requests',
            500 => 'Internal Server Error',
        ];

        return $texts[$code] ?? 'OK';
    }
}

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
 * Priority order:
 * 1. PHPDoc @response annotations
 * 2. API Resource classes
 * 3. response()->json() calls
 * 4. Model return types
 * 5. Fallback default response
 */
class ResponseExtractorService implements ResponseExtractorInterface
{
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

        // 1. Try PHPDoc @response annotation
        $phpDocResponse = $this->extractFromPhpDoc($reflection);
        if ($phpDocResponse !== null) {
            return [$phpDocResponse];
        }

        // 2. Try API Resource return type
        $resourceResponse = $this->extractFromApiResource($reflection);
        if ($resourceResponse !== null) {
            return [$resourceResponse];
        }

        // 3. Try response()->json() calls in source
        $jsonResponse = $this->extractFromJsonResponse($reflection);
        if ($jsonResponse !== null) {
            return [$jsonResponse];
        }

        // 4. Try Model return type
        $modelResponse = $this->extractFromModelReturn($reflection);
        if ($modelResponse !== null) {
            return [$modelResponse];
        }

        // 5. Fallback
        return [$this->buildFallbackResponse()];
    }

    /**
     * Extract response from PHPDoc @response annotation.
     *
     * @return array<string, mixed>|null
     */
    protected function extractFromPhpDoc(ReflectionMethod $method): ?array
    {
        $docComment = $method->getDocComment();
        if ($docComment === false) {
            return null;
        }

        // Match @response { ... } or @response 200 { ... }
        // The JSON may span multiple lines in the docblock with leading "* " on each line.
        if (preg_match('/@response\s+(?:(\d{3})\s+)?(\{[\s\S]*?\})/s', $docComment, $matches)) {
            $statusCode = ! empty($matches[1]) ? (int) $matches[1] : 200;
            $jsonBody = $matches[2];

            // Strip docblock line prefixes: lines that start with optional whitespace then "* "
            $jsonBody = preg_replace('/^\s*\*\s?/m', '', $jsonBody);
            $jsonBody = trim($jsonBody);

            // Validate it's valid JSON
            $decoded = json_decode($jsonBody, true);
            if (json_last_error() === JSON_ERROR_NONE) {
                return [
                    'source' => 'phpdoc',
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
     * Extract response from API Resource return type.
     *
     * @return array<string, mixed>|null
     */
    protected function extractFromApiResource(ReflectionMethod $method): ?array
    {
        // Check return type hint
        $returnType = $method->getReturnType();
        if ($returnType instanceof ReflectionNamedType && ! $returnType->isBuiltin()) {
            $className = $returnType->getName();
            if ($this->isApiResource($className)) {
                $fields = $this->extractResourceFields($className);
                if (! empty($fields)) {
                    return [
                        'source' => 'api_resource',
                        'status_code' => 200,
                        'status_text' => 'OK',
                        'body' => ['data' => $fields],
                        'name' => 'Success Response',
                    ];
                }
            }
        }

        // Check source code for "new XxxResource(" pattern
        $source = $this->getMethodSource($method);
        if ($source === null) {
            return null;
        }

        // Match: return new XxxResource(...)
        if (preg_match('/return\s+new\s+([A-Z][\w\\\\]*Resource)\s*\(/s', $source, $matches)) {
            $resourceClass = $matches[1];
            $resolvedClass = $this->resolveClassName($resourceClass, $method);

            if ($resolvedClass && $this->isApiResource($resolvedClass)) {
                $fields = $this->extractResourceFields($resolvedClass);
                if (! empty($fields)) {
                    return [
                        'source' => 'api_resource',
                        'status_code' => 200,
                        'status_text' => 'OK',
                        'body' => ['data' => $fields],
                        'name' => 'Success Response',
                    ];
                }
            }
        }

        // Match: return XxxResource::collection(...)
        if (preg_match('/return\s+([A-Z][\w\\\\]*Resource)::collection\s*\(/s', $source, $matches)) {
            $resourceClass = $matches[1];
            $resolvedClass = $this->resolveClassName($resourceClass, $method);

            if ($resolvedClass && $this->isApiResource($resolvedClass)) {
                $fields = $this->extractResourceFields($resolvedClass);
                if (! empty($fields)) {
                    return [
                        'source' => 'api_resource',
                        'status_code' => 200,
                        'status_text' => 'OK',
                        'body' => ['data' => [$fields]],
                        'name' => 'Success Response',
                    ];
                }
            }
        }

        return null;
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

        // Match: return response()->json([...]) or return response()->json([...], 201)
        if (preg_match('/return\s+response\(\)\s*->\s*json\s*\(\s*(\[[\s\S]*?\])\s*(?:,\s*(\d{3}))?\s*\)/s', $source, $matches)) {
            $arrayStr = $matches[1];
            $statusCode = isset($matches[2]) ? (int) $matches[2] : 200;

            $decoded = $this->safeEvalArray($arrayStr);
            if (! empty($decoded)) {
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
            if (! empty($decoded)) {
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
     * Build the fallback default response.
     *
     * @return array<string, mixed>
     */
    protected function buildFallbackResponse(): array
    {
        $fallbackStatus = (int) config('postman-exporter.responses.fallback_status', 200);
        $fallbackBody = config('postman-exporter.responses.fallback_body', ['message' => 'Success']);

        return [
            'source' => 'fallback',
            'status_code' => $fallbackStatus,
            'status_text' => $this->getStatusText($fallbackStatus),
            'body' => $fallbackBody,
            'name' => 'Success Response',
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
     * Extract fields from an API Resource's toArray method.
     *
     * @return array<string, mixed>
     */
    protected function extractResourceFields(string $className): array
    {
        if (! class_exists($className)) {
            return [];
        }

        try {
            $reflection = new ReflectionClass($className);

            if (! $reflection->hasMethod('toArray')) {
                return [];
            }

            $method = $reflection->getMethod('toArray');

            // Skip if toArray is inherited from the base resource class
            if ($method->getDeclaringClass()->getName() !== $className) {
                return [];
            }

            $source = $this->getMethodSource($method);
            if ($source === null) {
                return [];
            }

            // Try to extract the returned array structure
            if (preg_match('/return\s+(\[[\s\S]*?\])\s*;/s', $source, $matches)) {
                $fields = $this->parseResourceArray($matches[1]);
                if (! empty($fields)) {
                    return $fields;
                }
            }
        } catch (\Throwable) {
            // ignore
        }

        return [];
    }

    /**
     * Parse a resource array definition to extract field names.
     *
     * @return array<string, mixed>
     */
    protected function parseResourceArray(string $arrayStr): array
    {
        $fields = [];

        // Match 'key' => $this->xxx or 'key' => value patterns
        if (preg_match_all("/['\"](\w+)['\"]\s*=>/", $arrayStr, $matches)) {
            $exampleGenerator = new ExampleDataGeneratorService();
            foreach ($matches[1] as $fieldName) {
                $fields[$fieldName] = $exampleGenerator->inferFromFieldName($fieldName);
            }
        }

        return $fields;
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
    protected function safeEvalArray(string $arrayStr): array
    {
        // Replace $variables and method calls with placeholder strings
        $cleaned = preg_replace('/\$[\w\->()]+/', "'example'", $arrayStr);
        $cleaned = preg_replace('/[A-Z][\w\\\\]*::[\w]+\([^)]*\)/', "'example'", $cleaned);

        // Only allow simple literal values
        if (preg_match('/[\\\\]/', str_replace(['\'', '"', ',', '|', ' ', "\n", "\r", "\t", '[', ']', '=>', ':', '.', '_', '*'], '', $cleaned))) {
            return [];
        }

        try {
            $result = eval('return ' . $cleaned . ';');
            return is_array($result) ? $result : [];
        } catch (\Throwable) {
            return [];
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

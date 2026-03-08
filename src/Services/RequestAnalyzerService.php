<?php

declare(strict_types=1);

namespace Wessaal\PostmanExporter\Services;

use Wessaal\PostmanExporter\Contracts\RequestAnalyzerInterface;
use Illuminate\Foundation\Http\FormRequest;
use ReflectionClass;
use ReflectionMethod;
use ReflectionNamedType;

/**
 * Analyzes controller methods to extract request parameters,
 * validation rules, and authentication requirements.
 */
class RequestAnalyzerService implements RequestAnalyzerInterface

{
    public function __construct(
        protected ValidationParserService $validationParser,
        protected ExampleDataGeneratorService $exampleGenerator,
    ) {}

    /**
     * Analyze a scanned route and enrich it with request details.
     *
     * @param  array<string, mixed>  $routeData
     * @return array<string, mixed>
     */
    public function analyze(array $routeData): array
    {
        $routeData['body_params'] = [];
        $routeData['query_params'] = [];
        $routeData['path_params'] = $this->buildPathParams($routeData['parameters'] ?? []);
        $routeData['auth_headers'] = [];

        // Detect auth headers from middleware
        $routeData['auth_headers'] = $this->detectAuthHeaders($routeData['middleware'] ?? []);

        // Analyze controller method for validation rules
        if ($routeData['controller'] && $routeData['controller_method']) {
            $this->analyzeControllerMethod($routeData);
        }

        return $routeData;
    }

    /**
     * Build path parameters with example values.
     *
     * @param  array<int, string>  $paramNames
     * @return array<string, mixed>
     */
    protected function buildPathParams(array $paramNames): array
    {
        $params = [];
        foreach ($paramNames as $name) {
            $params[$name] = $this->exampleGenerator->generateForRouteParam($name);
        }
        return $params;
    }

    /**
     * Detect authentication headers from middleware.
     *
     * @param  array<int, string>  $middleware
     * @return array<string, string>
     */
    protected function detectAuthHeaders(array $middleware): array
    {
        $headerMap = config('postman-exporter.middleware_to_headers_map', []);
        $headers = [];

        foreach ($middleware as $mw) {
            if (isset($headerMap[$mw])) {
                $headers = array_merge($headers, $headerMap[$mw]);
            }
        }

        return $headers;
    }

    /**
     * Analyze the controller method for FormRequest or inline validation.
     *
     * @param  array<string, mixed>  &$routeData
     */
    protected function analyzeControllerMethod(array &$routeData): void
    {
        $controller = $routeData['controller'];
        $method = $routeData['controller_method'];

        if (! class_exists($controller)) {
            return;
        }

        try {
            $reflection = new ReflectionMethod($controller, $method);
        } catch (\ReflectionException) {
            return;
        }

        // Check for FormRequest type-hinted parameters
        $rules = $this->extractFormRequestRules($reflection);

        // Fallback: check for inline validation in source code
        if (empty($rules)) {
            $rules = $this->extractInlineValidation($reflection);
        }

        if (! empty($rules)) {
            $parsedFields = $this->validationParser->parse($rules);
            $exampleBody = $this->validationParser->buildExampleBody($parsedFields);

            $httpMethod = strtoupper($routeData['method']);

            if (in_array($httpMethod, ['GET', 'DELETE'], true)) {
                // GET/DELETE → query params
                foreach ($parsedFields as $field => $info) {
                    $routeData['query_params'][$field] = [
                        'value' => (string) $info['example'],
                        'description' => $this->buildParamDescription($field, $info),
                    ];
                }
            } else {
                // POST/PUT/PATCH → body params
                $routeData['body_params'] = $exampleBody;
            }
        }
    }

    /**
     * Extract validation rules from a FormRequest type-hint.
     *
     * @return array<string, mixed>
     */
    protected function extractFormRequestRules(ReflectionMethod $method): array
    {
        foreach ($method->getParameters() as $param) {
            $type = $param->getType();

            if (! $type instanceof ReflectionNamedType || $type->isBuiltin()) {
                continue;
            }

            $className = $type->getName();

            if (! class_exists($className)) {
                continue;
            }

            try {
                $classReflection = new ReflectionClass($className);

                if ($classReflection->isSubclassOf(FormRequest::class)) {
                    // Instantiate the FormRequest without constructor validation
                    $instance = $classReflection->newInstanceWithoutConstructor();

                    if (method_exists($instance, 'rules')) {
                        $rulesMethod = new ReflectionMethod($instance, 'rules');
                        $rulesMethod->setAccessible(true);

                        return $rulesMethod->invoke($instance);
                    }
                }
            } catch (\Throwable) {
                continue;
            }
        }

        return [];
    }

    /**
     * Extract inline $request->validate([...]) rules from source code.
     *
     * @return array<string, mixed>
     */
    protected function extractInlineValidation(ReflectionMethod $method): array
    {
        try {
            $filename = $method->getFileName();
            $startLine = $method->getStartLine();
            $endLine = $method->getEndLine();

            if (! $filename || ! $startLine || ! $endLine) {
                return [];
            }

            $source = file_get_contents($filename);
            if ($source === false) {
                return [];
            }

            $lines = array_slice(explode("\n", $source), $startLine - 1, $endLine - $startLine + 1);
            $methodSource = implode("\n", $lines);

            // Match $request->validate([...]) or $this->validate($request, [...])
            // Using a balanced bracket approach
            if (preg_match('/->validate\(\s*\[/s', $methodSource, $matches, PREG_OFFSET_CAPTURE)) {
                $offset = $matches[0][1];
                $arrayStart = strpos($methodSource, '[', $offset);

                if ($arrayStart !== false) {
                    $arrayStr = $this->extractBalancedBrackets($methodSource, $arrayStart);

                    if ($arrayStr) {
                        // Try to evaluate the array safely
                        return $this->safeEvalArray($arrayStr);
                    }
                }
            }
        } catch (\Throwable) {
            // Silently fail
        }

        return [];
    }

    /**
     * Extract a balanced bracket expression from source code.
     */
    protected function extractBalancedBrackets(string $source, int $start): ?string
    {
        $depth = 0;
        $length = strlen($source);

        for ($i = $start; $i < $length; $i++) {
            if ($source[$i] === '[') {
                $depth++;
            } elseif ($source[$i] === ']') {
                $depth--;
                if ($depth === 0) {
                    return substr($source, $start, $i - $start + 1);
                }
            }
        }

        return null;
    }

    /**
     * Safely evaluate a PHP array string (only simple rules).
     *
     * @return array<string, mixed>
     */
    protected function safeEvalArray(string $arrayStr): array
    {
        // Only allow simple string-based validation rules
        // Security: reject anything with function calls, variables, etc.
        if (preg_match('/[$()\\\]/', str_replace(['\'', '"', ',', '|', ' ', "\n", "\r", "\t", '[', ']', '=>', ':', '.', '_', '*'], '', $arrayStr))) {
            return [];
        }

        try {
            $result = eval('return ' . $arrayStr . ';');
            return is_array($result) ? $result : [];
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * Build a human-readable description for a parameter.
     *
     * @param  array{type: string, required: bool, rules: array, example: mixed}  $info
     */
    protected function buildParamDescription(string $field, array $info): string
    {
        $parts = [];

        if ($info['required']) {
            $parts[] = 'Required';
        } else {
            $parts[] = 'Optional';
        }

        $parts[] = '(' . $info['type'] . ')';

        $relevantRules = array_filter($info['rules'], function ($rule) {
            return ! in_array($rule, ['required', 'nullable', 'sometimes', 'bail'], true)
                && ! in_array($rule, ['string', 'integer', 'numeric', 'boolean', 'array'], true);
        });

        if (! empty($relevantRules)) {
            $parts[] = implode(', ', $relevantRules);
        }

        return implode(' ', $parts);
    }
}

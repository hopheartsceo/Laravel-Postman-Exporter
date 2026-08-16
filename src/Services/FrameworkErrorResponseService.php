<?php

declare(strict_types=1);

namespace Hopheartsceo\PostmanExporter\Services;

use Illuminate\Foundation\Http\FormRequest;
use ReflectionMethod;
use ReflectionNamedType;

class FrameworkErrorResponseService
{
    /**
     * Add deterministic framework-generated responses that can be inferred
     * without executing the endpoint.
     *
     * @param  array<string, mixed>  $routeData
     * @return array<int, array<string, mixed>>
     */
    public function analyze(array $routeData, ReflectionMethod $method): array
    {
        if (! config('postman-exporter.responses.framework_validation_errors', true)) {
            return [];
        }

        $parsedFields = $routeData['body_params_parsed'] ?? [];

        if (! is_array($parsedFields) || $parsedFields === []) {
            return [];
        }

        if ($this->hasCustomFormRequestValidationFailure($method)) {
            return [];
        }

        return [$this->buildValidationErrorResponse($parsedFields)];
    }

    /**
     * Do not assume Laravel's default 422 shape when a FormRequest or one of
     * its application base classes overrides failedValidation().
     */
    protected function hasCustomFormRequestValidationFailure(ReflectionMethod $method): bool
    {
        foreach ($method->getParameters() as $parameter) {
            $type = $parameter->getType();

            if (! $type instanceof ReflectionNamedType || $type->isBuiltin()) {
                continue;
            }

            $className = $type->getName();

            if (! class_exists($className) || ! is_subclass_of($className, FormRequest::class)) {
                continue;
            }

            try {
                $reflection = new \ReflectionClass($className);

                if (! $reflection->hasMethod('failedValidation')) {
                    continue;
                }

                $declaringClass = $reflection
                    ->getMethod('failedValidation')
                    ->getDeclaringClass()
                    ->getName();

                if ($declaringClass !== FormRequest::class) {
                    return true;
                }
            } catch (\Throwable) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<string, array<string, mixed>>  $parsedFields
     * @return array<string, mixed>
     */
    protected function buildValidationErrorResponse(array $parsedFields): array
    {
        $firstKey = array_key_first($parsedFields);
        $field = is_string($firstKey) ? $this->exampleErrorField($firstKey) : 'field';
        $fieldInfo = is_string($firstKey) && is_array($parsedFields[$firstKey] ?? null)
            ? $parsedFields[$firstKey]
            : [];
        $message = $this->validationMessage($field, $fieldInfo);

        return [
            'source' => 'framework_validation',
            'status_code' => 422,
            'status_text' => 'Unprocessable Entity',
            'body' => [
                'message' => $message,
                'errors' => [
                    $field => [$message],
                ],
            ],
            'schema' => [
                'type' => 'object',
                'properties' => [
                    'message' => [
                        'type' => 'string',
                    ],
                    'errors' => [
                        'type' => 'object',
                        'additionalProperties' => [
                            'type' => 'array',
                            'items' => [
                                'type' => 'string',
                            ],
                        ],
                    ],
                ],
                'required' => ['message', 'errors'],
            ],
            'name' => 'Validation Error',
        ];
    }

    protected function exampleErrorField(string $field): string
    {
        $segments = explode('.', $field);

        foreach ($segments as &$segment) {
            if ($segment === '*') {
                $segment = '0';
            }
        }

        unset($segment);

        return implode('.', $segments);
    }

    /**
     * @param  array<string, mixed>  $fieldInfo
     */
    protected function validationMessage(string $field, array $fieldInfo): string
    {
        $label = str_replace(['.', '_'], ' ', $field);
        $rules = is_array($fieldInfo['rules'] ?? null) ? $fieldInfo['rules'] : [];

        if (in_array('required', $rules, true)) {
            return "The {$label} field is required.";
        }

        return "The {$label} field is invalid.";
    }
}

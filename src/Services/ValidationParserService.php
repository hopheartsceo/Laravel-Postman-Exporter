<?php

declare(strict_types=1);

namespace Hopheartsceo\PostmanExporter\Services;

use Hopheartsceo\PostmanExporter\Contracts\ValidationParserInterface;
use Hopheartsceo\PostmanExporter\Mappers\ValidationToJsonSchemaMapper;

/**
 * Parses Laravel validation rules into structured format.
 */
class ValidationParserService implements ValidationParserInterface
{
    public function __construct(
        protected ExampleDataGeneratorService $exampleGenerator,
        protected ?ValidationToJsonSchemaMapper $schemaMapper = null,
    ) {}

    /**
     * Parse validation rules array into structured field definitions.
     *
     * @param  array<string, mixed>  $rules  Laravel validation rules array
     * @return array<string, array{type: string, required: bool, rules: array, example: mixed}>
     */
    public function parse(array $rules): array
    {
        $parsed = [];

        foreach ($rules as $field => $fieldRules) {
            $normalizedRules = $this->normalizeRules($fieldRules);
            $type = $this->detectType($normalizedRules);
            $required = $this->isRequired($normalizedRules);
            $example = $this->exampleGenerator->generate($field, $normalizedRules);

            $parsed[$field] = [
                'type' => $type,
                'required' => $required,
                'rules' => $normalizedRules,
                'example' => $example,
            ];
        }

        return $parsed;
    }

    /**
     * Normalize rules to an array of strings.
     *
     * @param  mixed  $rules  Can be string (pipe-delimited) or array
     * @return array<int, string>
     */
    public function normalizeRules(mixed $rules): array
    {
        if (is_string($rules)) {
            return explode('|', $rules);
        }

        if (is_array($rules)) {
            $normalized = [];
            foreach ($rules as $rule) {
                if (is_string($rule)) {
                    // Handle pipe-delimited within array entries
                    if (str_contains($rule, '|')) {
                        $normalized = array_merge($normalized, explode('|', $rule));
                    } else {
                        $normalized[] = $rule;
                    }
                } elseif (is_object($rule)) {
                    // Rule objects — use class name
                    $normalized[] = class_basename($rule);
                }
            }
            return $normalized;
        }

        return [];
    }

    /**
     * Detect the primary data type from rules.
     */
    public function detectType(array $rules): string
    {
        $typeMap = [
            'integer' => 'integer',
            'int' => 'integer',
            'numeric' => 'number',
            'string' => 'string',
            'boolean' => 'boolean',
            'bool' => 'boolean',
            'array' => 'array',
            'file' => 'file',
            'image' => 'file',
            'email' => 'string',
            'url' => 'string',
            'uuid' => 'string',
            'date' => 'string',
            'json' => 'string',
        ];

        foreach ($rules as $rule) {
            if (is_string($rule)) {
                $ruleName = explode(':', $rule)[0];
                if (isset($typeMap[$ruleName])) {
                    return $typeMap[$ruleName];
                }
            }
        }

        return 'string';
    }

    /**
     * Check if the field is required.
     */
    public function isRequired(array $rules): bool
    {
        foreach ($rules as $rule) {
            if (is_string($rule)) {
                $ruleName = explode(':', $rule)[0];
                if ($ruleName === 'required') {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Build an example request body from parsed fields.
     *
     * @param  array<string, array{type: string, required: bool, rules: array, example: mixed}>  $parsedFields
     * @return array<string, mixed>
     */
    public function buildExampleBody(array $parsedFields): array
    {
        $body = [];

        foreach ($parsedFields as $field => $info) {
            // Handle nested fields like "address.street"
            $this->setNestedValue($body, $field, $info['example']);
        }

        return $body;
    }

    /**
     * Set a value in a nested array using dot notation.
     *
     * @param  array<string, mixed>  $array
     */
    protected function setNestedValue(array &$array, string $key, mixed $value): void
    {
        $keys = explode('.', $key);

        // Handle wildcard array notation (e.g., "items.*.name")
        if (in_array('*', $keys, true)) {
            $this->setWildcardValue($array, $keys, $value);
            return;
        }

        $current = &$array;
        foreach ($keys as $i => $segment) {
            if ($i === count($keys) - 1) {
                $current[$segment] = $value;
            } else {
                if (! isset($current[$segment]) || ! is_array($current[$segment])) {
                    $current[$segment] = [];
                }
                $current = &$current[$segment];
            }
        }
    }

    /**
     * Handle wildcard (.*.) notation in nested fields.
     *
     * @param  array<string, mixed>  $array
     * @param  array<int, string>    $keys
     */
    protected function setWildcardValue(array &$array, array $keys, mixed $value): void
    {
        $current = &$array;
        foreach ($keys as $i => $segment) {
            if ($segment === '*') {
                $remaining = array_slice($keys, $i + 1);

                if (count($remaining) === 0) {
                    // Replace generic array examples with one valid wildcard example.
                    $current = [$value];
                    return;
                }

                // Preserve an item already built by sibling wildcard fields,
                // otherwise replace generic parent examples with one object item.
                if (count($current) !== 1 || ! isset($current[0]) || ! is_array($current[0])) {
                    $current = [[]];
                }

                $this->setNestedValue($current[0], implode('.', $remaining), $value);

                return;
            }

            if ($i === count($keys) - 1) {
                $current[$segment] = $value;
            } else {
                if (! isset($current[$segment]) || ! is_array($current[$segment])) {
                    $current[$segment] = [];
                }
                $current = &$current[$segment];
            }
        }
    }

    /**
     * Convert parsed validation rules to JSON Schema format.
     *
     * @param  array<string, array{type: string, required: bool, rules: array, example: mixed}>  $parsedFields
     * @return array<string, mixed>
     */
    public function toJsonSchema(array $parsedFields): array
    {
        $mapper = $this->schemaMapper ?? new ValidationToJsonSchemaMapper();

        return $mapper->map($parsedFields);
    }
}

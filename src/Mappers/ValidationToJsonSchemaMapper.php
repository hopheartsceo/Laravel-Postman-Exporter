<?php

declare(strict_types=1);

namespace Hopheartsceo\PostmanExporter\Mappers;

/**
 * Maps Laravel validation rules to OpenAPI-compatible JSON Schema.
 */
class ValidationToJsonSchemaMapper
{
    /**
     * Map parsed validation rules to JSON Schema.
     *
     * @param  array<string, array{type: string, required: bool, rules: array, example: mixed}>  $parsedFields
     * @return array<string, mixed>
     */
    public function map(array $parsedFields): array
    {
        $properties = [];
        $required = [];

        $jsonTypeMap = [
            'string' => 'string',
            'integer' => 'integer',
            'number' => 'number',
            'boolean' => 'boolean',
            'array' => 'array',
            'file' => 'string',
        ];

        foreach ($parsedFields as $field => $info) {
            $jsonType = $jsonTypeMap[$info['type']] ?? 'string';

            $property = [
                'type' => $jsonType,
                'example' => $info['example'],
            ];

            if (($info['type'] ?? null) === 'file') {
                $property['format'] = 'binary';
            }

            // Extract constraints from rules
            foreach ($info['rules'] as $rule) {
                if (! is_string($rule)) {
                    continue;
                }

                if (str_starts_with($rule, 'max:') && ($info['type'] ?? null) !== 'file') {
                    $max = (int) substr($rule, 4);
                    if ($jsonType === 'string') {
                        $property['maxLength'] = $max;
                    } elseif ($jsonType === 'array') {
                        $property['maxItems'] = $max;
                    } else {
                        $property['maximum'] = $max;
                    }
                }

                if (str_starts_with($rule, 'min:') && ($info['type'] ?? null) !== 'file') {
                    $min = (int) substr($rule, 4);
                    if ($jsonType === 'string') {
                        $property['minLength'] = $min;
                    } elseif ($jsonType === 'array') {
                        $property['minItems'] = $min;
                    } else {
                        $property['minimum'] = $min;
                    }
                }

                if (str_starts_with($rule, 'in:')) {
                    $property['enum'] = explode(',', substr($rule, 3));
                }

                if ($rule === 'email') {
                    $property['format'] = 'email';
                }

                if ($rule === 'url') {
                    $property['format'] = 'uri';
                }

                if ($rule === 'uuid') {
                    $property['format'] = 'uuid';
                }

                if ($rule === 'date') {
                    $property['format'] = 'date';
                }

                if ($rule === 'ip' || $rule === 'ipv4') {
                    $property['format'] = 'ipv4';
                }

                if ($rule === 'ipv6') {
                    $property['format'] = 'ipv6';
                }

                if ($rule === 'nullable') {
                    $property['nullable'] = true;
                }
            }

            // Handle wildcard array items before regular dot-notation fields.
            if (str_ends_with($field, '.*')) {
                $parent = substr($field, 0, -2);

                if (! isset($properties[$parent])) {
                    $properties[$parent] = ['type' => 'array'];
                }

                $properties[$parent]['items'] = $property;
            } elseif (str_contains($field, '.')) {
                $segments = explode('.', $field);
                $this->nestProperty($properties, $segments, $property);
            } else {
                $properties[$field] = $property;
            }

            if ($info['required']) {
                $topLevelField = explode('.', $field)[0];
                if (! in_array($topLevelField, $required, true)) {
                    $required[] = $topLevelField;
                }
            }
        }

        $schema = [
            'type' => 'object',
            'properties' => $properties,
        ];

        if (! empty($required)) {
            $schema['required'] = $required;
        }

        return $schema;
    }

    /**
     * Nest a property in the properties array using segments.
     */
    protected function nestProperty(array &$current, array $segments, array $property): void
    {
        $segment = array_shift($segments);

        if ($segment === null) {
            return;
        }

        if ($segment === '*') {
            if (empty($segments)) {
                $current['items'] = $property;
                return;
            }

            if (! isset($current['items']) || ! is_array($current['items'])) {
                $current['items'] = [];
            }

            if (($current['items']['type'] ?? null) !== 'object') {
                $current['items'] = [
                    'type' => 'object',
                    'properties' => [],
                ];
            }

            if (! isset($current['items']['properties'])) {
                $current['items']['properties'] = [];
            }

            $this->nestProperty(
                $current['items']['properties'],
                $segments,
                $property
            );

            return;
        }

        if (empty($segments)) {
            $current[$segment] = $property;
            return;
        }

        $nextSegment = $segments[0];

        if (! isset($current[$segment]) || ! is_array($current[$segment])) {
            $current[$segment] = $nextSegment === '*'
                ? [
                    'type' => 'array',
                    'items' => [],
                ]
                : [
                    'type' => 'object',
                    'properties' => [],
                ];
        }

        if ($nextSegment === '*') {
            $current[$segment]['type'] = 'array';

            if (! isset($current[$segment]['items'])) {
                $current[$segment]['items'] = [];
            }

            $this->nestProperty(
                $current[$segment],
                $segments,
                $property
            );

            return;
        }

        if (! isset($current[$segment]['properties'])) {
            $current[$segment]['properties'] = [];
        }

        $this->nestProperty(
            $current[$segment]['properties'],
            $segments,
            $property
        );
    }
}

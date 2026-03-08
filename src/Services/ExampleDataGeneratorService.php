<?php

declare(strict_types=1);

namespace Hopheartsceo\PostmanExporter\Services;

use Hopheartsceo\PostmanExporter\Contracts\ExampleDataGeneratorInterface;

/**
 * Generates realistic example data based on validation rules.
 */
class ExampleDataGeneratorService implements ExampleDataGeneratorInterface

{
    /**
     * Type-to-value mapping for generating sample data.
     *
     * @var array<string, mixed>
     */
    protected array $typeMap = [
        'integer' => 1,
        'numeric' => 99.99,
        'string' => 'example',
        'boolean' => true,
        'email' => 'user@example.com',
        'url' => 'https://example.com',
        'uuid' => null, // generated dynamically
        'date' => '2025-01-15',
        'date_format' => '2025-01-15',
        'ip' => '192.168.1.1',
        'ipv4' => '192.168.1.1',
        'ipv6' => '::1',
        'json' => '{"key": "value"}',
        'array' => [],
        'file' => '(binary)',
        'image' => '(binary)',
        'alpha' => 'abcdef',
        'alpha_num' => 'abc123',
        'alpha_dash' => 'abc-123',
        'timezone' => 'UTC',
        'password' => 'password123',
        'confirmed' => 'password123',
    ];

    /**
     * Generate example value for a given field name and its validation rules.
     */
    public function generate(string $fieldName, array $rules): mixed
    {
        // Check for 'in:' rule first — use first allowed value
        foreach ($rules as $rule) {
            if (is_string($rule) && str_starts_with($rule, 'in:')) {
                $options = explode(',', substr($rule, 3));
                return $this->castValue($options[0], $rules);
            }
        }

        // Check for specific (non-generic) type-based rules first
        foreach ($rules as $rule) {
            if (is_string($rule) && array_key_exists($rule, $this->typeMap) && ! in_array($rule, ['string', 'alpha', 'alpha_num', 'alpha_dash'], true)) {
                if ($rule === 'uuid') {
                    return $this->generateUuid();
                }
                if ($rule === 'array') {
                    return $this->generateArrayExample($fieldName, $rules);
                }
                return $this->typeMap[$rule];
            }
        }

        // Try to infer from field name (gives better results than generic 'string' match)
        $inferred = $this->inferFromFieldName($fieldName);

        // If inference returned a non-default value, use it
        if ($inferred !== 'example') {
            return $inferred;
        }

        // Fall back to generic type map match (e.g., 'string' → 'example')
        foreach ($rules as $rule) {
            if (is_string($rule) && array_key_exists($rule, $this->typeMap)) {
                return $this->typeMap[$rule];
            }
        }

        return $inferred;
    }

    /**
     * Generate example value inferred from the field name.
     */
    public function inferFromFieldName(string $fieldName): mixed
    {
        $name = strtolower($fieldName);

        return match (true) {
            str_contains($name, 'email') => 'user@example.com',
            str_contains($name, 'password') => 'password123',
            str_contains($name, 'phone') || str_contains($name, 'mobile') => '+1234567890',
            str_contains($name, 'url') || str_contains($name, 'link') || str_contains($name, 'website') => 'https://example.com',
            str_contains($name, 'name') => 'John Doe',
            str_contains($name, 'title') => 'Sample Title',
            str_contains($name, 'description') || str_contains($name, 'body') || str_contains($name, 'content') => 'Lorem ipsum dolor sit amet.',
            str_contains($name, 'age') => 25,
            str_contains($name, 'price') || str_contains($name, 'amount') || str_contains($name, 'cost') => 29.99,
            str_contains($name, 'quantity') || str_contains($name, 'count') => 1,
            str_contains($name, 'date') || str_contains($name, '_at') => '2025-01-15',
            str_contains($name, 'time') => '14:30:00',
            str_contains($name, 'id') => 1,
            str_contains($name, 'uuid') => $this->generateUuid(),
            str_contains($name, 'status') => 'active',
            str_contains($name, 'type') => 'default',
            str_contains($name, 'is_') || str_contains($name, 'has_') => true,
            str_contains($name, 'image') || str_contains($name, 'avatar') || str_contains($name, 'photo') => 'https://example.com/image.jpg',
            str_contains($name, 'address') => '123 Main St',
            str_contains($name, 'city') => 'New York',
            str_contains($name, 'country') => 'US',
            str_contains($name, 'zip') || str_contains($name, 'postal') => '10001',
            str_contains($name, 'lat') => 40.7128,
            str_contains($name, 'lng') || str_contains($name, 'lon') => -74.0060,
            str_contains($name, 'color') || str_contains($name, 'colour') => '#FF5733',
            str_contains($name, 'token') => 'sample-token-value',
            str_contains($name, 'slug') => 'sample-slug',
            str_contains($name, 'sort') || str_contains($name, 'order') => 'asc',
            str_contains($name, 'page') => 1,
            str_contains($name, 'per_page') || str_contains($name, 'limit') => 15,
            default => 'example',
        };
    }

    /**
     * Generate a UUID v4 string.
     */
    protected function generateUuid(): string
    {
        // Simple UUID v4 without external dependency
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

    /**
     * Generate an example array value.
     *
     * @return array<int, mixed>
     */
    protected function generateArrayExample(string $fieldName, array $rules): array
    {
        return ['item1', 'item2'];
    }

    /**
     * Cast a string value based on other rules.
     */
    protected function castValue(string $value, array $rules): mixed
    {
        if (in_array('integer', $rules, true)) {
            return (int) $value;
        }

        if (in_array('numeric', $rules, true)) {
            return (float) $value;
        }

        if (in_array('boolean', $rules, true)) {
            return filter_var($value, FILTER_VALIDATE_BOOLEAN);
        }

        return $value;
    }

    /**
     * Generate example value for a route parameter.
     */
    public function generateForRouteParam(string $paramName): mixed
    {
        $name = strtolower($paramName);

        return match (true) {
            $name === 'id' || str_ends_with($name, '_id') => 1,
            str_contains($name, 'uuid') => $this->generateUuid(),
            str_contains($name, 'slug') => 'sample-slug',
            str_contains($name, 'token') => 'sample-token',
            default => 1,
        };
    }
}

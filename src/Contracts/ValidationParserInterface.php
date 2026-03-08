<?php

declare(strict_types=1);

namespace Wessaal\PostmanExporter\Contracts;

/**
 * Contract for validation parsing services.
 */
interface ValidationParserInterface
{
    /**
     * Parse validation rules array into structured field definitions.
     *
     * @param  array<string, mixed>  $rules
     * @return array<string, array{type: string, required: bool, rules: array, example: mixed}>
     */
    public function parse(array $rules): array;

    /**
     * Build an example request body from parsed fields.
     *
     * @param  array<string, array{type: string, required: bool, rules: array, example: mixed}>  $parsedFields
     * @return array<string, mixed>
     */
    public function buildExampleBody(array $parsedFields): array;

    /**
     * Convert parsed validation rules to JSON Schema format.
     *
     * @param  array<string, array{type: string, required: bool, rules: array, example: mixed}>  $parsedFields
     * @return array<string, mixed>
     */
    public function toJsonSchema(array $parsedFields): array;
}

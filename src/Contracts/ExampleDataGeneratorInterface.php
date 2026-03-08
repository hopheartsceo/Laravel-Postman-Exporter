<?php

declare(strict_types=1);

namespace Wessaal\PostmanExporter\Contracts;

/**
 * Contract for example data generation services.
 */
interface ExampleDataGeneratorInterface
{
    /**
     * Generate example value for a given field name and its validation rules.
     */
    public function generate(string $fieldName, array $rules): mixed;

    /**
     * Generate example value for a route parameter.
     */
    public function generateForRouteParam(string $paramName): mixed;
}

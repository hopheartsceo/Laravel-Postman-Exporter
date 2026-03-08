<?php

declare(strict_types=1);

namespace Hopheartsceo\PostmanExporter\Contracts;

/**
 * Contract for response extraction services.
 */
interface ResponseExtractorInterface
{
    /**
     * Extract response information from a controller method.
     *
     * @param  array<string, mixed>  $routeData
     * @return array<int, array<string, mixed>>
     */
    public function extract(array $routeData): array;
}

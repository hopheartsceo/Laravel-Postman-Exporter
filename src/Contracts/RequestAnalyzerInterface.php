<?php

declare(strict_types=1);

namespace Hopheartsceo\PostmanExporter\Contracts;

/**
 * Contract for request analysis services.
 */
interface RequestAnalyzerInterface
{
    /**
     * Analyze a scanned route and enrich it with request details.
     *
     * @param  array<string, mixed>  $routeData
     * @return array<string, mixed>
     */
    public function analyze(array $routeData): array;
}

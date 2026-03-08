<?php

declare(strict_types=1);

namespace Hopheartsceo\PostmanExporter\Contracts;

/**
 * Contract for folder organizing services.
 */
interface FolderOrganizerInterface
{
    /**
     * Organize routes into a hierarchical folder structure.
     *
     * @param  array<int, array<string, mixed>>  $routes
     * @param  callable  $requestBuilder  Callback to build a request item from route data
     * @return array<int, array<string, mixed>>
     */
    public function organize(array $routes, callable $requestBuilder): array;
}

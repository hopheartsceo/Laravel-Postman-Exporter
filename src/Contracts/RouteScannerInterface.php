<?php

declare(strict_types=1);

namespace Hopheartsceo\PostmanExporter\Contracts;

/**
 * Contract for route scanning services.
 */
interface RouteScannerInterface
{
    /**
     * Scan all routes and return structured route data.
     *
     * @return array<int, array<string, mixed>>
     */
    public function scan(): array;
}

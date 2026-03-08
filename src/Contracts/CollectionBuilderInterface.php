<?php

declare(strict_types=1);

namespace Hopheartsceo\PostmanExporter\Contracts;

/**
 * Contract for Postman collection builder services.
 */
interface CollectionBuilderInterface
{
    /**
     * Build the complete Postman Collection v2.1 structure.
     *
     * @param  array<int, array<string, mixed>>  $analyzedRoutes
     * @return array<string, mixed>
     */
    public function build(array $analyzedRoutes): array;
}

<?php

declare(strict_types=1);

namespace Hopheartsceo\PostmanExporter\Services;

use Hopheartsceo\PostmanExporter\Builders\OpenApiDocumentBuilder;
use Hopheartsceo\PostmanExporter\Contracts\CollectionBuilderInterface;

/**
 * Exporter service for OpenAPI 3.0 format.
 */
class OpenApiExporterService implements CollectionBuilderInterface
{
    public function __construct(
        protected OpenApiDocumentBuilder $builder,
    ) {}

    /**
     * Build the complete OpenAPI 3.0 structure.
     *
     * @param  array<int, array<string, mixed>>  $analyzedRoutes
     * @return array<string, mixed>
     */
    public function build(array $analyzedRoutes): array
    {
        return $this->builder->build($analyzedRoutes);
    }
}

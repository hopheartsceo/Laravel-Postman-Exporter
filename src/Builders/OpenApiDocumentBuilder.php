<?php

declare(strict_types=1);

namespace Hopheartsceo\PostmanExporter\Builders;

/**
 * Builds the top-level OpenAPI 3.0 document structure.
 */
class OpenApiDocumentBuilder
{
    public function __construct(
        protected OpenApiPathBuilder $pathBuilder,
        protected array $config,
    ) {}

    /**
     * Build the complete OpenAPI 3.0 document.
     *
     * @param  array<int, array<string, mixed>>  $routes
     * @return array<string, mixed>
     */
    public function build(array $routes): array
    {
        return [
            'openapi' => '3.0.3',
            'info' => [
                'title' => $this->config['collection_name'] ?? 'Laravel API',
                'description' => $this->config['collection_description'] ?? 'Auto-generated API documentation.',
                'version' => '1.0.0',
            ],
            'servers' => [
                [
                    'url' => $this->config['base_url'] ?? 'http://localhost',
                    'description' => 'Local server',
                ],
            ],
            'paths' => $this->pathBuilder->build($routes),
            'components' => [
                'schemas' => new \stdClass(), // Ensure empty object in JSON
                'securitySchemes' => $this->buildSecuritySchemes(),
            ],
        ];
    }

    /**
     * Build globally defined security schemes.
     */
    protected function buildSecuritySchemes(): array
    {
        return [
            'bearerAuth' => [
                'type' => 'http',
                'scheme' => 'bearer',
                'bearerFormat' => 'JWT',
            ],
        ];
    }
}

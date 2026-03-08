<?php

declare(strict_types=1);

namespace Wessaal\PostmanExporter;

use Wessaal\PostmanExporter\Services\PostmanCollectionBuilderService;
use Wessaal\PostmanExporter\Services\PostmanUploaderService;
use Wessaal\PostmanExporter\Services\RequestAnalyzerService;
use Wessaal\PostmanExporter\Services\RouteScannerService;

class PostmanExporterManager
{
    /**
     * The generated collection data.
     *
     * @var array<string, mixed>|null
     */
    protected ?array $collection = null;

    public function __construct(
        protected RouteScannerService $routeScanner,
        protected RequestAnalyzerService $requestAnalyzer,
        protected PostmanCollectionBuilderService $collectionBuilder,
        protected PostmanUploaderService $uploader,
        protected array $config,
    ) {}

    /**
     * Generate the Postman collection array.
     *
     * @return array<string, mixed>
     */
    public function generate(): array
    {
        $routes = $this->routeScanner->scan();

        $analyzedRoutes = [];
        foreach ($routes as $route) {
            $analyzedRoutes[] = $this->requestAnalyzer->analyze($route);
        }

        $this->collection = $this->collectionBuilder->build($analyzedRoutes);

        return $this->collection;
    }

    /**
     * Save the collection to a JSON file.
     */
    public function save(?string $path = null): string
    {
        if ($this->collection === null) {
            $this->generate();
        }

        $path = $path ?? $this->config['output_path'];

        $directory = dirname($path);
        if (! is_dir($directory)) {
            mkdir($directory, 0755, true);
        }

        file_put_contents(
            $path,
            json_encode($this->collection, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
        );

        return $path;
    }

    /**
     * Upload the collection to Postman.
     */
    public function upload(?string $apiKey = null): array
    {
        if ($this->collection === null) {
            $this->generate();
        }

        $apiKey = $apiKey ?? $this->config['postman_api_key'];

        return $this->uploader->upload($this->collection, $apiKey);
    }

    /**
     * Get the generated collection.
     *
     * @return array<string, mixed>|null
     */
    public function getCollection(): ?array
    {
        return $this->collection;
    }
}

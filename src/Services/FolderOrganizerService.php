<?php

declare(strict_types=1);

namespace Hopheartsceo\PostmanExporter\Services;

use Hopheartsceo\PostmanExporter\Contracts\FolderOrganizerInterface;

/**
 * Organizes routes into a hierarchical folder structure based on prefixes.
 */
class FolderOrganizerService implements FolderOrganizerInterface
{
    public function __construct(
        protected array $config,
    ) {}

    /**
     * Organize routes into a hierarchical folder structure.
     */
    public function organize(array $routes, callable $requestBuilder): array
    {
        $groupingConfig = $this->config['grouping'] ?? [];
        $enabled = $groupingConfig['enabled'] ?? true;
        
        if (!$enabled) {
            return array_map($requestBuilder, $routes);
        }

        $fallbackFolder = $groupingConfig['fallback_folder'] ?? 'General';
        $nestedFolders = $groupingConfig['nested_folders'] ?? true;

        $tree = [];

        foreach ($routes as $route) {
            $segments = $this->getFolderSegments($route, $fallbackFolder, $nestedFolders);
            $this->addToTree($tree, $segments, $requestBuilder($route));
        }

        return $this->formatTree($tree);
    }

    /**
     * Extract folder segments from route data.
     */
    protected function getFolderSegments(array $route, string $fallbackFolder, bool $nestedFolders): array
    {
        $prefix = $route['prefix'] ?? '';
        $prefix = trim($prefix, '/');

        if (empty($prefix)) {
            return [$fallbackFolder];
        }

        // Split into segments and clean up
        $segments = array_values(array_filter(explode('/', $prefix)));

        // Remove segments that look like parameters {id} or :id
        $cleanSegments = array_filter($segments, function ($segment) {
            return !preg_match('/^[\{:]\w+\??\}?$/', $segment);
        });

        if (empty($cleanSegments)) {
            return [$fallbackFolder];
        }

        if (!$nestedFolders) {
            return [implode(' / ', array_map([$this, 'humanize'], $cleanSegments))];
        }

        return array_map([$this, 'humanize'], array_values($cleanSegments));
    }

    /**
     * Add a request item to the folder tree.
     */
    protected function addToTree(array &$tree, array $segments, array $requestItem): void
    {
        $current = &$tree;

        foreach ($segments as $segment) {
            if (!isset($current['folders'][$segment])) {
                $current['folders'][$segment] = [
                    'folders' => [],
                    'items' => [],
                ];
            }
            $current = &$current['folders'][$segment];
        }

        $current['items'][] = $requestItem;
    }

    /**
     * Format the internal tree into Postman's recursive item structure.
     */
    protected function formatTree(array $tree): array
    {
        $output = [];

        // Add root items if any (shouldn't be any in this logic, but for safety)
        if (isset($tree['items'])) {
            foreach ($tree['items'] as $item) {
                $output[] = $item;
            }
        }

        // Process folders
        if (isset($tree['folders'])) {
            foreach ($tree['folders'] as $name => $node) {
                $folder = [
                    'name' => $name,
                    'item' => array_merge(
                        $this->formatTree($node),
                        $node['items']
                    ),
                    'description' => 'Routes for ' . $name,
                ];
                $output[] = $folder;
            }
        }

        return $output;
    }

    /**
     * Humanize a segment name (e.g., 'auth-api' -> 'Auth Api').
     */
    protected function humanize(string $segment): string
    {
        return ucfirst(str_replace(['-', '_'], ' ', $segment));
    }
}

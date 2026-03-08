<?php

declare(strict_types=1);

namespace Hopheartsceo\PostmanExporter\Services;

use Hopheartsceo\PostmanExporter\Contracts\FolderOrganizerInterface;

/**
 * Organizes routes into folder groups based on URI prefix.
 *
 * All routes sharing the same first URI prefix segment are grouped into one folder.
 * Routes without a prefix go into a configurable fallback folder (default: "general").
 * No nested folders are created — only flat, single-level grouping.
 */
class FolderOrganizerService implements FolderOrganizerInterface
{
    public function __construct(
        protected array $config,
    ) {}

    /**
     * Organize routes into a flat folder structure grouped by first URI prefix segment.
     *
     * @param  array<int, array<string, mixed>>  $routes
     * @param  callable  $requestBuilder
     * @return array<int, array<string, mixed>>
     */
    public function organize(array $routes, callable $requestBuilder): array
    {
        $groupingConfig = $this->config['grouping'] ?? [];
        $enabled = $groupingConfig['enabled'] ?? true;

        if (! $enabled) {
            return array_map($requestBuilder, $routes);
        }

        $fallbackFolder = $groupingConfig['fallback_folder'] ?? 'general';

        // Group routes by their first prefix segment
        $groups = [];

        foreach ($routes as $route) {
            $folderName = $this->resolveFolderName($route, $fallbackFolder);

            if (! isset($groups[$folderName])) {
                $groups[$folderName] = [];
            }

            $groups[$folderName][] = $requestBuilder($route);
        }

        // Convert groups to Postman folder format
        return $this->buildFolders($groups);
    }

    /**
     * Resolve the folder name for a route based on its first URI prefix segment.
     *
     * A route is considered "prefixed" only if it has more than one segment
     * (e.g. "api/users" → prefix "api"). Single-segment routes like "status"
     * have no prefix and go into the fallback folder.
     */
    protected function resolveFolderName(array $route, string $fallbackFolder): string
    {
        $uri = trim($route['uri'] ?? '', '/');

        if (empty($uri)) {
            return $fallbackFolder;
        }

        $segments = explode('/', $uri);

        // Single-segment routes have no prefix — use fallback
        if (count($segments) < 2) {
            return $fallbackFolder;
        }

        $firstSegment = $segments[0] ?? '';

        // If first segment is empty or looks like a parameter, use fallback
        if (empty($firstSegment) || preg_match('/^[\{:]/', $firstSegment)) {
            return $fallbackFolder;
        }

        return $firstSegment;
    }

    /**
     * Build Postman folder items from grouped routes.
     *
     * @param  array<string, array<int, array<string, mixed>>>  $groups
     * @return array<int, array<string, mixed>>
     */
    protected function buildFolders(array $groups): array
    {
        $folders = [];

        foreach ($groups as $name => $items) {
            $folders[] = [
                'name' => $name,
                'item' => $items,
                'description' => 'Routes for ' . $name,
            ];
        }

        return $folders;
    }
}

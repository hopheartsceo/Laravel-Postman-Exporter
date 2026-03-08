<?php

declare(strict_types=1);

namespace Hopheartsceo\PostmanExporter\Services;

use Hopheartsceo\PostmanExporter\Contracts\FolderOrganizerInterface;

/**
 * Organizes routes into folder groups based on URI prefix.
 *
 * Common boilerplate prefixes (like "api", "api/v1", "api/v2") are automatically
 * stripped so routes are grouped by the first *meaningful* segment — typically
 * the resource or domain name (e.g. "camps", "admin", "auth").
 *
 * Routes without a meaningful segment after stripping go into a configurable
 * fallback folder (default: "general"). No nested folders are created.
 */
class FolderOrganizerService implements FolderOrganizerInterface
{
    /**
     * Prefixes that should be stripped before resolving the folder name.
     * Longer prefixes are matched first so "api/v1" is stripped before "api".
     */
    protected const STRIP_PREFIXES = [
        'api/v1',
        'api/v2',
        'api/v3',
        'api',
    ];

    public function __construct(
        protected array $config,
    ) {}

    /**
     * Organize routes into a flat folder structure grouped by meaningful prefix.
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
        $stripPrefixes = $groupingConfig['strip_prefixes'] ?? self::STRIP_PREFIXES;

        // Group routes by their first meaningful segment
        $groups = [];

        foreach ($routes as $route) {
            $folderName = $this->resolveFolderName($route, $fallbackFolder, $stripPrefixes);

            if (! isset($groups[$folderName])) {
                $groups[$folderName] = [];
            }

            $groups[$folderName][] = $requestBuilder($route);
        }

        // Convert groups to Postman folder format
        return $this->buildFolders($groups);
    }

    /**
     * Resolve the folder name for a route.
     *
     * Steps:
     * 1. Strip known boilerplate prefixes (api, api/v1, etc.)
     * 2. Take the first segment of what remains as the folder name.
     * 3. If nothing meaningful remains, use the fallback folder.
     *
     * @param  array<string, mixed>  $route
     * @param  string[]  $stripPrefixes
     */
    protected function resolveFolderName(array $route, string $fallbackFolder, array $stripPrefixes): string
    {
        $uri = trim($route['uri'] ?? '', '/');

        if (empty($uri)) {
            return $fallbackFolder;
        }

        // Strip known boilerplate prefixes (longest match first)
        $stripped = $this->stripBoilerplatePrefixes($uri, $stripPrefixes);

        if (empty($stripped)) {
            return $fallbackFolder;
        }

        $segments = explode('/', $stripped);
        $firstSegment = $segments[0] ?? '';

        // If first segment is empty, a parameter, or the only segment left
        // and it IS a parameter, use fallback
        if (empty($firstSegment) || preg_match('/^[\{:]/', $firstSegment)) {
            return $fallbackFolder;
        }

        // Single-segment routes (after stripping) go to fallback only if
        // the segment is a parameter. Otherwise they form their own folder.
        // e.g. "api/v1/login" → stripped "login" → folder "auth" is tricky;
        // but "api/v1/camps" → stripped "camps" → folder "camps" ✓
        // For single-segment we still use the segment as a folder.
        return $firstSegment;
    }

    /**
     * Strip known boilerplate prefixes from a URI.
     *
     * @param  string[]  $prefixes  Sorted longest-first for greedy matching.
     */
    protected function stripBoilerplatePrefixes(string $uri, array $prefixes): string
    {
        // Sort by length descending so longer prefixes match first
        usort($prefixes, fn (string $a, string $b) => strlen($b) <=> strlen($a));

        foreach ($prefixes as $prefix) {
            $prefix = trim($prefix, '/');

            if ($prefix === '') {
                continue;
            }

            if (str_starts_with($uri, $prefix . '/')) {
                return trim(substr($uri, strlen($prefix)), '/');
            }

            // Exact match (URI IS the prefix with nothing after it)
            if ($uri === $prefix) {
                return '';
            }
        }

        return $uri;
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

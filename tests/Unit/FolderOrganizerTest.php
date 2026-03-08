<?php

declare(strict_types=1);

namespace Hopheartsceo\PostmanExporter\Tests\Unit;

use Hopheartsceo\PostmanExporter\Services\FolderOrganizerService;
use Hopheartsceo\PostmanExporter\Tests\TestCase;

class FolderOrganizerTest extends TestCase
{
    protected FolderOrganizerService $organizer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->organizer = new FolderOrganizerService([
            'grouping' => [
                'enabled' => true,
                'strategy' => 'prefix',
                'fallback_folder' => 'general',
                'strip_prefixes' => ['api/v1', 'api/v2', 'api/v3', 'api'],
            ],
        ]);
    }

    public function test_strips_api_prefix_and_groups_by_resource(): void
    {
        $routes = [
            ['uri' => 'api/v1/camps', 'method' => 'GET'],
            ['uri' => 'api/v1/camps/{camp}', 'method' => 'GET'],
            ['uri' => 'api/v1/admin/users', 'method' => 'GET'],
            ['uri' => 'api/v1/admin/need-types', 'method' => 'GET'],
        ];

        $requestBuilder = fn($route) => ['name' => $route['method'] . ' ' . $route['uri']];

        $result = $this->organizer->organize($routes, $requestBuilder);

        // Two folders: camps and admin
        $this->assertCount(2, $result);

        $folderNames = array_column($result, 'name');
        $this->assertContains('camps', $folderNames);
        $this->assertContains('admin', $folderNames);

        // Camps folder should have 2 items
        $campsFolder = $this->findFolder($result, 'camps');
        $this->assertCount(2, $campsFolder['item']);

        // Admin folder should have 2 items
        $adminFolder = $this->findFolder($result, 'admin');
        $this->assertCount(2, $adminFolder['item']);
    }

    public function test_non_api_prefixed_routes_group_by_first_segment(): void
    {
        $routes = [
            ['uri' => 'auth/login', 'method' => 'POST'],
            ['uri' => 'auth/logout', 'method' => 'POST'],
        ];

        $requestBuilder = fn($route) => ['name' => $route['method'] . ' ' . $route['uri']];

        $result = $this->organizer->organize($routes, $requestBuilder);

        $this->assertCount(1, $result);
        $this->assertEquals('auth', $result[0]['name']);
        $this->assertCount(2, $result[0]['item']);
    }

    public function test_single_segment_routes_go_to_own_folder(): void
    {
        $routes = [
            ['uri' => 'status', 'method' => 'GET'],
            ['uri' => 'health', 'method' => 'GET'],
        ];

        $requestBuilder = fn($route) => ['name' => $route['method'] . ' ' . $route['uri']];

        $result = $this->organizer->organize($routes, $requestBuilder);

        // Each single-segment route becomes its own folder
        $this->assertCount(2, $result);
        $folderNames = array_column($result, 'name');
        $this->assertContains('status', $folderNames);
        $this->assertContains('health', $folderNames);
    }

    public function test_route_that_is_only_stripped_prefix_goes_to_fallback(): void
    {
        $routes = [
            ['uri' => 'api/v1', 'method' => 'GET'],
            ['uri' => 'api', 'method' => 'GET'],
        ];

        $requestBuilder = fn($route) => ['name' => $route['uri']];

        $result = $this->organizer->organize($routes, $requestBuilder);

        // Both resolve to empty after stripping → fallback
        $this->assertCount(1, $result);
        $this->assertEquals('general', $result[0]['name']);
        $this->assertCount(2, $result[0]['item']);
    }

    public function test_no_root_level_requests(): void
    {
        $routes = [
            ['uri' => 'api/v1/users', 'method' => 'GET'],
            ['uri' => 'status', 'method' => 'GET'],
        ];

        $requestBuilder = fn($route) => ['name' => $route['uri']];

        $result = $this->organizer->organize($routes, $requestBuilder);

        // Every result item must be a folder (has 'item' key)
        foreach ($result as $item) {
            $this->assertArrayHasKey('item', $item, 'All items should be folders, no root-level requests');
        }
    }

    public function test_no_nested_folders(): void
    {
        $routes = [
            ['uri' => 'api/v1/admin/users', 'method' => 'GET'],
            ['uri' => 'api/v1/admin/need-types', 'method' => 'GET'],
        ];

        $requestBuilder = fn($route) => ['name' => $route['uri']];

        $result = $this->organizer->organize($routes, $requestBuilder);

        // Both under "admin" after stripping api/v1
        $this->assertCount(1, $result);
        $this->assertEquals('admin', $result[0]['name']);
        $this->assertCount(2, $result[0]['item']);

        // Items should be request items, NOT subfolders
        foreach ($result[0]['item'] as $item) {
            $this->assertArrayNotHasKey('item', $item, 'Should not have nested folders');
        }
    }

    public function test_flat_mode_when_grouping_disabled(): void
    {
        $this->organizer = new FolderOrganizerService([
            'grouping' => ['enabled' => false],
        ]);

        $routes = [
            ['uri' => 'api/v1/users', 'method' => 'GET'],
        ];

        $requestBuilder = fn($route) => ['name' => $route['uri']];

        $result = $this->organizer->organize($routes, $requestBuilder);

        $this->assertCount(1, $result);
        $this->assertEquals('api/v1/users', $result[0]['name']);
        $this->assertArrayNotHasKey('item', $result[0]);
    }

    public function test_custom_fallback_folder_name(): void
    {
        $this->organizer = new FolderOrganizerService([
            'grouping' => [
                'enabled' => true,
                'strategy' => 'prefix',
                'fallback_folder' => 'misc',
                'strip_prefixes' => ['api/v1', 'api'],
            ],
        ]);

        $routes = [
            ['uri' => 'api/v1', 'method' => 'GET'],
        ];

        $requestBuilder = fn($route) => ['name' => $route['uri']];

        $result = $this->organizer->organize($routes, $requestBuilder);

        $this->assertCount(1, $result);
        $this->assertEquals('misc', $result[0]['name']);
    }

    public function test_custom_strip_prefixes(): void
    {
        $this->organizer = new FolderOrganizerService([
            'grouping' => [
                'enabled' => true,
                'strategy' => 'prefix',
                'fallback_folder' => 'general',
                'strip_prefixes' => ['backend/api'],
            ],
        ]);

        $routes = [
            ['uri' => 'backend/api/users', 'method' => 'GET'],
            ['uri' => 'backend/api/posts', 'method' => 'GET'],
        ];

        $requestBuilder = fn($route) => ['name' => $route['uri']];

        $result = $this->organizer->organize($routes, $requestBuilder);

        $this->assertCount(2, $result);
        $folderNames = array_column($result, 'name');
        $this->assertContains('users', $folderNames);
        $this->assertContains('posts', $folderNames);
    }

    public function test_mixed_api_and_non_api_routes(): void
    {
        $routes = [
            ['uri' => 'api/v1/camps', 'method' => 'GET'],
            ['uri' => 'api/v1/admin/users', 'method' => 'GET'],
            ['uri' => 'api/v1/login', 'method' => 'POST'],
            ['uri' => 'ahmed/posts', 'method' => 'GET'],
        ];

        $requestBuilder = fn($route) => ['name' => $route['uri']];

        $result = $this->organizer->organize($routes, $requestBuilder);

        $folderNames = array_column($result, 'name');
        $this->assertContains('camps', $folderNames);
        $this->assertContains('admin', $folderNames);
        $this->assertContains('login', $folderNames);
        $this->assertContains('ahmed', $folderNames);
    }

    public function test_parameter_only_route_after_strip_goes_to_fallback(): void
    {
        $routes = [
            ['uri' => 'api/v1/{id}', 'method' => 'GET'],
        ];

        $requestBuilder = fn($route) => ['name' => $route['uri']];

        $result = $this->organizer->organize($routes, $requestBuilder);

        $this->assertCount(1, $result);
        $this->assertEquals('general', $result[0]['name']);
    }

    /**
     * Helper to find a folder by name.
     */
    protected function findFolder(array $folders, string $name): ?array
    {
        foreach ($folders as $folder) {
            if (($folder['name'] ?? null) === $name) {
                return $folder;
            }
        }
        return null;
    }
}

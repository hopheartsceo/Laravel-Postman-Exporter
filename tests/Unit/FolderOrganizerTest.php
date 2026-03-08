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
            ],
        ]);
    }

    public function test_groups_prefixed_routes_into_folders(): void
    {
        $routes = [
            ['uri' => 'auth/login', 'method' => 'POST'],
            ['uri' => 'auth/logout', 'method' => 'POST'],
            ['uri' => 'api/users', 'method' => 'GET'],
            ['uri' => 'api/posts', 'method' => 'GET'],
        ];

        $requestBuilder = fn($route) => ['name' => $route['method'] . ' ' . $route['uri']];

        $result = $this->organizer->organize($routes, $requestBuilder);

        // Two folders: auth and api
        $this->assertCount(2, $result);

        $folderNames = array_column($result, 'name');
        $this->assertContains('auth', $folderNames);
        $this->assertContains('api', $folderNames);

        // Auth folder should have 2 items
        $authFolder = $this->findFolder($result, 'auth');
        $this->assertCount(2, $authFolder['item']);

        // Api folder should have 2 items
        $apiFolder = $this->findFolder($result, 'api');
        $this->assertCount(2, $apiFolder['item']);
    }

    public function test_non_prefixed_routes_go_to_fallback_folder(): void
    {
        $routes = [
            ['uri' => 'status', 'method' => 'GET'],
            ['uri' => 'contact', 'method' => 'POST'],
        ];

        $requestBuilder = fn($route) => ['name' => $route['method'] . ' ' . $route['uri']];

        $result = $this->organizer->organize($routes, $requestBuilder);

        // Should have a single "general" folder
        $this->assertCount(1, $result);
        $this->assertEquals('general', $result[0]['name']);
        $this->assertCount(2, $result[0]['item']);
    }

    public function test_no_root_level_requests(): void
    {
        $routes = [
            ['uri' => 'api/users', 'method' => 'GET'],
            ['uri' => 'status', 'method' => 'GET'],
        ];

        $requestBuilder = fn($route) => ['name' => $route['uri']];

        $result = $this->organizer->organize($routes, $requestBuilder);

        // Every result item must be a folder (has 'item' key)
        foreach ($result as $item) {
            $this->assertArrayHasKey('item', $item, 'All items should be folders, no root-level requests');
        }
    }

    public function test_prefixed_and_non_prefixed_not_mixed(): void
    {
        $routes = [
            ['uri' => 'auth/login', 'method' => 'POST'],
            ['uri' => 'status', 'method' => 'GET'],
            ['uri' => 'auth/register', 'method' => 'POST'],
        ];

        $requestBuilder = fn($route) => ['name' => $route['uri']];

        $result = $this->organizer->organize($routes, $requestBuilder);

        // Should have 2 folders: auth and general
        $this->assertCount(2, $result);
        $folderNames = array_column($result, 'name');
        $this->assertContains('auth', $folderNames);
        $this->assertContains('general', $folderNames);

        // Auth folder should have 2 items
        $authFolder = $this->findFolder($result, 'auth');
        $this->assertCount(2, $authFolder['item']);

        // General folder should have 1 item
        $generalFolder = $this->findFolder($result, 'general');
        $this->assertCount(1, $generalFolder['item']);
    }

    public function test_no_nested_folders(): void
    {
        $routes = [
            ['uri' => 'api/v1/users', 'method' => 'GET'],
            ['uri' => 'api/v2/users', 'method' => 'GET'],
        ];

        $requestBuilder = fn($route) => ['name' => $route['uri']];

        $result = $this->organizer->organize($routes, $requestBuilder);

        // All routes share the same first prefix "api" → one flat folder
        $this->assertCount(1, $result);
        $this->assertEquals('api', $result[0]['name']);
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
            ['uri' => 'api/users', 'method' => 'GET'],
        ];

        $requestBuilder = fn($route) => ['name' => $route['uri']];

        $result = $this->organizer->organize($routes, $requestBuilder);

        $this->assertCount(1, $result);
        $this->assertEquals('api/users', $result[0]['name']);
        $this->assertArrayNotHasKey('item', $result[0]);
    }

    public function test_custom_fallback_folder_name(): void
    {
        $this->organizer = new FolderOrganizerService([
            'grouping' => [
                'enabled' => true,
                'strategy' => 'prefix',
                'fallback_folder' => 'misc',
            ],
        ]);

        $routes = [
            ['uri' => 'ping', 'method' => 'GET'],
        ];

        $requestBuilder = fn($route) => ['name' => $route['uri']];

        $result = $this->organizer->organize($routes, $requestBuilder);

        $this->assertCount(1, $result);
        $this->assertEquals('misc', $result[0]['name']);
    }

    public function test_single_segment_route_goes_to_fallback_folder(): void
    {
        $routes = [
            ['uri' => 'health', 'method' => 'GET'],
        ];

        $requestBuilder = fn($route) => ['name' => $route['uri']];

        $result = $this->organizer->organize($routes, $requestBuilder);

        // "health" is a single-segment URI with no prefix.
        // It should be placed under the fallback "general" folder.
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

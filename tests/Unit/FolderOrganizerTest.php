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
                'fallback_folder' => 'General',
                'nested_folders' => true,
            ]
        ]);
    }

    public function test_organizes_routes_into_single_level_folders()
    {
        $routes = [
            ['uri' => 'api/users', 'prefix' => 'api', 'method' => 'GET'],
            ['uri' => 'api/products', 'prefix' => 'api', 'method' => 'GET'],
            ['uri' => 'auth/login', 'prefix' => 'auth', 'method' => 'POST'],
        ];

        $requestBuilder = fn($route) => ['name' => $route['uri']];

        $result = $this->organizer->organize($routes, $requestBuilder);

        $this->assertCount(2, $result);
        $this->assertEquals('Api', $result[0]['name']);
        $this->assertCount(2, $result[0]['item']);
        $this->assertEquals('Auth', $result[1]['name']);
        $this->assertCount(1, $result[1]['item']);
    }

    public function test_organizes_routes_into_nested_folders()
    {
        $routes = [
            ['uri' => 'api/v1/users', 'prefix' => 'api/v1', 'method' => 'GET'],
            ['uri' => 'api/v1/products', 'prefix' => 'api/v1', 'method' => 'GET'],
            ['uri' => 'api/v2/users', 'prefix' => 'api/v2', 'method' => 'GET'],
        ];

        $requestBuilder = fn($route) => ['name' => $route['uri']];

        $result = $this->organizer->organize($routes, $requestBuilder);

        $this->assertCount(1, $result);
        $this->assertEquals('Api', $result[0]['name']);
        
        $apiV1 = $result[0]['item'][0];
        $this->assertEquals('V1', $apiV1['name']);
        $this->assertCount(2, $apiV1['item']);

        $apiV2 = $result[0]['item'][1];
        $this->assertEquals('V2', $apiV2['name']);
        $this->assertCount(1, $apiV2['item']);
    }

    public function test_handles_routes_without_prefix()
    {
        $routes = [
            ['uri' => 'ping', 'prefix' => null, 'method' => 'GET'],
        ];

        $requestBuilder = fn($route) => ['name' => $route['uri']];

        $result = $this->organizer->organize($routes, $requestBuilder);

        $this->assertCount(1, $result);
        $this->assertEquals('General', $result[0]['name']);
    }

    public function test_strips_parameters_from_folder_names()
    {
        $routes = [
            ['uri' => 'teams/{team}/users', 'prefix' => 'teams/{team}', 'method' => 'GET'],
        ];

        $requestBuilder = fn($route) => ['name' => $route['uri']];

        $result = $this->organizer->organize($routes, $requestBuilder);

        $this->assertCount(1, $result);
        $this->assertEquals('Teams', $result[0]['name']);
        $this->assertCount(1, $result[0]['item']);
    }

    public function test_flat_grouping_when_disabled()
    {
        $this->organizer = new FolderOrganizerService([
            'grouping' => ['enabled' => false]
        ]);

        $routes = [
            ['uri' => 'api/users', 'prefix' => 'api', 'method' => 'GET'],
        ];

        $requestBuilder = fn($route) => ['name' => $route['uri']];

        $result = $this->organizer->organize($routes, $requestBuilder);

        $this->assertCount(1, $result);
        $this->assertEquals('api/users', $result[0]['name']);
        $this->assertArrayNotHasKey('item', $result[0]);
    }

    public function test_flat_folders_when_nested_disabled()
    {
        $this->organizer = new FolderOrganizerService([
            'grouping' => [
                'enabled' => true,
                'nested_folders' => false,
            ]
        ]);

        $routes = [
            ['uri' => 'api/v1/users', 'prefix' => 'api/v1', 'method' => 'GET'],
        ];

        $requestBuilder = fn($route) => ['name' => $route['uri']];

        $result = $this->organizer->organize($routes, $requestBuilder);

        $this->assertCount(1, $result);
        $this->assertEquals('Api / V1', $result[0]['name']);
    }
}

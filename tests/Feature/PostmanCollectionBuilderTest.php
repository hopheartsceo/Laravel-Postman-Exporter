<?php

declare(strict_types=1);

namespace Hopheartsceo\PostmanExporter\Tests\Feature;

use Hopheartsceo\PostmanExporter\Services\PostmanCollectionBuilderService;
use Hopheartsceo\PostmanExporter\Services\FolderOrganizerService;
use Hopheartsceo\PostmanExporter\Tests\TestCase;

class PostmanCollectionBuilderTest extends TestCase
{
    protected PostmanCollectionBuilderService $builder;

    protected function setUp(): void
    {
        parent::setUp();
        $this->builder = $this->app->make(PostmanCollectionBuilderService::class);
    }

    protected function getSampleRoutes(): array
    {
        return [
            [
                'method' => 'GET',
                'methods' => ['GET'],
                'uri' => 'api/users',
                'name' => 'users.index',
                'action' => 'App\\Http\\Controllers\\UserController@index',
                'controller' => 'App\\Http\\Controllers\\UserController',
                'controller_method' => 'index',
                'middleware' => ['api', 'auth:sanctum'],
                'prefix' => 'api/users',
                'parameters' => [],
                'is_api' => true,
                'body_params' => [],
                'query_params' => [
                    'page' => ['value' => '1', 'description' => 'Optional (integer)'],
                    'per_page' => ['value' => '15', 'description' => 'Optional (integer)'],
                ],
                'path_params' => [],
                'auth_headers' => ['Authorization' => 'Bearer {{token}}'],
            ],
            [
                'method' => 'POST',
                'methods' => ['POST'],
                'uri' => 'api/users',
                'name' => 'users.store',
                'action' => 'App\\Http\\Controllers\\UserController@store',
                'controller' => 'App\\Http\\Controllers\\UserController',
                'controller_method' => 'store',
                'middleware' => ['api', 'auth:sanctum'],
                'prefix' => 'api/users',
                'parameters' => [],
                'is_api' => true,
                'body_params' => [
                    'name' => 'John Doe',
                    'email' => 'user@example.com',
                    'password' => 'password123',
                ],
                'query_params' => [],
                'path_params' => [],
                'auth_headers' => ['Authorization' => 'Bearer {{token}}'],
            ],
            [
                'method' => 'GET',
                'methods' => ['GET'],
                'uri' => 'api/users/{id}',
                'name' => 'users.show',
                'action' => 'App\\Http\\Controllers\\UserController@show',
                'controller' => 'App\\Http\\Controllers\\UserController',
                'controller_method' => 'show',
                'middleware' => ['api', 'auth:sanctum'],
                'prefix' => 'api/users',
                'parameters' => ['id'],
                'is_api' => true,
                'body_params' => [],
                'query_params' => [],
                'path_params' => ['id' => 1],
                'auth_headers' => ['Authorization' => 'Bearer {{token}}'],
            ],
            [
                'method' => 'GET',
                'methods' => ['GET'],
                'uri' => 'api/posts',
                'name' => 'posts.index',
                'action' => 'App\\Http\\Controllers\\PostController@index',
                'controller' => 'App\\Http\\Controllers\\PostController',
                'controller_method' => 'index',
                'middleware' => ['api'],
                'prefix' => 'api/posts',
                'parameters' => [],
                'is_api' => true,
                'body_params' => [],
                'query_params' => [],
                'path_params' => [],
                'auth_headers' => [],
            ],
        ];
    }

    public function test_builds_valid_collection_structure(): void
    {
        $collection = $this->builder->build($this->getSampleRoutes());

        $this->assertArrayHasKey('info', $collection);
        $this->assertArrayHasKey('item', $collection);
        $this->assertArrayHasKey('variable', $collection);
    }

    public function test_collection_info_has_required_fields(): void
    {
        $collection = $this->builder->build($this->getSampleRoutes());

        $info = $collection['info'];

        $this->assertArrayHasKey('name', $info);
        $this->assertArrayHasKey('schema', $info);
        $this->assertSame(
            'https://schema.getpostman.com/json/collection/v2.1.0/collection.json',
            $info['schema']
        );
    }

    public function test_collection_has_variables(): void
    {
        $collection = $this->builder->build($this->getSampleRoutes());

        $variables = $collection['variable'];
        $keys = array_column($variables, 'key');

        $this->assertContains('base_url', $keys);
        $this->assertContains('token', $keys);
    }

    public function test_routes_are_grouped_by_prefix(): void
    {
        $collection = $this->builder->build($this->getSampleRoutes());

        $items = $collection['item'];

        // With hierarchical grouping, we should have 1 top level folder: "Api"
        $this->assertCount(1, $items);
        $this->assertEquals('Api', $items[0]['name']);

        // Under "Api", we should have "Users" and "Posts"
        $apiFolder = $items[0]['item'];
        $folderNames = array_column($apiFolder, 'name');
        $this->assertContains('Users', $folderNames);
        $this->assertContains('Posts', $folderNames);
    }

    public function test_request_item_has_correct_structure(): void
    {
        $collection = $this->builder->build($this->getSampleRoutes());

        // Navigate to a request: Api -> Users -> GET users.index
        $apiFolder = $collection['item'][0];
        $usersFolder = $apiFolder['item'][0];
        $firstRequest = $usersFolder['item'][0];

        $this->assertArrayHasKey('name', $firstRequest);
        $this->assertArrayHasKey('request', $firstRequest);
        $this->assertArrayHasKey('method', $firstRequest['request']);
        $this->assertArrayHasKey('header', $firstRequest['request']);
        $this->assertArrayHasKey('url', $firstRequest['request']);
    }

    public function test_url_has_correct_structure(): void
    {
        $collection = $this->builder->build($this->getSampleRoutes());

        $apiFolder = $collection['item'][0];
        $usersFolder = $apiFolder['item'][0];
        $firstRequest = $usersFolder['item'][0];
        $url = $firstRequest['request']['url'];

        $this->assertArrayHasKey('raw', $url);
        $this->assertArrayHasKey('host', $url);
        $this->assertArrayHasKey('path', $url);
        $this->assertStringContainsString('{{base_url}}', $url['raw']);
    }

    public function test_post_request_has_body(): void
    {
        $collection = $this->builder->build($this->getSampleRoutes());

        // Find POST request recursively
        $postRequest = $this->findRequestByMethod($collection['item'], 'POST');

        $this->assertNotNull($postRequest);
        $this->assertArrayHasKey('body', $postRequest['request']);
        $this->assertSame('raw', $postRequest['request']['body']['mode']);
    }

    public function test_request_has_headers(): void
    {
        $collection = $this->builder->build($this->getSampleRoutes());

        $request = $this->findRequestByMethod($collection['item'], 'GET');
        $headers = $request['request']['header'];

        $this->assertNotEmpty($headers);

        $headerKeys = array_column($headers, 'key');
        $this->assertContains('Accept', $headerKeys);
        $this->assertContains('Content-Type', $headerKeys);
    }

    public function test_auth_headers_are_added(): void
    {
        $collection = $this->builder->build($this->getSampleRoutes());

        // Find a request in the users folder (which has auth)
        $request = $this->findRequestByName($collection['item'], 'users.index');
        $headers = $request['request']['header'];

        $headerKeys = array_column($headers, 'key');
        $this->assertContains('Authorization', $headerKeys);
    }

    public function test_path_variables_are_included(): void
    {
        $collection = $this->builder->build($this->getSampleRoutes());

        // Find route with {id} parameter
        $routeWithParam = $this->findRequestByName($collection['item'], 'users.show');

        $this->assertNotNull($routeWithParam);
        $variables = $routeWithParam['request']['url']['variable'];
        $this->assertSame('id', $variables[0]['key']);
    }

    public function test_query_params_are_included(): void
    {
        $collection = $this->builder->build($this->getSampleRoutes());

        // Find users.index which has query params
        $getRequest = $this->findRequestByName($collection['item'], 'users.index');

        $this->assertNotNull($getRequest);
        $queryKeys = array_column($getRequest['request']['url']['query'], 'key');
        $this->assertContains('page', $queryKeys);
        $this->assertContains('per_page', $queryKeys);
    }

    protected function findRequestByMethod(array $items, string $method): ?array
    {
        foreach ($items as $item) {
            if (isset($item['request']) && $item['request']['method'] === $method) {
                return $item;
            }
            if (isset($item['item'])) {
                $found = $this->findRequestByMethod($item['item'], $method);
                if ($found) return $found;
            }
        }
        return null;
    }

    protected function findRequestByName(array $items, string $name): ?array
    {
        foreach ($items as $item) {
            if (isset($item['request']) && $item['name'] === $name) {
                return $item;
            }
            if (isset($item['item'])) {
                $found = $this->findRequestByName($item['item'], $name);
                if ($found) return $found;
            }
        }
        return null;
    }

    public function test_flat_mode_no_folders(): void
    {
        $config = $this->app['config']->get('postman-exporter');
        $config['grouping']['enabled'] = false;
        $organizer = new FolderOrganizerService($config);
        $builder = new PostmanCollectionBuilderService($organizer, $config);

        $collection = $builder->build($this->getSampleRoutes());

        // Items should be flat (no nested 'item' arrays)
        foreach ($collection['item'] as $item) {
            $this->assertArrayHasKey('request', $item);
            $this->assertArrayNotHasKey('item', $item);
        }
    }

    public function test_generates_valid_json(): void
    {
        $collection = $this->builder->build($this->getSampleRoutes());

        $json = json_encode($collection, JSON_PRETTY_PRINT);
        $this->assertNotFalse($json);

        $decoded = json_decode($json, true);
        $this->assertIsArray($decoded);
        $this->assertSame($collection, $decoded);
    }
}

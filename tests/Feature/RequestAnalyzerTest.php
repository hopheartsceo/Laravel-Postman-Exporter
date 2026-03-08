<?php

declare(strict_types=1);

namespace Hopheartsceo\PostmanExporter\Tests\Feature;

use Hopheartsceo\PostmanExporter\Services\ExampleDataGeneratorService;
use Hopheartsceo\PostmanExporter\Services\RequestAnalyzerService;
use Hopheartsceo\PostmanExporter\Services\ValidationParserService;
use Hopheartsceo\PostmanExporter\Tests\TestCase;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Request;

class RequestAnalyzerTest extends TestCase
{
    protected RequestAnalyzerService $analyzer;

    protected function setUp(): void
    {
        parent::setUp();
        $exampleGenerator = new ExampleDataGeneratorService();
        $validationParser = new ValidationParserService($exampleGenerator);
        $this->analyzer = new RequestAnalyzerService($validationParser, $exampleGenerator);
    }

    public function test_analyze_adds_default_keys(): void
    {
        $routeData = [
            'method' => 'GET',
            'methods' => ['GET'],
            'uri' => 'api/users',
            'name' => 'users.index',
            'action' => 'App\\Http\\Controllers\\UserController@index',
            'controller' => null,
            'controller_method' => null,
            'middleware' => [],
            'prefix' => 'api/users',
            'parameters' => [],
            'is_api' => true,
        ];

        $result = $this->analyzer->analyze($routeData);

        $this->assertArrayHasKey('body_params', $result);
        $this->assertArrayHasKey('query_params', $result);
        $this->assertArrayHasKey('path_params', $result);
        $this->assertArrayHasKey('auth_headers', $result);
    }

    public function test_builds_path_params_with_examples(): void
    {
        $routeData = [
            'method' => 'GET',
            'methods' => ['GET'],
            'uri' => 'api/users/{id}',
            'name' => 'users.show',
            'action' => 'App\\Http\\Controllers\\UserController@show',
            'controller' => null,
            'controller_method' => null,
            'middleware' => [],
            'prefix' => 'api/users',
            'parameters' => ['id'],
            'is_api' => true,
        ];

        $result = $this->analyzer->analyze($routeData);

        $this->assertNotEmpty($result['path_params']);
        $this->assertArrayHasKey('id', $result['path_params']);
        $this->assertSame(1, $result['path_params']['id']);
    }

    public function test_detects_auth_headers_from_sanctum(): void
    {
        $routeData = [
            'method' => 'GET',
            'methods' => ['GET'],
            'uri' => 'api/profile',
            'name' => 'profile.show',
            'action' => 'App\\Http\\Controllers\\ProfileController@show',
            'controller' => null,
            'controller_method' => null,
            'middleware' => ['auth:sanctum'],
            'prefix' => 'api/profile',
            'parameters' => [],
            'is_api' => true,
        ];

        $result = $this->analyzer->analyze($routeData);

        $this->assertNotEmpty($result['auth_headers']);
        $this->assertArrayHasKey('Authorization', $result['auth_headers']);
        $this->assertStringContainsString('Bearer', $result['auth_headers']['Authorization']);
    }

    public function test_detects_auth_headers_from_api_guard(): void
    {
        $routeData = [
            'method' => 'GET',
            'methods' => ['GET'],
            'uri' => 'api/dashboard',
            'name' => 'dashboard',
            'action' => null,
            'controller' => null,
            'controller_method' => null,
            'middleware' => ['auth:api'],
            'prefix' => 'api/dashboard',
            'parameters' => [],
            'is_api' => true,
        ];

        $result = $this->analyzer->analyze($routeData);

        $this->assertArrayHasKey('Authorization', $result['auth_headers']);
    }

    public function test_no_auth_headers_without_auth_middleware(): void
    {
        $routeData = [
            'method' => 'GET',
            'methods' => ['GET'],
            'uri' => 'api/public',
            'name' => 'public.index',
            'action' => null,
            'controller' => null,
            'controller_method' => null,
            'middleware' => ['api'],
            'prefix' => 'api/public',
            'parameters' => [],
            'is_api' => true,
        ];

        $result = $this->analyzer->analyze($routeData);

        $this->assertEmpty($result['auth_headers']);
    }

    public function test_analyzes_controller_with_form_request(): void
    {
        $routeData = [
            'method' => 'POST',
            'methods' => ['POST'],
            'uri' => 'api/items',
            'name' => 'items.store',
            'action' => TestFormRequestController::class . '@store',
            'controller' => TestFormRequestController::class,
            'controller_method' => 'store',
            'middleware' => ['api'],
            'prefix' => 'api/items',
            'parameters' => [],
            'is_api' => true,
        ];

        $result = $this->analyzer->analyze($routeData);

        // FormRequest rules should be extracted and turned into body params
        $this->assertNotEmpty($result['body_params']);
        $this->assertArrayHasKey('title', $result['body_params']);
        $this->assertArrayHasKey('description', $result['body_params']);
    }

    public function test_multiple_path_params(): void
    {
        $routeData = [
            'method' => 'GET',
            'methods' => ['GET'],
            'uri' => 'api/users/{user_id}/posts/{post_id}',
            'name' => 'users.posts.show',
            'action' => null,
            'controller' => null,
            'controller_method' => null,
            'middleware' => [],
            'prefix' => 'api/users',
            'parameters' => ['user_id', 'post_id'],
            'is_api' => true,
        ];

        $result = $this->analyzer->analyze($routeData);

        $this->assertArrayHasKey('user_id', $result['path_params']);
        $this->assertArrayHasKey('post_id', $result['path_params']);
        $this->assertSame(1, $result['path_params']['user_id']);
        $this->assertSame(1, $result['path_params']['post_id']);
    }
}

/**
 * Dummy FormRequest for testing.
 */
class TestItemFormRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'title' => 'required|string|max:255',
            'description' => 'nullable|string',
            'price' => 'required|numeric|min:0',
            'status' => 'required|in:active,inactive',
        ];
    }
}

/**
 * Dummy controller using FormRequest.
 */
class TestFormRequestController
{
    public function store(TestItemFormRequest $request): array
    {
        return ['created' => true];
    }
}

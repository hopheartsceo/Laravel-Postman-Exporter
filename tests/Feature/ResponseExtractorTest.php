<?php

declare(strict_types=1);

namespace Hopheartsceo\PostmanExporter\Tests\Feature;

use Hopheartsceo\PostmanExporter\Services\ResponseExtractorService;
use Hopheartsceo\PostmanExporter\Tests\TestCase;

class ResponseExtractorTest extends TestCase
{
    protected ResponseExtractorService $extractor;

    protected function setUp(): void
    {
        parent::setUp();
        $this->extractor = new ResponseExtractorService();
    }

    public function test_extracts_phpdoc_response_annotation(): void
    {
        $routeData = [
            'controller' => TestPhpDocController::class,
            'controller_method' => 'index',
        ];

        $responses = $this->extractor->extract($routeData);

        $this->assertNotEmpty($responses);
        $this->assertEquals('phpdoc', $responses[0]['source']);
        $this->assertEquals(200, $responses[0]['status_code']);
        $this->assertArrayHasKey('status', $responses[0]['body']);
        $this->assertEquals('success', $responses[0]['body']['status']);
        $this->assertEquals('Users fetched', $responses[0]['body']['message']);
    }

    public function test_extracts_json_response(): void
    {
        $routeData = [
            'controller' => TestJsonResponseController::class,
            'controller_method' => 'store',
        ];

        $responses = $this->extractor->extract($routeData);

        $this->assertNotEmpty($responses);
        $this->assertEquals('json_response', $responses[0]['source']);
        $this->assertArrayHasKey('body', $responses[0]);
    }

    public function test_returns_fallback_for_controller_without_metadata(): void
    {
        $routeData = [
            'controller' => TestPlainController::class,
            'controller_method' => 'index',
        ];

        $responses = $this->extractor->extract($routeData);

        $this->assertNotEmpty($responses);
        $this->assertEquals('fallback', $responses[0]['source']);
        $this->assertEquals(200, $responses[0]['status_code']);
    }

    public function test_returns_fallback_for_missing_controller(): void
    {
        $routeData = [
            'controller' => 'NonExistentController',
            'controller_method' => 'index',
        ];

        $responses = $this->extractor->extract($routeData);

        $this->assertNotEmpty($responses);
        $this->assertEquals('fallback', $responses[0]['source']);
    }

    public function test_returns_fallback_for_null_controller(): void
    {
        $routeData = [
            'controller' => null,
            'controller_method' => null,
        ];

        $responses = $this->extractor->extract($routeData);

        $this->assertNotEmpty($responses);
        $this->assertEquals('fallback', $responses[0]['source']);
    }

    public function test_fallback_response_has_correct_structure(): void
    {
        $routeData = [
            'controller' => null,
            'controller_method' => null,
        ];

        $responses = $this->extractor->extract($routeData);
        $response = $responses[0];

        $this->assertArrayHasKey('source', $response);
        $this->assertArrayHasKey('status_code', $response);
        $this->assertArrayHasKey('status_text', $response);
        $this->assertArrayHasKey('body', $response);
        $this->assertArrayHasKey('name', $response);
    }

    public function test_phpdoc_with_status_code(): void
    {
        $routeData = [
            'controller' => TestPhpDocWithStatusController::class,
            'controller_method' => 'store',
        ];

        $responses = $this->extractor->extract($routeData);

        $this->assertNotEmpty($responses);
        $this->assertEquals('phpdoc', $responses[0]['source']);
        $this->assertEquals(201, $responses[0]['status_code']);
        $this->assertEquals('Created', $responses[0]['status_text']);
    }
}

/**
 * Dummy controller with PHPDoc @response annotation.
 */
class TestPhpDocController
{
    /**
     * Get all users.
     *
     * @response {
     *   "status": "success",
     *   "message": "Users fetched"
     * }
     */
    public function index(): array
    {
        return [];
    }
}

/**
 * Dummy controller with PHPDoc @response annotation and status code.
 */
class TestPhpDocWithStatusController
{
    /**
     * Create a user.
     *
     * @response 201 {
     *   "status": "success",
     *   "message": "User created"
     * }
     */
    public function store(): array
    {
        return [];
    }
}

/**
 * Dummy controller that returns response()->json().
 */
class TestJsonResponseController
{
    public function store()
    {
        return response()->json([
            'status' => 'success',
            'message' => 'Created successfully',
        ], 201);
    }
}

/**
 * Dummy plain controller with no metadata.
 */
class TestPlainController
{
    public function index()
    {
        return 'hello';
    }
}

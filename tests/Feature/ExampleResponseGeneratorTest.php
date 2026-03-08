<?php

declare(strict_types=1);

namespace Hopheartsceo\PostmanExporter\Tests\Feature;

use Hopheartsceo\PostmanExporter\Services\ExampleResponseGeneratorService;
use Hopheartsceo\PostmanExporter\Tests\TestCase;

class ExampleResponseGeneratorTest extends TestCase
{
    protected ExampleResponseGeneratorService $generator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->generator = new ExampleResponseGeneratorService(
            config('postman-exporter')
        );
    }

    public function test_generates_postman_response_format(): void
    {
        $responseData = [
            'source' => 'phpdoc',
            'status_code' => 200,
            'status_text' => 'OK',
            'body' => ['message' => 'Success'],
            'name' => 'Success Response',
        ];

        $originalRequest = [
            'method' => 'GET',
            'url' => ['raw' => '{{base_url}}/api/users'],
        ];

        $result = $this->generator->generate($responseData, $originalRequest);

        $this->assertArrayHasKey('name', $result);
        $this->assertArrayHasKey('originalRequest', $result);
        $this->assertArrayHasKey('status', $result);
        $this->assertArrayHasKey('code', $result);
        $this->assertArrayHasKey('_postman_previewlanguage', $result);
        $this->assertArrayHasKey('body', $result);
        $this->assertArrayHasKey('header', $result);

        $this->assertEquals('Success Response', $result['name']);
        $this->assertEquals('OK', $result['status']);
        $this->assertEquals(200, $result['code']);
        $this->assertEquals('json', $result['_postman_previewlanguage']);
    }

    public function test_body_is_json_encoded_string(): void
    {
        $responseData = [
            'source' => 'phpdoc',
            'status_code' => 200,
            'status_text' => 'OK',
            'body' => ['status' => 'success', 'data' => ['id' => 1]],
            'name' => 'Success Response',
        ];

        $result = $this->generator->generate($responseData, []);

        $this->assertIsString($result['body']);
        $decoded = json_decode($result['body'], true);
        $this->assertIsArray($decoded);
        $this->assertEquals('success', $decoded['status']);
    }

    public function test_generates_fallback_response(): void
    {
        $originalRequest = [
            'method' => 'GET',
            'url' => ['raw' => '{{base_url}}/api/status'],
        ];

        $result = $this->generator->generateFallback($originalRequest);

        $this->assertEquals('Success Response', $result['name']);
        $this->assertEquals(200, $result['code']);
        $this->assertNotEmpty($result['body']);

        $decoded = json_decode($result['body'], true);
        $this->assertEquals('Success', $decoded['message']);
    }

    public function test_generates_all_responses(): void
    {
        $extracted = [
            [
                'source' => 'phpdoc',
                'status_code' => 200,
                'status_text' => 'OK',
                'body' => ['message' => 'Found'],
                'name' => 'Success Response',
            ],
        ];

        $result = $this->generator->generateAll($extracted, []);

        $this->assertCount(1, $result);
        $this->assertEquals(200, $result[0]['code']);
    }

    public function test_generates_fallback_when_empty(): void
    {
        $result = $this->generator->generateAll([], []);

        $this->assertCount(1, $result);
        $this->assertEquals(200, $result[0]['code']);
    }

    public function test_preserves_custom_status_code(): void
    {
        $responseData = [
            'source' => 'json_response',
            'status_code' => 201,
            'status_text' => 'Created',
            'body' => ['message' => 'Resource created'],
            'name' => 'Created Response',
        ];

        $result = $this->generator->generate($responseData, []);

        $this->assertEquals(201, $result['code']);
        $this->assertEquals('Created', $result['status']);
        $this->assertEquals('Created Response', $result['name']);
    }

    public function test_response_header_contains_content_type(): void
    {
        $responseData = [
            'source' => 'fallback',
            'status_code' => 200,
            'status_text' => 'OK',
            'body' => ['message' => 'Success'],
            'name' => 'Success Response',
        ];

        $result = $this->generator->generate($responseData, []);

        $this->assertNotEmpty($result['header']);
        $headerKeys = array_column($result['header'], 'key');
        $this->assertContains('Content-Type', $headerKeys);
    }

    public function test_original_request_is_preserved(): void
    {
        $originalRequest = [
            'method' => 'POST',
            'url' => ['raw' => '{{base_url}}/api/users'],
            'header' => [['key' => 'Accept', 'value' => 'application/json']],
        ];

        $responseData = [
            'source' => 'fallback',
            'status_code' => 200,
            'status_text' => 'OK',
            'body' => ['message' => 'Success'],
            'name' => 'Success Response',
        ];

        $result = $this->generator->generate($responseData, $originalRequest);

        $this->assertEquals($originalRequest, $result['originalRequest']);
    }
}

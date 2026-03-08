<?php

declare(strict_types=1);

namespace Wessaal\PostmanExporter\Tests\Feature;

use Wessaal\PostmanExporter\Services\ExampleDataGeneratorService;
use Wessaal\PostmanExporter\Services\ValidationParserService;
use Wessaal\PostmanExporter\Tests\TestCase;

class ValidationParserTest extends TestCase
{
    protected ValidationParserService $parser;

    protected function setUp(): void
    {
        parent::setUp();
        $this->parser = new ValidationParserService(new ExampleDataGeneratorService());
    }

    public function test_parses_pipe_delimited_rules(): void
    {
        $rules = [
            'name' => 'required|string|max:255',
            'email' => 'required|email',
        ];

        $parsed = $this->parser->parse($rules);

        $this->assertArrayHasKey('name', $parsed);
        $this->assertArrayHasKey('email', $parsed);
        $this->assertTrue($parsed['name']['required']);
        $this->assertTrue($parsed['email']['required']);
        $this->assertSame('string', $parsed['name']['type']);
        $this->assertSame('string', $parsed['email']['type']);
    }

    public function test_parses_array_rules(): void
    {
        $rules = [
            'age' => ['required', 'integer', 'min:1', 'max:120'],
            'status' => ['required', 'in:active,inactive'],
        ];

        $parsed = $this->parser->parse($rules);

        $this->assertTrue($parsed['age']['required']);
        $this->assertSame('integer', $parsed['age']['type']);
        $this->assertSame(1, $parsed['age']['example']);
    }

    public function test_detects_optional_fields(): void
    {
        $rules = [
            'nickname' => 'nullable|string|max:100',
        ];

        $parsed = $this->parser->parse($rules);

        $this->assertFalse($parsed['nickname']['required']);
    }

    public function test_detects_boolean_type(): void
    {
        $rules = [
            'is_active' => 'boolean',
        ];

        $parsed = $this->parser->parse($rules);

        $this->assertSame('boolean', $parsed['is_active']['type']);
    }

    public function test_detects_array_type(): void
    {
        $rules = [
            'tags' => 'required|array',
        ];

        $parsed = $this->parser->parse($rules);

        $this->assertSame('array', $parsed['tags']['type']);
    }

    public function test_builds_example_body(): void
    {
        $rules = [
            'name' => 'required|string',
            'email' => 'required|email',
            'age' => 'integer',
        ];

        $parsed = $this->parser->parse($rules);
        $body = $this->parser->buildExampleBody($parsed);

        $this->assertIsArray($body);
        $this->assertArrayHasKey('name', $body);
        $this->assertArrayHasKey('email', $body);
        $this->assertArrayHasKey('age', $body);
    }

    public function test_builds_nested_example_body(): void
    {
        $rules = [
            'address.street' => 'required|string',
            'address.city' => 'required|string',
            'address.zip' => 'required|string',
        ];

        $parsed = $this->parser->parse($rules);
        $body = $this->parser->buildExampleBody($parsed);

        $this->assertIsArray($body);
        $this->assertArrayHasKey('address', $body);
        $this->assertIsArray($body['address']);
        $this->assertArrayHasKey('street', $body['address']);
        $this->assertArrayHasKey('city', $body['address']);
        $this->assertArrayHasKey('zip', $body['address']);
    }

    public function test_normalizes_pipe_in_array_rules(): void
    {
        $rules = ['required|string'];
        $normalized = $this->parser->normalizeRules($rules);

        $this->assertContains('required', $normalized);
        $this->assertContains('string', $normalized);
    }

    public function test_detects_file_type(): void
    {
        $rules = [
            'avatar' => 'required|image|max:2048',
        ];

        $parsed = $this->parser->parse($rules);

        $this->assertSame('file', $parsed['avatar']['type']);
    }

    public function test_detects_numeric_type(): void
    {
        $rules = [
            'price' => 'required|numeric|min:0',
        ];

        $parsed = $this->parser->parse($rules);

        $this->assertSame('number', $parsed['price']['type']);
    }

    public function test_to_json_schema(): void
    {
        $rules = [
            'title' => 'required|string|max:255',
            'email' => 'required|email|unique:users,email',
            'age' => 'nullable|integer|min:18',
            'settings.theme' => 'string|in:light,dark',
        ];

        $parsed = $this->parser->parse($rules);
        $schema = $this->parser->toJsonSchema($parsed);

        $this->assertEquals('object', $schema['type']);
        $this->assertArrayHasKey('properties', $schema);
        $this->assertContains('title', $schema['required']);
        $this->assertContains('email', $schema['required']);
        $this->assertNotContains('age', $schema['required']);

        $this->assertEquals('string', $schema['properties']['title']['type']);
        $this->assertEquals(255, $schema['properties']['title']['maxLength']);

        $this->assertEquals('string', $schema['properties']['email']['type']);
        $this->assertEquals('email', $schema['properties']['email']['format']);

        $this->assertEquals('integer', $schema['properties']['age']['type']);
        $this->assertEquals(18, $schema['properties']['age']['minimum']);
        $this->assertTrue($schema['properties']['age']['nullable']);

        $this->assertEquals('object', $schema['properties']['settings']['type']);
        $this->assertEquals('string', $schema['properties']['settings']['properties']['theme']['type']);
        $this->assertEquals(['light', 'dark'], $schema['properties']['settings']['properties']['theme']['enum']);
    }
}

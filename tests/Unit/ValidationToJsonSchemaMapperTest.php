<?php

declare(strict_types=1);

namespace Hopheartsceo\PostmanExporter\Tests\Unit;

use Hopheartsceo\PostmanExporter\Mappers\ValidationToJsonSchemaMapper;
use Hopheartsceo\PostmanExporter\Tests\TestCase;

class ValidationToJsonSchemaMapperTest extends TestCase
{
    protected ValidationToJsonSchemaMapper $mapper;

    protected function setUp(): void
    {
        parent::setUp();
        $this->mapper = new ValidationToJsonSchemaMapper();
    }

    public function test_maps_basic_types()
    {
        $parsed = [
            'name' => [
                'type' => 'string',
                'required' => true,
                'rules' => ['required', 'string'],
                'example' => 'John Doe',
            ],
            'age' => [
                'type' => 'integer',
                'required' => false,
                'rules' => ['integer'],
                'example' => 25,
            ],
        ];

        $schema = $this->mapper->map($parsed);

        $this->assertEquals('object', $schema['type']);
        $this->assertEquals('string', $schema['properties']['name']['type']);
        $this->assertEquals('integer', $schema['properties']['age']['type']);
        $this->assertContains('name', $schema['required']);
    }

    public function test_maps_constraints()
    {
        $parsed = [
            'email' => [
                'type' => 'string',
                'required' => true,
                'rules' => ['required', 'string', 'email', 'max:255'],
                'example' => 'test@example.com',
            ],
        ];

        $schema = $this->mapper->map($parsed);

        $this->assertEquals('email', $schema['properties']['email']['format']);
        $this->assertEquals(255, $schema['properties']['email']['maxLength']);
    }

    public function test_handles_nested_fields()
    {
        $parsed = [
            'user.name' => [
                'type' => 'string',
                'required' => true,
                'rules' => ['required', 'string'],
                'example' => 'John',
            ],
            'user.email' => [
                'type' => 'string',
                'required' => false,
                'rules' => ['string'],
                'example' => 'john@example.com',
            ],
        ];

        $schema = $this->mapper->map($parsed);

        $this->assertEquals('object', $schema['properties']['user']['type']);
        $this->assertArrayHasKey('name', $schema['properties']['user']['properties']);
        $this->assertArrayHasKey('email', $schema['properties']['user']['properties']);
    }

    public function test_maps_array_wildcard_items_and_array_constraints(): void
    {
        $schema = $this->mapper->map([
            'roles' => [
                'type' => 'array',
                'required' => true,
                'rules' => ['required', 'array', 'min:1'],
                'example' => ['admin'],
            ],
            'roles.*' => [
                'type' => 'string',
                'required' => true,
                'rules' => ['required', 'string', 'in:admin,editor,user'],
                'example' => 'admin',
            ],
        ]);

        $roles = $schema['properties']['roles'];

        $this->assertEquals('array', $roles['type']);
        $this->assertEquals(1, $roles['minItems']);
        $this->assertArrayNotHasKey('minimum', $roles);
        $this->assertArrayNotHasKey('roles.*', $schema['properties']);
        $this->assertEquals('string', $roles['items']['type']);
        $this->assertEquals(['admin', 'editor', 'user'], $roles['items']['enum']);
    }





    public function test_file_max_rule_does_not_become_string_length(): void
    {
        $schema = $this->mapper->map([
            'avatar' => [
                'type' => 'file',
                'required' => true,
                'rules' => ['required', 'image', 'max:2048'],
                'example' => '(binary)',
            ],
        ]);

        $avatar = $schema['properties']['avatar'];

        $this->assertSame('string', $avatar['type']);
        $this->assertSame('binary', $avatar['format']);
        $this->assertArrayNotHasKey('maxLength', $avatar);
        $this->assertArrayNotHasKey('maximum', $avatar);
    }

    public function test_maps_file_fields_as_binary_strings(): void
    {
        $schema = $this->mapper->map([
            'avatar' => [
                'type' => 'file',
                'required' => true,
                'rules' => ['required', 'image'],
                'example' => '(binary)',
            ],
        ]);

        $avatar = $schema['properties']['avatar'];

        $this->assertSame('string', $avatar['type']);
        $this->assertSame('binary', $avatar['format']);
    }

    public function test_maps_nested_array_wildcard_fields(): void
    {
        $schema = $this->mapper->map([
            'items' => [
                'type' => 'array',
                'required' => true,
                'rules' => ['required', 'array'],
                'example' => [],
            ],
            'items.*.name' => [
                'type' => 'string',
                'required' => true,
                'rules' => ['required', 'string'],
                'example' => 'Ahmed',
            ],
            'items.*.email' => [
                'type' => 'string',
                'required' => true,
                'rules' => ['required', 'email'],
                'example' => 'ahmed@example.com',
            ],
        ]);

        $items = $schema['properties']['items'];

        $this->assertEquals('array', $items['type']);
        $this->assertEquals('object', $items['items']['type']);
        $this->assertEquals('string', $items['items']['properties']['name']['type']);
        $this->assertEquals('string', $items['items']['properties']['email']['type']);
    }


}

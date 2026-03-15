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
}

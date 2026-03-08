<?php

declare(strict_types=1);

namespace Wessaal\PostmanExporter\Tests\Feature;

use Wessaal\PostmanExporter\Services\ExampleDataGeneratorService;
use Wessaal\PostmanExporter\Tests\TestCase;

class ExampleDataGeneratorTest extends TestCase
{
    protected ExampleDataGeneratorService $generator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->generator = new ExampleDataGeneratorService();
    }

    public function test_generates_integer_value(): void
    {
        $result = $this->generator->generate('count', ['required', 'integer']);
        $this->assertSame(1, $result);
    }

    public function test_generates_string_value(): void
    {
        $result = $this->generator->generate('name', ['required', 'string']);
        $this->assertSame('John Doe', $result); // inferred from field name
    }

    public function test_generates_email_value(): void
    {
        $result = $this->generator->generate('email', ['required', 'email']);
        $this->assertSame('user@example.com', $result);
    }

    public function test_generates_boolean_value(): void
    {
        $result = $this->generator->generate('active', ['boolean']);
        $this->assertSame(true, $result);
    }

    public function test_generates_uuid_value(): void
    {
        $result = $this->generator->generate('identifier', ['required', 'uuid']);
        $this->assertIsString($result);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/', $result);
    }

    public function test_generates_url_value(): void
    {
        $result = $this->generator->generate('website', ['url']);
        $this->assertSame('https://example.com', $result);
    }

    public function test_generates_date_value(): void
    {
        $result = $this->generator->generate('start_date', ['date']);
        $this->assertSame('2025-01-15', $result);
    }

    public function test_generates_numeric_value(): void
    {
        $result = $this->generator->generate('price', ['numeric']);
        $this->assertSame(99.99, $result);
    }

    public function test_generates_from_in_rule(): void
    {
        $result = $this->generator->generate('status', ['required', 'in:active,inactive,pending']);
        $this->assertSame('active', $result);
    }

    public function test_generates_array_value(): void
    {
        $result = $this->generator->generate('tags', ['array']);
        $this->assertIsArray($result);
    }

    public function test_infers_phone_from_name(): void
    {
        $result = $this->generator->generate('phone_number', ['string']);
        $this->assertSame('+1234567890', $result);
    }

    public function test_infers_password_from_name(): void
    {
        $result = $this->generator->generate('password', ['string']);
        $this->assertSame('password123', $result);
    }

    public function test_infers_price_from_name(): void
    {
        $result = $this->generator->generate('price', ['string']);
        $this->assertSame(29.99, $result);
    }

    public function test_generates_route_param_for_id(): void
    {
        $result = $this->generator->generateForRouteParam('id');
        $this->assertSame(1, $result);
    }

    public function test_generates_route_param_for_user_id(): void
    {
        $result = $this->generator->generateForRouteParam('user_id');
        $this->assertSame(1, $result);
    }

    public function test_generates_route_param_for_slug(): void
    {
        $result = $this->generator->generateForRouteParam('slug');
        $this->assertSame('sample-slug', $result);
    }

    public function test_generates_ip_value(): void
    {
        $result = $this->generator->generate('ip_address', ['ip']);
        $this->assertSame('192.168.1.1', $result);
    }

    public function test_generates_json_value(): void
    {
        $result = $this->generator->generate('metadata', ['json']);
        $this->assertSame('{"key": "value"}', $result);
    }
}

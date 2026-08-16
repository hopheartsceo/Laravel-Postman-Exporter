<?php

declare(strict_types=1);

namespace Hopheartsceo\PostmanExporter\Tests\Feature;

use Hopheartsceo\PostmanExporter\Services\ResourceSchemaAnalyzerService;
use Hopheartsceo\PostmanExporter\Tests\TestCase;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Http\Resources\Json\ResourceCollection;

class ResourceSchemaAnalyzerTest extends TestCase
{
    protected ResourceSchemaAnalyzerService $analyzer;

    protected function setUp(): void
    {
        parent::setUp();
        JsonResource::wrap('data');
        $this->analyzer = $this->app->make(ResourceSchemaAnalyzerService::class);
    }

    public function test_builds_response_schema_from_resource_structure(): void
    {
        $contract = $this->analyzer->analyzeResponse(UserResourceForContractTest::class);

        $this->assertNotNull($contract);
        $this->assertSame('object', $contract['schema']['type']);
        $this->assertSame(['data', 'meta'], $contract['schema']['required']);

        $data = $contract['schema']['properties']['data'];

        $this->assertSame('object', $data['type']);
        $this->assertSame(['id', 'name', 'profile'], $data['required']);
        $this->assertSame('integer', $data['properties']['id']['type']);
        $this->assertSame('email', $data['properties']['email']['format']);
        $this->assertNotContains('email', $data['required']);
        $this->assertSame('object', $data['properties']['profile']['type']);
        $this->assertSame('array', $data['properties']['posts']['type']);
        $this->assertSame('object', $data['properties']['posts']['items']['type']);
        $this->assertNotContains('posts', $data['required']);
        $this->assertNotContains('phone', $data['required']);
    }

    public function test_includes_top_level_resource_metadata(): void
    {
        $contract = $this->analyzer->analyzeResponse(UserResourceForContractTest::class);

        $this->assertNotNull($contract);
        $this->assertSame('object', $contract['schema']['properties']['meta']['type']);
        $this->assertSame('integer', $contract['schema']['properties']['meta']['properties']['version']['type']);
        $this->assertSame(1, $contract['example']['meta']['version']);
    }

    public function test_builds_collection_schema_from_resource_items(): void
    {
        $contract = $this->analyzer->analyzeResponse(UserResourceForContractTest::class, collection: true);

        $this->assertNotNull($contract);
        $data = $contract['schema']['properties']['data'];

        $this->assertSame('array', $data['type']);
        $this->assertSame('object', $data['items']['type']);
        $this->assertArrayHasKey('email', $data['items']['properties']);
    }

    public function test_builds_paginated_resource_contract(): void
    {
        $contract = $this->analyzer->analyzeResponse(
            UserResourceForContractTest::class,
            collection: true,
            paginated: true,
        );

        $this->assertNotNull($contract);
        $this->assertSame(['data', 'links', 'meta'], $contract['schema']['required']);
        $this->assertSame('array', $contract['schema']['properties']['data']['type']);
        $this->assertSame('object', $contract['schema']['properties']['links']['type']);
        $this->assertSame('object', $contract['schema']['properties']['meta']['type']);
    }

    public function test_analyzes_custom_resource_collection_shape(): void
    {
        $contract = $this->analyzer->analyzeResponse(UserCollectionForContractTest::class);

        $this->assertNotNull($contract);
        $this->assertSame('array', $contract['schema']['properties']['data']['type']);
        $this->assertSame('object', $contract['schema']['properties']['data']['items']['type']);
        $this->assertSame('integer', $contract['schema']['properties']['summary']['properties']['count']['type']);
    }
}

class UserResourceForContractTest extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => (int) $this->id,
            'name' => $this->name,
            'email' => $this->when($request->boolean('include_email'), $this->email),
            'profile' => new ProfileResourceForContractTest($this->profile),
            'posts' => PostResourceForContractTest::collection($this->whenLoaded('posts')),
            $this->mergeWhen($request->boolean('include_details'), [
                'phone' => $this->phone,
            ]),
        ];
    }

    public function with(Request $request): array
    {
        return [
            'meta' => [
                'version' => 1,
            ],
        ];
    }
}

class ProfileResourceForContractTest extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => (int) $this->id,
            'bio' => $this->bio,
        ];
    }
}

class PostResourceForContractTest extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => (int) $this->id,
            'title' => $this->title,
        ];
    }
}

class UserCollectionForContractTest extends ResourceCollection
{
    public $collects = UserResourceForContractTest::class;

    public function toArray(Request $request): array
    {
        return [
            'data' => $this->collection,
            'summary' => [
                'count' => 1,
            ],
        ];
    }
}

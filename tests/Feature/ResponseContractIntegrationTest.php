<?php

declare(strict_types=1);

namespace Hopheartsceo\PostmanExporter\Tests\Feature;

use Hopheartsceo\PostmanExporter\Builders\OpenApiPathBuilder;
use Hopheartsceo\PostmanExporter\Mappers\ValidationToJsonSchemaMapper;
use Hopheartsceo\PostmanExporter\Services\ContractDiffService;
use Hopheartsceo\PostmanExporter\Services\ResourceSchemaAnalyzerService;
use Hopheartsceo\PostmanExporter\Services\ResponseExtractorService;
use Hopheartsceo\PostmanExporter\Tests\TestCase;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ResponseContractIntegrationTest extends TestCase
{
    public function test_contract_guard_detects_removed_api_resource_field(): void
    {
        $analyzer = $this->app->make(ResourceSchemaAnalyzerService::class);
        $builder = new OpenApiPathBuilder(
            new ValidationToJsonSchemaMapper(),
            new ResponseExtractorService($analyzer),
        );

        $baseline = [
            'openapi' => '3.0.3',
            'paths' => $builder->build([
                $this->route(BaselineUserControllerForContractTest::class),
            ]),
        ];

        $current = [
            'openapi' => '3.0.3',
            'paths' => $builder->build([
                $this->route(CurrentUserControllerForContractTest::class),
            ]),
        ];

        $changes = (new ContractDiffService())->diff($baseline, $current);

        $this->assertContains(
            'GET /api/users/{user}: response 200 field removed: data.email',
            $changes,
        );
    }

    public function test_contract_guard_detects_resource_field_becoming_conditional(): void
    {
        $analyzer = $this->app->make(ResourceSchemaAnalyzerService::class);
        $builder = new OpenApiPathBuilder(
            new ValidationToJsonSchemaMapper(),
            new ResponseExtractorService($analyzer),
        );

        $baseline = [
            'openapi' => '3.0.3',
            'paths' => $builder->build([
                $this->route(BaselineUserControllerForContractTest::class),
            ]),
        ];

        $current = [
            'openapi' => '3.0.3',
            'paths' => $builder->build([
                $this->route(ConditionalUserControllerForContractTest::class),
            ]),
        ];

        $changes = (new ContractDiffService())->diff($baseline, $current);

        $this->assertContains(
            'GET /api/users/{user}: response 200 field became optional: data.email',
            $changes,
        );
    }

    /** @return array<string, mixed> */
    protected function route(string $controller): array
    {
        return [
            'method' => 'GET',
            'uri' => 'api/users/{user}',
            'name' => 'users.show',
            'controller' => $controller,
            'controller_method' => 'show',
            'parameters' => ['user'],
            'query_params' => [],
            'middleware' => ['api'],
        ];
    }
}

class BaselineUserControllerForContractTest
{
    public function show(): BaselineUserResourceForContractTest
    {
        return new BaselineUserResourceForContractTest((object) []);
    }
}

class CurrentUserControllerForContractTest
{
    public function show(): CurrentUserResourceForContractTest
    {
        return new CurrentUserResourceForContractTest((object) []);
    }
}

class ConditionalUserControllerForContractTest
{
    public function show(): ConditionalUserResourceForContractTest
    {
        return new ConditionalUserResourceForContractTest((object) []);
    }
}

class BaselineUserResourceForContractTest extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => (int) $this->id,
            'name' => $this->name,
            'email' => $this->email,
        ];
    }
}

class CurrentUserResourceForContractTest extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => (int) $this->id,
            'name' => $this->name,
        ];
    }
}


class ConditionalUserResourceForContractTest extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => (int) $this->id,
            'name' => $this->name,
            'email' => $this->when($request->boolean('include_email'), $this->email),
        ];
    }
}

<?php

declare(strict_types=1);

namespace Hopheartsceo\PostmanExporter\Tests\Feature;

use Hopheartsceo\PostmanExporter\Builders\OpenApiPathBuilder;
use Hopheartsceo\PostmanExporter\Services\ContractDiffService;
use Hopheartsceo\PostmanExporter\Tests\TestCase;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ResponsePipelineContractIntegrationTest extends TestCase
{
    public function test_contract_guard_detects_resource_field_removed_inside_response_helper(): void
    {
        $builder = $this->app->make(OpenApiPathBuilder::class);

        $baseline = [
            'openapi' => '3.0.3',
            'paths' => $builder->build([
                $this->route(PipelineBaselineController::class),
            ]),
        ];

        $current = [
            'openapi' => '3.0.3',
            'paths' => $builder->build([
                $this->route(PipelineCurrentController::class),
            ]),
        ];

        $changes = $this->app->make(ContractDiffService::class)->diff(
            $baseline,
            $current,
        );

        $this->assertContains(
            'GET /api/pipeline-users/{user}: response 200 field removed: data.email',
            $changes,
        );
    }

    public function test_contract_guard_uses_status_code_from_response_helper(): void
    {
        $builder = $this->app->make(OpenApiPathBuilder::class);

        $baseline = [
            'openapi' => '3.0.3',
            'paths' => $builder->build([
                $this->route(PipelineCreatedController::class),
            ]),
        ];

        $current = [
            'openapi' => '3.0.3',
            'paths' => $builder->build([
                $this->route(PipelineOkController::class),
            ]),
        ];

        $changes = $this->app->make(ContractDiffService::class)->diff(
            $baseline,
            $current,
        );

        $this->assertContains(
            'GET /api/pipeline-users/{user}: response status removed: 201',
            $changes,
        );
    }

    /** @return array<string, mixed> */
    protected function route(string $controller): array
    {
        return [
            'method' => 'GET',
            'uri' => 'api/pipeline-users/{user}',
            'name' => 'pipeline-users.show',
            'controller' => $controller,
            'controller_method' => 'show',
            'parameters' => ['user'],
            'query_params' => [],
            'middleware' => ['api'],
        ];
    }
}

trait PipelineContractResponder
{
    protected function pipelineResponse(mixed $data, int $status = 200)
    {
        return response()->json([
            'success' => true,
            'data' => $data,
        ], $status);
    }
}

class PipelineBaselineController
{
    use PipelineContractResponder;

    public function show()
    {
        return $this->pipelineResponse(
            new PipelineBaselineResource((object) []),
        );
    }
}

class PipelineCurrentController
{
    use PipelineContractResponder;

    public function show()
    {
        return $this->pipelineResponse(
            new PipelineCurrentResource((object) []),
        );
    }
}

class PipelineCreatedController
{
    use PipelineContractResponder;

    public function show()
    {
        return $this->pipelineResponse(
            new PipelineCurrentResource((object) []),
            201,
        );
    }
}

class PipelineOkController
{
    use PipelineContractResponder;

    public function show()
    {
        return $this->pipelineResponse(
            new PipelineCurrentResource((object) []),
            200,
        );
    }
}

class PipelineBaselineResource extends JsonResource
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

class PipelineCurrentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => (int) $this->id,
            'name' => $this->name,
        ];
    }
}

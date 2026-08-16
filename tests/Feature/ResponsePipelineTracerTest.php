<?php

declare(strict_types=1);

namespace Hopheartsceo\PostmanExporter\Tests\Feature;

use Hopheartsceo\PostmanExporter\Services\ResponseExtractorService;
use Hopheartsceo\PostmanExporter\Tests\TestCase;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

class ResponsePipelineTracerTest extends TestCase
{
    protected ResponseExtractorService $extractor;

    protected function setUp(): void
    {
        parent::setUp();

        $this->extractor = $this->app->make(ResponseExtractorService::class);
    }

    public function test_traces_trait_response_helper_with_resource_payload(): void
    {
        $response = $this->extract(PipelineTraitController::class, 'show');

        $this->assertSame('response_pipeline', $response['source']);
        $this->assertSame(200, $response['status_code']);
        $this->assertSame(true, $response['body']['success']);
        $this->assertSame('User fetched', $response['body']['message']);

        $data = $response['schema']['properties']['data'];

        $this->assertSame('object', $data['type']);
        $this->assertArrayHasKey('email', $data['properties']);
        $this->assertSame('email', $data['properties']['email']['format']);
        $this->assertArrayNotHasKey('data', $data['properties']);
    }

    public function test_traces_nested_helpers_and_status_code(): void
    {
        $response = $this->extract(PipelineNestedController::class, 'store');

        $this->assertSame('response_pipeline', $response['source']);
        $this->assertSame(201, $response['status_code']);
        $this->assertSame('Created', $response['body']['message']);
        $this->assertArrayHasKey('email', $response['schema']['properties']['data']['properties']);
    }

    public function test_traces_static_response_helper(): void
    {
        $response = $this->extract(PipelineStaticController::class, 'show');

        $this->assertSame('response_pipeline', $response['source']);
        $this->assertSame(202, $response['status_code']);
        $this->assertArrayHasKey('email', $response['schema']['properties']['payload']['properties']);
    }

    public function test_traces_helper_inherited_from_parent_controller(): void
    {
        $response = $this->extract(PipelineChildController::class, 'show');

        $this->assertSame('response_pipeline', $response['source']);
        $this->assertSame(200, $response['status_code']);
        $this->assertArrayHasKey('email', $response['schema']['properties']['data']['properties']);
    }

    public function test_tracks_simple_local_assignment_before_helper_call(): void
    {
        $response = $this->extract(PipelineAssignmentController::class, 'show');

        $this->assertSame('response_pipeline', $response['source']);
        $this->assertSame('Assigned resource', $response['body']['message']);
        $this->assertArrayHasKey('email', $response['schema']['properties']['data']['properties']);
    }

    public function test_supports_named_helper_arguments(): void
    {
        $response = $this->extract(PipelineNamedArgumentsController::class, 'store');

        $this->assertSame(201, $response['status_code']);
        $this->assertSame('Named created', $response['body']['message']);
        $this->assertArrayHasKey('email', $response['schema']['properties']['data']['properties']);
    }

    public function test_supports_named_arguments_on_terminal_json_response(): void
    {
        $response = $this->extract(PipelineNamedTerminalController::class, 'store');

        $this->assertSame('response_pipeline', $response['source']);
        $this->assertSame(201, $response['status_code']);
        $this->assertTrue($response['body']['success']);
        $this->assertArrayHasKey(
            'email',
            $response['schema']['properties']['data']['properties'],
        );
    }

    public function test_resolves_http_status_class_constants(): void
    {
        $response = $this->extract(PipelineStatusConstantController::class, 'store');

        $this->assertSame('response_pipeline', $response['source']);
        $this->assertSame(201, $response['status_code']);
        $this->assertArrayHasKey(
            'email',
            $response['schema']['properties']['data']['properties'],
        );
    }

    /** @return array<string, mixed> */
    protected function extract(string $controller, string $method): array
    {
        $responses = $this->extractor->extract([
            'controller' => $controller,
            'controller_method' => $method,
        ]);

        $this->assertNotEmpty($responses);

        return $responses[0];
    }
}

trait PipelineRespondsWithJson
{
    protected function successResponse(
        mixed $data,
        string $message = 'Success',
        int $status = 200,
    ) {
        return response()->json([
            'success' => true,
            'message' => $message,
            'data' => $data,
        ], $status);
    }

    protected function createdResponse(mixed $data)
    {
        return $this->successResponse($data, 'Created', 201);
    }
}

class PipelineTraitController
{
    use PipelineRespondsWithJson;

    public function show()
    {
        return $this->successResponse(
            new PipelineUserResource((object) []),
            'User fetched',
        );
    }
}

class PipelineNestedController
{
    use PipelineRespondsWithJson;

    public function store()
    {
        return $this->createdResponse(
            new PipelineUserResource((object) []),
        );
    }
}

class PipelineStaticController
{
    public function show()
    {
        return PipelineStaticResponder::success(
            new PipelineUserResource((object) []),
            202,
        );
    }
}

class PipelineStaticResponder
{
    public static function success(mixed $data, int $status = 200)
    {
        return response()->json([
            'ok' => true,
            'payload' => $data,
        ], $status);
    }
}

class PipelineBaseController
{
    protected function respondWithData(mixed $data)
    {
        return response()->json([
            'data' => $data,
        ]);
    }
}

class PipelineChildController extends PipelineBaseController
{
    public function show()
    {
        return $this->respondWithData(
            new PipelineUserResource((object) []),
        );
    }
}

class PipelineAssignmentController
{
    use PipelineRespondsWithJson;

    public function show()
    {
        $resource = new PipelineUserResource((object) []);

        return $this->successResponse($resource, 'Assigned resource');
    }
}

class PipelineNamedArgumentsController
{
    use PipelineRespondsWithJson;

    public function store()
    {
        return $this->successResponse(
            status: 201,
            data: new PipelineUserResource((object) []),
            message: 'Named created',
        );
    }
}

class PipelineNamedTerminalController
{
    public function store()
    {
        return PipelineNamedTerminalResponder::created(
            new PipelineUserResource((object) []),
        );
    }
}

class PipelineNamedTerminalResponder
{
    public static function created(mixed $data)
    {
        return response()->json(
            status: 201,
            data: [
                'success' => true,
                'data' => $data,
            ],
        );
    }
}

class PipelineStatusConstantController
{
    public function store()
    {
        return PipelineStatusConstantResponder::created(
            new PipelineUserResource((object) []),
        );
    }
}

class PipelineStatusConstantResponder
{
    public static function created(mixed $data)
    {
        return response()->json([
            'data' => $data,
        ], HttpResponse::HTTP_CREATED);
    }
}

class PipelineUserResource extends JsonResource
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

<?php

declare(strict_types=1);

namespace Hopheartsceo\PostmanExporter\Tests\Feature;

use Hopheartsceo\PostmanExporter\Builders\OpenApiPathBuilder;
use Hopheartsceo\PostmanExporter\Services\ContractDiffService;
use Hopheartsceo\PostmanExporter\Services\RequestAnalyzerService;
use Hopheartsceo\PostmanExporter\Services\ResponseExtractorService;
use Hopheartsceo\PostmanExporter\Tests\TestCase;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;

class MultiResponseContractTest extends TestCase
{
    public function test_extracts_multiple_phpdoc_response_annotations(): void
    {
        $responses = $this->app->make(ResponseExtractorService::class)->extract([
            'controller' => MultiPhpDocController::class,
            'controller_method' => 'show',
        ]);

        $this->assertSame([200, 404], $this->statuses($responses));
        $this->assertSame('phpdoc', $responses[0]['source']);
        $this->assertSame('phpdoc', $responses[1]['source']);
    }

    public function test_traces_multiple_explicit_response_branches(): void
    {
        $responses = $this->app->make(ResponseExtractorService::class)->extract([
            'controller' => MultiBranchController::class,
            'controller_method' => 'show',
        ]);

        $statuses = $this->statuses($responses);
        sort($statuses);

        $this->assertSame([200, 404], $statuses);
    }

    public function test_preserves_assignment_state_at_each_response_branch(): void
    {
        $responses = $this->app->make(ResponseExtractorService::class)->extract([
            'controller' => AssignmentOrderController::class,
            'controller_method' => 'show',
        ]);

        $notFound = $this->responseByStatus($responses, 404);
        $success = $this->responseByStatus($responses, 200);

        $this->assertSame('first@example.com', $notFound['body']['data']['email']);
        $this->assertSame('second@example.com', $success['body']['data']['email']);
    }

    public function test_ignores_return_statements_inside_closures(): void
    {
        $responses = $this->app->make(ResponseExtractorService::class)->extract([
            'controller' => ClosureReturnController::class,
            'controller_method' => 'show',
        ]);

        $this->assertSame([200], $this->statuses($responses));
    }

    public function test_traces_ternary_response_branches(): void
    {
        $responses = $this->app->make(ResponseExtractorService::class)->extract([
            'controller' => TernaryResponseController::class,
            'controller_method' => 'show',
        ]);

        $statuses = $this->statuses($responses);
        sort($statuses);

        $this->assertSame([200, 404], $statuses);
    }

    public function test_adds_default_laravel_validation_422_contract(): void
    {
        $route = $this->analyzeRoute(ValidatedCreateController::class, 'store');

        $responses = $this->app->make(ResponseExtractorService::class)->extract($route);
        $statuses = $this->statuses($responses);
        sort($statuses);

        $this->assertSame([201, 422], $statuses);

        $validation = $this->responseByStatus($responses, 422);

        $this->assertSame('framework_validation', $validation['source']);
        $this->assertSame('object', $validation['schema']['properties']['errors']['type']);
        $this->assertSame(
            'array',
            $validation['schema']['properties']['errors']['additionalProperties']['type'],
        );
    }

    public function test_skips_default_422_when_form_request_customizes_failed_validation(): void
    {
        $route = $this->analyzeRoute(CustomValidationController::class, 'store');

        $responses = $this->app->make(ResponseExtractorService::class)->extract($route);

        $this->assertSame([201], $this->statuses($responses));
    }

    public function test_contract_guard_detects_removed_validation_status(): void
    {
        $builder = $this->app->make(OpenApiPathBuilder::class);

        $baseline = [
            'openapi' => '3.0.3',
            'paths' => $builder->build([
                $this->analyzeRoute(ValidatedCreateController::class, 'store'),
            ]),
        ];

        $current = [
            'openapi' => '3.0.3',
            'paths' => $builder->build([
                $this->analyzeRoute(UnvalidatedCreateController::class, 'store'),
            ]),
        ];

        $changes = $this->app->make(ContractDiffService::class)->diff(
            $baseline,
            $current,
        );

        $this->assertContains(
            'POST /api/multi-response-users: response status removed: 422',
            $changes,
        );
    }

    /** @return array<string, mixed> */
    protected function analyzeRoute(string $controller, string $method): array
    {
        return $this->app->make(RequestAnalyzerService::class)->analyze([
            'method' => 'POST',
            'uri' => 'api/multi-response-users',
            'name' => 'multi-response-users.store',
            'controller' => $controller,
            'controller_method' => $method,
            'parameters' => [],
            'middleware' => ['api'],
        ]);
    }

    /**
     * @param  array<int, array<string, mixed>>  $responses
     * @return array<int, int>
     */
    protected function statuses(array $responses): array
    {
        return array_values(array_map(
            static fn (array $response): int => (int) $response['status_code'],
            $responses,
        ));
    }

    /**
     * @param  array<int, array<string, mixed>>  $responses
     * @return array<string, mixed>
     */
    protected function responseByStatus(array $responses, int $status): array
    {
        foreach ($responses as $response) {
            if ((int) $response['status_code'] === $status) {
                return $response;
            }
        }

        $this->fail("Response status {$status} was not found.");
    }
}

class MultiPhpDocController
{
    /**
     * @response 200 {
     *   "data": {
     *     "id": 1,
     *     "name": "Ahmed"
     *   }
     * }
     *
     * @response 404 {
     *   "message": "User not found"
     * }
     */
    public function show()
    {
        return null;
    }
}

class MultiBranchController
{
    public function show(bool $exists = true)
    {
        if (! $exists) {
            return response()->json([
                'message' => 'User not found',
            ], 404);
        }

        return response()->json([
            'data' => [
                'id' => 1,
            ],
        ], 200);
    }
}

class AssignmentOrderController
{
    public function show(bool $missing = true)
    {
        $data = [
            'email' => 'first@example.com',
        ];

        if ($missing) {
            return response()->json([
                'data' => $data,
            ], 404);
        }

        $data = [
            'email' => 'second@example.com',
        ];

        return response()->json([
            'data' => $data,
        ], 200);
    }
}

class ClosureReturnController
{
    public function show()
    {
        $callback = function () {
            return response()->json([
                'message' => 'Internal callback only',
            ], 500);
        };

        return response()->json([
            'data' => [
                'id' => 1,
            ],
        ], 200);
    }
}

class TernaryResponseController
{
    public function show(bool $exists = true)
    {
        return $exists
            ? response()->json(['id' => 1], 200)
            : response()->json(['message' => 'Not found'], 404);
    }
}

class ValidatedCreateController
{
    public function store(MultiResponseCreateRequest $request)
    {
        return response()->json([
            'data' => [
                'id' => 1,
                'email' => $request->input('email'),
            ],
        ], 201);
    }
}

class CustomValidationController
{
    public function store(MultiResponseCustomRequest $request)
    {
        return response()->json([
            'data' => [
                'id' => 1,
            ],
        ], 201);
    }
}

class UnvalidatedCreateController
{
    public function store()
    {
        return response()->json([
            'data' => [
                'id' => 1,
            ],
        ], 201);
    }
}

class MultiResponseCreateRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'email' => ['required', 'email'],
            'profile.name' => ['nullable', 'string'],
        ];
    }
}

class MultiResponseCustomRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'email' => ['required', 'email'],
        ];
    }

    protected function failedValidation(Validator $validator): void
    {
        throw new HttpResponseException(
            response()->json([
                'message' => 'Custom validation failure',
            ], 400),
        );
    }
}

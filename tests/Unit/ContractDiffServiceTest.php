<?php

declare(strict_types=1);

namespace Hopheartsceo\PostmanExporter\Tests\Unit;

use Hopheartsceo\PostmanExporter\Services\ContractDiffService;
use PHPUnit\Framework\TestCase;

class ContractDiffServiceTest extends TestCase
{
    protected ContractDiffService $diff;

    protected function setUp(): void
    {
        parent::setUp();
        $this->diff = new ContractDiffService();
    }

    public function test_detects_removed_endpoint(): void
    {
        $baseline = $this->specWithOperation();
        $current = ['openapi' => '3.0.3', 'paths' => []];

        $changes = $this->diff->diff($baseline, $current);

        $this->assertContains('POST /api/users: endpoint removed', $changes);
    }

    public function test_detects_new_required_request_field(): void
    {
        $baseline = $this->specWithOperation(['name'], ['name' => ['type' => 'string']]);
        $current = $this->specWithOperation(
            ['name', 'company_id'],
            ['name' => ['type' => 'string'], 'company_id' => ['type' => 'integer']]
        );

        $changes = $this->diff->diff($baseline, $current);

        $this->assertContains('POST /api/users: required request field added: company_id', $changes);
    }

    public function test_detects_removed_response_field(): void
    {
        $baseline = $this->specWithOperation(
            responseProperties: ['id' => ['type' => 'integer'], 'name' => ['type' => 'string']]
        );
        $current = $this->specWithOperation(
            responseProperties: ['id' => ['type' => 'integer']]
        );

        $changes = $this->diff->diff($baseline, $current);

        $this->assertContains('POST /api/users: response 200 field removed: name', $changes);
    }

    public function test_detects_response_type_change(): void
    {
        $baseline = $this->specWithOperation(responseProperties: ['id' => ['type' => 'integer']]);
        $current = $this->specWithOperation(responseProperties: ['id' => ['type' => 'string']]);

        $changes = $this->diff->diff($baseline, $current);

        $this->assertContains('POST /api/users: response 200 type changed for id: integer -> string', $changes);
    }

    public function test_additive_optional_response_field_is_not_breaking(): void
    {
        $baseline = $this->specWithOperation(responseProperties: ['id' => ['type' => 'integer']]);
        $current = $this->specWithOperation(
            responseProperties: ['id' => ['type' => 'integer'], 'name' => ['type' => 'string']]
        );

        $this->assertSame([], $this->diff->diff($baseline, $current));
    }

    /**
     * @param  array<int, string>  $required
     * @param  array<string, mixed>  $requestProperties
     * @param  array<string, mixed>  $responseProperties
     * @return array<string, mixed>
     */
    protected function specWithOperation(
        array $required = ['name'],
        array $requestProperties = ['name' => ['type' => 'string']],
        array $responseProperties = ['id' => ['type' => 'integer']],
    ): array {
        return [
            'openapi' => '3.0.3',
            'paths' => [
                '/api/users' => [
                    'post' => [
                        'parameters' => [],
                        'requestBody' => [
                            'content' => [
                                'application/json' => [
                                    'schema' => [
                                        'type' => 'object',
                                        'properties' => $requestProperties,
                                        'required' => $required,
                                    ],
                                ],
                            ],
                        ],
                        'responses' => [
                            '200' => [
                                'description' => 'OK',
                                'content' => [
                                    'application/json' => [
                                        'schema' => [
                                            'type' => 'object',
                                            'properties' => $responseProperties,
                                        ],
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ];
    }
}

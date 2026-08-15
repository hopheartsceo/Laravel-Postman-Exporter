<?php

declare(strict_types=1);

namespace Hopheartsceo\PostmanExporter\Tests\Feature;

use Hopheartsceo\PostmanExporter\PostmanExporterManager;
use Hopheartsceo\PostmanExporter\Tests\TestCase;

class ContractCheckCommandTest extends TestCase
{
    public function test_command_passes_when_contract_has_no_breaking_changes(): void
    {
        $spec = $this->spec(['id' => ['type' => 'integer']]);
        $baseline = tempnam(sys_get_temp_dir(), 'openapi-baseline-');
        file_put_contents($baseline, json_encode($spec));

        $manager = $this->createMock(PostmanExporterManager::class);
        $manager->expects($this->once())->method('generate')->with('openapi')->willReturn($spec);
        $this->app->instance(PostmanExporterManager::class, $manager);

        $this->artisan('postman:check', ['--against' => $baseline])
            ->expectsOutputToContain('No breaking API changes detected.')
            ->assertExitCode(0);

        @unlink($baseline);
    }

    public function test_command_fails_when_breaking_changes_are_detected(): void
    {
        $baselineSpec = $this->spec([
            'id' => ['type' => 'integer'],
            'name' => ['type' => 'string'],
        ]);
        $currentSpec = $this->spec(['id' => ['type' => 'integer']]);
        $baseline = tempnam(sys_get_temp_dir(), 'openapi-baseline-');
        file_put_contents($baseline, json_encode($baselineSpec));

        $manager = $this->createMock(PostmanExporterManager::class);
        $manager->expects($this->once())->method('generate')->with('openapi')->willReturn($currentSpec);
        $this->app->instance(PostmanExporterManager::class, $manager);

        $this->artisan('postman:check', ['--against' => $baseline])
            ->expectsOutputToContain('breaking API change')
            ->expectsOutputToContain('response 200 field removed: name')
            ->assertExitCode(1);

        @unlink($baseline);
    }

    public function test_command_can_write_a_baseline(): void
    {
        $spec = $this->spec(['id' => ['type' => 'integer']]);
        $directory = sys_get_temp_dir() . '/postman-contract-' . uniqid();
        $baseline = $directory . '/openapi.json';

        $manager = $this->createMock(PostmanExporterManager::class);
        $manager->expects($this->once())->method('generate')->with('openapi')->willReturn($spec);
        $this->app->instance(PostmanExporterManager::class, $manager);

        $this->artisan('postman:check', [
            '--against' => $baseline,
            '--write-baseline' => true,
        ])->assertExitCode(0);

        $this->assertFileExists($baseline);
        $this->assertSame($spec, json_decode((string) file_get_contents($baseline), true));

        @unlink($baseline);
        @rmdir($directory);
    }

    /** @return array<string, mixed> */
    protected function spec(array $responseProperties): array
    {
        return [
            'openapi' => '3.0.3',
            'paths' => [
                '/api/users' => [
                    'get' => [
                        'parameters' => [],
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

<?php

declare(strict_types=1);

namespace Hopheartsceo\PostmanExporter\Tests\Feature;

use Hopheartsceo\PostmanExporter\Tests\TestCase;

class ExportCommandTest extends TestCase
{
    protected function defineRoutes($router): void
    {
        $router->middleware('api')->prefix('api')->group(function ($router) {
            $router->get('users', [TestCommandController::class, 'index'])->name('users.index');
            $router->post('users', [TestCommandController::class, 'store'])->name('users.store');
            $router->get('users/{id}', [TestCommandController::class, 'show'])->name('users.show');
        });
    }

    public function test_command_executes_successfully(): void
    {
        $outputPath = sys_get_temp_dir() . '/postman-cmd-test-' . uniqid() . '.json';

        $this->artisan('postman:export', ['--output' => $outputPath])
            ->assertExitCode(0);

        if (file_exists($outputPath)) {
            unlink($outputPath);
        }
    }

    public function test_command_generates_json_file(): void
    {
        $outputPath = sys_get_temp_dir() . '/postman-cmd-test-' . uniqid() . '.json';

        $this->artisan('postman:export', ['--output' => $outputPath]);

        $this->assertFileExists($outputPath);

        $content = file_get_contents($outputPath);
        $this->assertNotFalse($content);

        $collection = json_decode($content, true);
        $this->assertIsArray($collection);
        $this->assertArrayHasKey('info', $collection);
        $this->assertArrayHasKey('item', $collection);

        unlink($outputPath);
    }

    public function test_command_with_group_by_prefix(): void
    {
        $outputPath = sys_get_temp_dir() . '/postman-cmd-test-' . uniqid() . '.json';

        $this->artisan('postman:export', [
            '--output' => $outputPath,
            '--group-by-prefix' => true,
        ])->assertExitCode(0);

        $content = file_get_contents($outputPath);
        $collection = json_decode($content, true);

        // Should have folders
        $this->assertNotEmpty($collection['item']);

        unlink($outputPath);
    }

    public function test_command_output_is_valid_postman_schema(): void
    {
        $outputPath = sys_get_temp_dir() . '/postman-cmd-test-' . uniqid() . '.json';

        $this->artisan('postman:export', ['--output' => $outputPath]);

        $content = file_get_contents($outputPath);
        $collection = json_decode($content, true);

        // Validate v2.1 schema
        $this->assertSame(
            'https://schema.getpostman.com/json/collection/v2.1.0/collection.json',
            $collection['info']['schema']
        );

        $this->assertArrayHasKey('variable', $collection);

        unlink($outputPath);
    }
}

/**
 * Dummy controller for command tests.
 */
class TestCommandController
{
    public function index(): array
    {
        return ['data' => []];
    }

    public function store(): array
    {
        return ['created' => true];
    }

    public function show(int $id): array
    {
        return ['id' => $id];
    }
}

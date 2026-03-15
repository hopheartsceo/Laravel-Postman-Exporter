<?php

declare(strict_types=1);

namespace Hopheartsceo\PostmanExporter\Tests\Feature;

use Hopheartsceo\PostmanExporter\PostmanExporterManager;
use Hopheartsceo\PostmanExporter\Tests\TestCase;
use cebe\openapi\Reader;

class OpenApiExportTest extends TestCase
{
    protected function defineRoutes($router): void
    {
        $router->middleware('api')->prefix('api')->group(function ($router) {
            $router->get('users', [OpenApiTestController::class, 'index'])->name('users.index');
            $router->post('users', [OpenApiTestController::class, 'store'])->name('users.store');
        });
    }

    public function test_generates_valid_openapi_spec()
    {
        /** @var PostmanExporterManager $manager */
        $manager = $this->app->make(PostmanExporterManager::class);
        
        $spec = $manager->generate('openapi');

        $this->assertEquals('3.0.3', $spec['openapi']);
        $this->assertArrayHasKey('/api/users', $spec['paths']);
        $this->assertArrayHasKey('get', $spec['paths']['/api/users']);
        $this->assertArrayHasKey('post', $spec['paths']['/api/users']);
    }

    public function test_output_passes_openapi_validation()
    {
        /** @var PostmanExporterManager $manager */
        $manager = $this->app->make(PostmanExporterManager::class);
        
        $spec = $manager->generate('openapi');
        $json = json_encode($spec);

        // Use cebe/php-openapi for validation
        $openapi = Reader::readFromJson($json);
        
        $this->assertTrue($openapi->validate(), "OpenAPI spec validation failed: " . json_encode($openapi->getErrors()));
        $this->assertEquals('3.0.3', $openapi->openapi);
    }

    public function test_command_exports_openapi_format()
    {
        $outputPath = sys_get_temp_dir() . '/openapi-test-' . uniqid() . '.json';

        $this->artisan('postman:export', [
            '--format' => 'openapi',
            '--output' => $outputPath,
        ])->assertExitCode(0);

        $this->assertFileExists($outputPath);
        
        $content = file_get_contents($outputPath);
        $spec = json_decode($content, true);

        $this->assertEquals('3.0.3', $spec['openapi']);
        
        unlink($outputPath);
    }
}

class OpenApiTestController
{
    public function index() { return []; }
    public function store(\Illuminate\Http\Request $request) 
    {
        $request->validate([
            'name' => 'required|string|max:100',
            'email' => 'required|email|unique:users',
        ]);
        return [];
    }
}

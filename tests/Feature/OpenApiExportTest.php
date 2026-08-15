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
            $router->delete('users/{user}', [OpenApiTestController::class, 'destroy'])->name('users.destroy');
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


    public function test_uses_controller_response_status_codes(): void
    {
        $manager = $this->app->make(PostmanExporterManager::class);

        $spec = $manager->generate('openapi');

        $this->assertArrayHasKey('201', $spec['paths']['/api/users']['post']['responses']);
        $this->assertArrayNotHasKey('200', $spec['paths']['/api/users']['post']['responses']);

        $this->assertArrayHasKey('204', $spec['paths']['/api/users/{user}']['delete']['responses']);
        $this->assertArrayNotHasKey(
            'content',
            $spec['paths']['/api/users/{user}']['delete']['responses']['204']
        );
    }


    public function test_infers_response_schema_from_controller_json_response(): void
    {
        $manager = $this->app->make(PostmanExporterManager::class);

        $spec = $manager->generate('openapi');

        $response = $spec['paths']['/api/users']['post']['responses']['201'];
        $json = $response['content']['application/json'];

        $this->assertEquals('object', $json['schema']['type']);
        $this->assertEquals('string', $json['schema']['properties']['message']['type']);
        $this->assertEquals('integer', $json['schema']['properties']['id']['type']);
        $this->assertSame([
            'message' => 'User created',
            'id' => 1,
        ], $json['example']);
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

    public function test_bearer_security_scheme_does_not_assume_jwt_format(): void
    {
        $manager = $this->app->make(PostmanExporterManager::class);

        $spec = $manager->generate('openapi');
        $bearer = $spec['components']['securitySchemes']['bearerAuth'];

        $this->assertEquals('http', $bearer['type']);
        $this->assertEquals('bearer', $bearer['scheme']);
        $this->assertArrayNotHasKey('bearerFormat', $bearer);
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
        return response()->json([
            'message' => 'User created',
            'id' => 1,
        ], 201);
    }

    public function destroy(string $user)
    {
        return response()->json(null, 204);
    }
}

<?php

declare(strict_types=1);

namespace Hopheartsceo\PostmanExporter\Tests\Feature;

use Hopheartsceo\PostmanExporter\Services\RouteScannerService;
use Hopheartsceo\PostmanExporter\Tests\TestCase;
use Illuminate\Support\Facades\Route;

class RouteScannerTest extends TestCase
{
    protected function defineRoutes($router): void
    {
        $router->middleware('api')->prefix('api')->group(function ($router) {
            $router->get('users', [TestDummyController::class, 'index'])->name('users.index');
            $router->post('users', [TestDummyController::class, 'store'])->name('users.store');
            $router->get('users/{id}', [TestDummyController::class, 'show'])->name('users.show');
            $router->put('users/{id}', [TestDummyController::class, 'update'])->name('users.update');
            $router->delete('users/{id}', [TestDummyController::class, 'destroy'])->name('users.destroy');

            $router->get('posts', [TestDummyController::class, 'index'])->name('posts.index');
            $router->post('posts', [TestDummyController::class, 'store'])->name('posts.store');
        });
    }

    public function test_scans_api_routes(): void
    {
        $scanner = $this->app->make(RouteScannerService::class);
        $routes = $scanner->scan();

        $this->assertNotEmpty($routes);
    }

    public function test_extracts_http_methods(): void
    {
        $scanner = $this->app->make(RouteScannerService::class);
        $routes = $scanner->scan();

        $methods = array_column($routes, 'method');

        $this->assertContains('GET', $methods);
        $this->assertContains('POST', $methods);
        $this->assertContains('PUT', $methods);
        $this->assertContains('DELETE', $methods);
    }

    public function test_extracts_uris(): void
    {
        $scanner = $this->app->make(RouteScannerService::class);
        $routes = $scanner->scan();

        $uris = array_column($routes, 'uri');

        $this->assertContains('api/users', $uris);
        $this->assertContains('api/users/{id}', $uris);
        $this->assertContains('api/posts', $uris);
    }

    public function test_extracts_route_names(): void
    {
        $scanner = $this->app->make(RouteScannerService::class);
        $routes = $scanner->scan();

        $names = array_column($routes, 'name');

        $this->assertContains('users.index', $names);
        $this->assertContains('users.store', $names);
    }

    public function test_extracts_controller_info(): void
    {
        $scanner = $this->app->make(RouteScannerService::class);
        $routes = $scanner->scan();

        $firstRoute = $routes[0];

        $this->assertNotNull($firstRoute['controller']);
        $this->assertNotNull($firstRoute['controller_method']);
        $this->assertSame(TestDummyController::class, $firstRoute['controller']);
    }

    public function test_extracts_route_parameters(): void
    {
        $scanner = $this->app->make(RouteScannerService::class);
        $routes = $scanner->scan();

        $routeWithParams = null;
        foreach ($routes as $route) {
            if (str_contains($route['uri'], '{id}')) {
                $routeWithParams = $route;
                break;
            }
        }

        $this->assertNotNull($routeWithParams);
        $this->assertContains('id', $routeWithParams['parameters']);
    }

    public function test_excludes_closure_routes(): void
    {
        // Add a closure route dynamically
        Route::get('api/closure-test', function () {
            return 'test';
        });

        $scanner = $this->app->make(RouteScannerService::class);
        $routes = $scanner->scan();

        $uris = array_column($routes, 'uri');
        $this->assertNotContains('api/closure-test', $uris);
    }

    public function test_excludes_web_routes_by_default(): void
    {
        Route::get('web-page', [TestDummyController::class, 'index']);

        $scanner = $this->app->make(RouteScannerService::class);
        $routes = $scanner->scan();

        $uris = array_column($routes, 'uri');
        $this->assertNotContains('web-page', $uris);
    }

    public function test_sets_is_api_flag(): void
    {
        $scanner = $this->app->make(RouteScannerService::class);
        $routes = $scanner->scan();

        foreach ($routes as $route) {
            $this->assertTrue($route['is_api']);
        }
    }
}

/**
 * Dummy controller for testing route scanning.
 */
class TestDummyController
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

    public function update(int $id): array
    {
        return ['updated' => true];
    }

    public function destroy(int $id): array
    {
        return ['deleted' => true];
    }
}

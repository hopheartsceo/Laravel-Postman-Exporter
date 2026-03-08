<?php

declare(strict_types=1);

namespace Hopheartsceo\PostmanExporter\Tests;

use Hopheartsceo\PostmanExporter\PostmanExporterServiceProvider;
use Orchestra\Testbench\TestCase as OrchestraTestCase;

abstract class TestCase extends OrchestraTestCase
{
    /**
     * Get package providers.
     *
     * @param  \Illuminate\Foundation\Application  $app
     * @return array<int, class-string>
     */
    protected function getPackageProviders($app): array
    {
        return [
            PostmanExporterServiceProvider::class,
        ];
    }

    /**
     * Get package aliases.
     *
     * @param  \Illuminate\Foundation\Application  $app
     * @return array<string, class-string>
     */
    protected function getPackageAliases($app): array
    {
        return [
            'PostmanExporter' => \Hopheartsceo\PostmanExporter\Facades\PostmanExporter::class,
        ];
    }

    /**
     * Define environment setup.
     *
     * @param  \Illuminate\Foundation\Application  $app
     */
    protected function defineEnvironment($app): void
    {
        $app['config']->set('postman-exporter.base_url', 'http://localhost');
        $app['config']->set('postman-exporter.output_path', sys_get_temp_dir() . '/postman-test-collection.json');
        $app['config']->set('postman-exporter.collection_name', 'Test API Collection');
        $app['config']->set('postman-exporter.group_routes', true);
        $app['config']->set('postman-exporter.include_web_routes', false);
        $app['config']->set('postman-exporter.default_headers', [
            'Accept' => 'application/json',
            'Content-Type' => 'application/json',
        ]);
        $app['config']->set('postman-exporter.middleware_to_headers_map', [
            'auth:sanctum' => [
                'Authorization' => 'Bearer {{token}}',
            ],
            'auth:api' => [
                'Authorization' => 'Bearer {{token}}',
            ],
        ]);
        $app['config']->set('postman-exporter.route_filters', [
            'include_prefixes' => [],
            'exclude_prefixes' => ['_ignition', '_debugbar', 'sanctum'],
            'include_middleware' => [],
            'exclude_middleware' => [],
        ]);
        $app['config']->set('postman-exporter.grouping', [
            'enabled' => true,
            'strategy' => 'prefix',
            'fallback_folder' => 'general',
            'strip_prefixes' => ['api/v1', 'api/v2', 'api/v3', 'api'],
        ]);
        $app['config']->set('postman-exporter.responses', [
            'enabled' => true,
            'fallback_status' => 200,
            'fallback_body' => [
                'message' => 'Success',
            ],
        ]);
    }
}

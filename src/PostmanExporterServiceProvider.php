<?php

declare(strict_types=1);

namespace Wessaal\PostmanExporter;

use Wessaal\PostmanExporter\Commands\ExportPostmanCommand;
use Wessaal\PostmanExporter\Services\ExampleDataGeneratorService;
use Wessaal\PostmanExporter\Services\PostmanCollectionBuilderService;
use Wessaal\PostmanExporter\Services\PostmanUploaderService;
use Wessaal\PostmanExporter\Services\RequestAnalyzerService;
use Wessaal\PostmanExporter\Services\RouteScannerService;
use Wessaal\PostmanExporter\Services\ValidationParserService;
use Illuminate\Support\ServiceProvider;

class PostmanExporterServiceProvider extends ServiceProvider
{
    /**
     * Register package services.
     */
    public function register(): void
    {
        $this->mergeConfigFrom(
            __DIR__ . '/../config/postman-exporter.php',
            'postman-exporter'
        );

        $this->app->singleton(ExampleDataGeneratorService::class, function () {
            return new ExampleDataGeneratorService();
        });

        $this->app->singleton(ValidationParserService::class, function ($app) {
            return new ValidationParserService(
                $app->make(ExampleDataGeneratorService::class)
            );
        });

        $this->app->singleton(RouteScannerService::class, function ($app) {
            return new RouteScannerService(
                $app['router'],
                $app['config']->get('postman-exporter')
            );
        });

        $this->app->singleton(RequestAnalyzerService::class, function ($app) {
            return new RequestAnalyzerService(
                $app->make(ValidationParserService::class),
                $app->make(ExampleDataGeneratorService::class)
            );
        });

        $this->app->singleton(PostmanCollectionBuilderService::class, function ($app) {
            return new PostmanCollectionBuilderService(
                $app['config']->get('postman-exporter')
            );
        });

        $this->app->singleton(PostmanUploaderService::class, function () {
            return new PostmanUploaderService();
        });

        $this->app->singleton('postman-exporter', function ($app) {
            return new PostmanExporterManager(
                $app->make(RouteScannerService::class),
                $app->make(RequestAnalyzerService::class),
                $app->make(PostmanCollectionBuilderService::class),
                $app->make(PostmanUploaderService::class),
                $app['config']->get('postman-exporter')
            );
        });

        $this->app->alias('postman-exporter', PostmanExporterManager::class);
    }

    /**
     * Bootstrap package services.
     */
    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__ . '/../config/postman-exporter.php' => config_path('postman-exporter.php'),
            ], 'postman-exporter-config');

            $this->commands([
                ExportPostmanCommand::class,
            ]);
        }
    }
}

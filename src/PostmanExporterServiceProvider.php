<?php

declare(strict_types=1);

namespace Hopheartsceo\PostmanExporter;

use Hopheartsceo\PostmanExporter\Commands\ExportPostmanCommand;
use Hopheartsceo\PostmanExporter\Services\ExampleDataGeneratorService;
use Hopheartsceo\PostmanExporter\Services\FolderOrganizerService;
use Hopheartsceo\PostmanExporter\Services\PostmanCollectionBuilderService;
use Hopheartsceo\PostmanExporter\Services\PostmanUploaderService;
use Hopheartsceo\PostmanExporter\Services\RequestAnalyzerService;
use Hopheartsceo\PostmanExporter\Services\RouteScannerService;
use Hopheartsceo\PostmanExporter\Services\ValidationParserService;
use Hopheartsceo\PostmanExporter\Contracts\FolderOrganizerInterface;
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

        $this->app->singleton(FolderOrganizerService::class, function ($app) {
            return new FolderOrganizerService(
                $app['config']->get('postman-exporter')
            );
        });

        $this->app->bind(FolderOrganizerInterface::class, FolderOrganizerService::class);

        $this->app->singleton(PostmanCollectionBuilderService::class, function ($app) {
            return new PostmanCollectionBuilderService(
                $app->make(FolderOrganizerService::class),
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

<?php

declare(strict_types=1);

namespace Hopheartsceo\PostmanExporter\Commands;

use Hopheartsceo\PostmanExporter\PostmanExporterManager;
use Hopheartsceo\PostmanExporter\Services\PostmanUploaderService;
use Hopheartsceo\PostmanExporter\Services\RouteScannerService;
use Illuminate\Console\Command;

/**
 * Artisan command to export Laravel routes as a Postman Collection.
 */
class ExportPostmanCommand extends Command
{
    /**
     * The name and signature of the console command.
     */
    protected $signature = 'postman:export
        {--output= : Custom output file path}
        {--group-by-prefix : Group routes into folders by prefix}
        {--include-web-routes : Include web (non-API) routes}
        {--upload : Upload collection to Postman}
        {--api-key= : Postman API key for upload}';

    /**
     * The console command description.
     */
    protected $description = 'Generate a Postman Collection v2.1 from your Laravel routes';

    /**
     * Execute the console command.
     */
    public function handle(
        PostmanExporterManager $manager,
        PostmanUploaderService $uploader,
    ): int
    {
        $this->newLine();
        $this->components->info('🚀 Laravel Postman Exporter');
        $this->newLine();

        // Apply CLI overrides to config
        $this->applyOptions();

        // Step 1: Scan routes
        $this->components->task('Scanning routes', function () {
            // Routes will be scanned during generate
            return true;
        });

        // Step 2: Generate collection
        $this->components->task('Analyzing controllers & building collection', function () use ($manager) {
            try {
                $manager->generate();
                return true;
            } catch (\Throwable $e) {
                $this->error('  Error: ' . $e->getMessage());
                return false;
            }
        });

        $collection = $manager->getCollection();

        if ($collection === null) {
            $this->components->error('Failed to generate collection.');
            return self::FAILURE;
        }

        // Count items
        $itemCount = $this->countItems($collection['item'] ?? []);
        $this->components->info("  Found {$itemCount} route(s)");
        $this->newLine();

        // Step 3: Save file
        $outputPath = $this->option('output') ?? config('postman-exporter.output_path');

        $this->components->task('Saving collection', function () use ($manager, $outputPath) {
            try {
                $manager->save($outputPath);
                return true;
            } catch (\Throwable $e) {
                $this->error('  Error: ' . $e->getMessage());
                return false;
            }
        });

        $this->newLine();
        $this->components->info("  📄 Saved to: {$outputPath}");

        // Step 4: Upload (optional)
        if ($this->option('upload') || config('postman-exporter.enable_upload')) {
            $apiKey = $this->option('api-key') ?? config('postman-exporter.postman_api_key');

            $this->newLine();
            $this->components->task('Uploading to Postman', function () use ($uploader, $collection, $apiKey) {
                try {
                    $result = $uploader->upload($collection, $apiKey);

                    if ($result['success']) {
                        return true;
                    }

                    $this->error('  ' . $result['message']);
                    return false;
                } catch (\Throwable $e) {
                    $this->error('  Error: ' . $e->getMessage());
                    return false;
                }
            });
        }

        $this->newLine();
        $this->components->info('✅ Postman collection generated successfully!');
        $this->newLine();

        return self::SUCCESS;
    }

    /**
     * Apply CLI options to the runtime config.
     */
    protected function applyOptions(): void
    {
        if ($this->option('group-by-prefix')) {
            config(['postman-exporter.group_routes' => true]);
        }

        if ($this->option('include-web-routes')) {
            config(['postman-exporter.include_web_routes' => true]);
        }
    }

    /**
     * Count total request items in the collection (recursive for folders).
     *
     * @param  array<int, array<string, mixed>>  $items
     */
    protected function countItems(array $items): int
    {
        $count = 0;

        foreach ($items as $item) {
            if (isset($item['item'])) {
                // It's a folder
                $count += $this->countItems($item['item']);
            } else {
                $count++;
            }
        }

        return $count;
    }
}

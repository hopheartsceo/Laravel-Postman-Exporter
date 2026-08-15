<?php

declare(strict_types=1);

namespace Hopheartsceo\PostmanExporter\Commands;

use Hopheartsceo\PostmanExporter\PostmanExporterManager;
use Hopheartsceo\PostmanExporter\Services\ContractDiffService;
use Illuminate\Console\Command;

class CheckApiContractCommand extends Command
{
    protected $signature = 'postman:check
        {--against= : Baseline OpenAPI JSON file}
        {--write-baseline : Write the current OpenAPI contract as the baseline}';

    protected $description = 'Detect breaking API contract changes from the generated OpenAPI specification';

    public function handle(PostmanExporterManager $manager, ContractDiffService $diff): int
    {
        $current = $manager->generate('openapi');
        $baselinePath = $this->resolvePath(
            (string) ($this->option('against') ?: config('postman-exporter.contract.baseline_path'))
        );

        if ($this->option('write-baseline')) {
            return $this->writeBaseline($baselinePath, $current);
        }

        if (! is_file($baselinePath)) {
            $this->components->error("Baseline contract not found: {$baselinePath}");
            $this->line('Create it with: php artisan postman:check --write-baseline');

            return self::FAILURE;
        }

        try {
            $baseline = json_decode((string) file_get_contents($baselinePath), true, 512, JSON_THROW_ON_ERROR);
        } catch (\Throwable $e) {
            $this->components->error('Unable to read baseline contract: ' . $e->getMessage());

            return self::FAILURE;
        }

        if (! is_array($baseline)) {
            $this->components->error('Baseline contract must contain a JSON object.');

            return self::FAILURE;
        }

        $changes = $diff->diff($baseline, $current);

        if ($changes === []) {
            $this->components->info('No breaking API changes detected.');

            return self::SUCCESS;
        }

        $this->components->error(count($changes) . ' breaking API change(s) detected:');

        foreach ($changes as $change) {
            $this->line('  - ' . $change);
        }

        return self::FAILURE;
    }

    /** @param array<string, mixed> $current */
    protected function writeBaseline(string $path, array $current): int
    {
        $directory = dirname($path);

        if (! is_dir($directory) && ! mkdir($directory, 0755, true) && ! is_dir($directory)) {
            $this->components->error("Unable to create baseline directory: {$directory}");

            return self::FAILURE;
        }

        $json = json_encode($current, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

        if ($json === false || file_put_contents($path, $json . PHP_EOL) === false) {
            $this->components->error("Unable to write baseline contract: {$path}");

            return self::FAILURE;
        }

        $this->components->info("Baseline contract written to: {$path}");

        return self::SUCCESS;
    }

    protected function resolvePath(string $path): string
    {
        if ($path === '') {
            return base_path('openapi-baseline.json');
        }

        if (str_starts_with($path, DIRECTORY_SEPARATOR)) {
            return $path;
        }

        return base_path($path);
    }
}

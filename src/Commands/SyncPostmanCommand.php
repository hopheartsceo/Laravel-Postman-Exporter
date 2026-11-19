<?php

declare(strict_types=1);

namespace Hopheartsceo\PostmanExporter\Commands;

use Hopheartsceo\PostmanExporter\PostmanExporterManager;
use Hopheartsceo\PostmanExporter\Services\GoEngineBridgeService;
use Illuminate\Console\Command;

class SyncPostmanCommand extends Command
{
    protected $signature = 'postman:sync
        {--api-key= : Postman API key (defaults to config postman_api_key)}
        {--uid= : Existing Postman Collection UID (auto-detected by name if omitted)}
        {--workspace= : Postman Workspace ID}
        {--output= : Output path for the merged collection JSON}
        {--local-only : Perform smart 3-way merge locally without calling Postman Cloud API}
        {--no-auto-wire : Disable automatic token-capture script injection on auth endpoints}
        {--no-preserve-deprecated : Do not move removed routes into Deprecated Routes folder}';

    protected $description = 'Smart-merge generated Laravel routes with Postman Cloud (preserving team scripts & variables)';

    public function handle(PostmanExporterManager $manager, GoEngineBridgeService $engine): int
    {
        if (! $engine->isAvailable()) {
            $this->components->error('Go engine binary not found at: ' . $engine->binaryPath());
            return self::FAILURE;
        }

        $outputPath = (string) ($this->option('output') ?: config('postman-exporter.output_path'));
        $tempFreshPath = sys_get_temp_dir() . '/lpe-fresh-' . uniqid('', true) . '.json';

        $this->components->task('Scanning Laravel routes & analyzing AST schemas', function () use ($manager, $tempFreshPath) {
            $manager->generate('postman');
            $manager->save($tempFreshPath);
            return true;
        });

        try {
            // Fuse recorded runtime exchanges if available
            $recordingsPath = (string) config('postman-exporter.capture.recordings_path');
            if ($recordingsPath !== '' && is_file($recordingsPath)) {
                $this->components->task('Fusing captured runtime HTTP examples', function () use ($engine, $tempFreshPath, $recordingsPath) {
                    $engine->fuse($tempFreshPath, $recordingsPath, $tempFreshPath);
                    return true;
                });
            }

            $autoWire = ! (bool) $this->option('no-auto-wire') && (bool) config('postman-exporter.sync.auto_wire_auth', true);
            $preserveDeprecated = ! (bool) $this->option('no-preserve-deprecated') && (bool) config('postman-exporter.sync.preserve_deprecated', true);

            if ($this->option('local-only')) {
                $result = $engine->merge(
                    localPath: $tempFreshPath,
                    remotePath: is_file($outputPath) ? $outputPath : null,
                    outPath: $outputPath,
                    autoWire: $autoWire,
                    preserveDeprecated: $preserveDeprecated,
                );

                $this->renderStats($result['stats'] ?? [], "Merged locally into: {$outputPath}");
                return self::SUCCESS;
            }

            $apiKey = (string) ($this->option('api-key') ?: config('postman-exporter.postman_api_key'));
            if ($apiKey === '') {
                $this->components->error('Postman API key is required. Pass --api-key, set POSTMAN_API_KEY, or use --local-only.');
                return self::FAILURE;
            }

            $uid = (string) ($this->option('uid') ?: config('postman-exporter.sync.collection_uid', ''));
            $workspaceId = (string) ($this->option('workspace') ?: config('postman-exporter.sync.workspace_id', ''));

            $syncResult = null;
            $this->components->task('Running Go 3-way smart merge & syncing with Postman Cloud', function () use (
                $engine,
                $tempFreshPath,
                $apiKey,
                $uid,
                $workspaceId,
                $outputPath,
                $autoWire,
                $preserveDeprecated,
                &$syncResult
            ) {
                $syncResult = $engine->sync(
                    localPath: $tempFreshPath,
                    apiKey: $apiKey,
                    uid: $uid,
                    workspaceId: $workspaceId,
                    outPath: $outputPath,
                    autoWire: $autoWire,
                    preserveDeprecated: $preserveDeprecated,
                );
                return true;
            });

            $upsert = $syncResult['upsert'] ?? [];
            $action = ($upsert['created'] ?? false) ? 'Created new collection' : 'Updated existing collection';
            $colUid = $upsert['uid'] ?? 'unknown';

            $this->renderStats($syncResult['stats'] ?? [], "{$action} (UID: {$colUid}) & saved to {$outputPath}");
            return self::SUCCESS;
        } catch (\Throwable $e) {
            $this->components->error($e->getMessage());
            return self::FAILURE;
        } finally {
            @unlink($tempFreshPath);
        }
    }

    /**
     * @param  array<string, mixed>  $stats
     */
    protected function renderStats(array $stats, string $headline): void
    {
        $this->newLine();
        $this->components->info($headline);
        $this->table(
            ['Added', 'Updated', 'Preserved Scripts', 'Auto-Wired Auth', 'Deprecated Preserved'],
            [[
                (string) ($stats['added'] ?? 0),
                (string) ($stats['updated'] ?? 0),
                (string) ($stats['preserved_scripts'] ?? 0),
                (string) ($stats['auto_wired'] ?? 0),
                (string) ($stats['deprecated'] ?? 0),
            ]]
        );
    }
}

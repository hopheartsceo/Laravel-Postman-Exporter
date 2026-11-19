<?php

declare(strict_types=1);

namespace Hopheartsceo\PostmanExporter\Commands;

use Hopheartsceo\PostmanExporter\PostmanExporterManager;
use Hopheartsceo\PostmanExporter\Services\GoEngineBridgeService;
use Illuminate\Console\Command;

class AuditApiCommand extends Command
{
    protected $signature = 'postman:audit
        {--base-url= : Target live server URL (defaults to config audit.base_url)}
        {--token= : Bearer token for authenticated endpoints}
        {--concurrency=10 : Number of concurrent Go worker goroutines}
        {--no-chaos : Disable type-mutation chaos fuzzing probes}
        {--timeout=10 : Per-request timeout in seconds}
        {--collection= : Optional existing Postman collection JSON path}';

    protected $description = 'Run concurrent Go security, auth-guard & chaos fuzzing probes against a live API server';

    public function handle(PostmanExporterManager $manager, GoEngineBridgeService $engine): int
    {
        if (! $engine->isAvailable()) {
            $this->components->error('Go engine binary not found at: ' . $engine->binaryPath());
            return self::FAILURE;
        }

        $baseUrl = (string) ($this->option('base-url') ?: config('postman-exporter.audit.base_url', 'http://127.0.0.1:8000'));
        $token = (string) ($this->option('token') ?: config('postman-exporter.audit.token', ''));
        $concurrency = (int) ($this->option('concurrency') ?: config('postman-exporter.audit.concurrency', 10));
        $chaos = ! (bool) $this->option('no-chaos') && (bool) config('postman-exporter.audit.chaos', true);
        $timeout = (int) ($this->option('timeout') ?: config('postman-exporter.audit.timeout', 10));

        $collectionPath = (string) ($this->option('collection') ?: '');
        $tempPath = null;

        if ($collectionPath === '') {
            $tempPath = sys_get_temp_dir() . '/lpe-audit-' . uniqid('', true) . '.json';
            $manager->generate('postman');
            $manager->save($tempPath);
            $collectionPath = $tempPath;
        }

        try {
            $this->components->info("Launching Go concurrent audit against {$baseUrl} (workers: {$concurrency}, chaos: " . ($chaos ? 'ON' : 'OFF') . ')');

            $report = $engine->audit(
                collectionPath: $collectionPath,
                baseUrl: $baseUrl,
                token: $token,
                concurrency: $concurrency,
                chaos: $chaos,
                timeout: $timeout,
            );

            $this->newLine();
            $this->table(
                ['Endpoints', 'Total Probes', 'Critical', 'Warnings', 'Passed', 'Duration'],
                [[
                    (string) ($report['total_endpoints'] ?? 0),
                    (string) ($report['total_probes'] ?? 0),
                    (string) ($report['critical_count'] ?? 0),
                    (string) ($report['warning_count'] ?? 0),
                    (string) ($report['passed_count'] ?? 0),
                    (string) (($report['duration_ms'] ?? 0) . ' ms'),
                ]]
            );

            $findings = $report['findings'] ?? [];
            $issues = array_filter($findings, fn (array $f) => ($f['severity'] ?? '') !== 'PASS');

            if (! empty($issues)) {
                $this->newLine();
                $rows = [];
                foreach ($issues as $issue) {
                    $rows[] = [
                        $issue['severity'] ?? '',
                        $issue['category'] ?? '',
                        ($issue['method'] ?? '') . ' ' . ($issue['endpoint'] ?? ''),
                        (string) ($issue['status_code'] ?? '-'),
                        $issue['detail'] ?? '',
                    ];
                }
                $this->table(['Severity', 'Category', 'Endpoint', 'HTTP', 'Detail'], $rows);
            }

            if ((int) ($report['critical_count'] ?? 0) > 0) {
                $this->components->error('Audit failed with ' . $report['critical_count'] . ' critical finding(s).');
                return self::FAILURE;
            }

            $this->components->info('API audit completed with zero critical findings.');
            return self::SUCCESS;
        } catch (\Throwable $e) {
            $this->components->error($e->getMessage());
            return self::FAILURE;
        } finally {
            if ($tempPath !== null) {
                @unlink($tempPath);
            }
        }
    }
}

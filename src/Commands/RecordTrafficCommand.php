<?php

declare(strict_types=1);

namespace Hopheartsceo\PostmanExporter\Commands;

use Hopheartsceo\PostmanExporter\PostmanExporterManager;
use Hopheartsceo\PostmanExporter\Services\GoEngineBridgeService;
use Illuminate\Console\Command;
use Symfony\Component\Process\Process;

class RecordTrafficCommand extends Command
{
    protected $signature = 'postman:record
        {--command=php artisan test : Shell command to run while capturing traffic}
        {--listen-only : Keep listening on Unix socket until Ctrl+C without running a test command}
        {--fresh : Clear previous recorded snapshots before starting}
        {--output= : Output path for the fused Postman collection}';

    protected $description = 'Capture real runtime HTTP exchanges via Go Unix socket & fuse them into Postman examples';

    public function handle(PostmanExporterManager $manager, GoEngineBridgeService $engine): int
    {
        if (! $engine->isAvailable()) {
            $this->components->error('Go engine binary not found at: ' . $engine->binaryPath());
            return self::FAILURE;
        }

        $socketPath = (string) config('postman-exporter.capture.socket_path', '/tmp/lpe-recorder.sock');
        $recordingsPath = (string) config('postman-exporter.capture.recordings_path', storage_path('app/lpe-recordings.ndjson'));
        $outputPath = (string) ($this->option('output') ?: config('postman-exporter.output_path'));

        if ($this->option('fresh') && is_file($recordingsPath)) {
            @unlink($recordingsPath);
        }

        $listener = new Process([
            $engine->binaryPath(),
            'listen',
            '--socket=' . $socketPath,
            '--out=' . $recordingsPath,
        ]);
        $listener->setTimeout(null);
        $listener->start();

        // Wait up to 2 seconds for Unix socket to become ready
        $ready = false;
        for ($i = 0; $i < 40; $i++) {
            if (file_exists($socketPath)) {
                $ready = true;
                break;
            }
            usleep(50_000);
        }

        if (! $ready) {
            $listener->stop();
            $this->components->error('Failed to start Go Unix socket listener at: ' . $socketPath);
            return self::FAILURE;
        }

        $this->components->info("Go traffic recorder active on {$socketPath}");

        $commandExit = self::SUCCESS;

        try {
            if ($this->option('listen-only')) {
                $this->line('Listening for live API traffic... Press Ctrl+C to stop and fuse snapshots.');
                while ($listener->isRunning()) {
                    usleep(250_000);
                }
            } else {
                $cmd = (string) $this->option('command');
                $this->line("Running: {$cmd}");
                $this->newLine();

                $runner = Process::fromShellCommandline($cmd, base_path(), [
                    'LPE_CAPTURE_ENABLED' => 'true',
                    'LPE_CAPTURE_SOCKET' => $socketPath,
                ]);
                $runner->setTimeout(null);
                if (Process::isTtySupported()) {
                    $runner->setTty(true);
                }

                $commandExit = $runner->run(function (string $type, string $buffer) {
                    $this->output->write($buffer);
                });
            }
        } finally {
            if ($listener->isRunning()) {
                $listener->signal(defined('SIGTERM') ? SIGTERM : 15);
                $listener->wait();
            }
            @unlink($socketPath);
        }

        $this->newLine();
        $this->components->task('Generating collection & fusing captured runtime responses', function () use (
            $manager,
            $engine,
            $outputPath,
            $recordingsPath,
            &$fuseResult
        ) {
            $manager->generate('postman');
            $manager->save($outputPath);
            $fuseResult = $engine->fuse($outputPath, $recordingsPath, $outputPath);
            return true;
        });

        $stats = $fuseResult['stats'] ?? [];
        $this->table(
            ['Exchanges Captured', 'Matched Routes', 'Runtime Examples Added'],
            [[
                (string) ($stats['recordings_read'] ?? 0),
                (string) ($stats['matched_routes'] ?? 0),
                (string) ($stats['examples_added'] ?? 0),
            ]]
        );

        $this->components->info("Fused Postman collection saved to: {$outputPath}");

        return $commandExit === 0 ? self::SUCCESS : self::FAILURE;
    }
}

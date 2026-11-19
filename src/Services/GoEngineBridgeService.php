<?php

declare(strict_types=1);

namespace Hopheartsceo\PostmanExporter\Services;

use RuntimeException;
use Symfony\Component\Process\Process;

class GoEngineBridgeService
{
    public function __construct(
        protected array $config = [],
    ) {}

    public function binaryPath(): string
    {
        $configured = (string) ($this->config['engine']['binary_path'] ?? '');
        if ($configured !== '' && is_file($configured)) {
            return $configured;
        }

        $binDir = dirname(__DIR__, 2) . '/bin';
        $platformSuffix = $this->detectPlatformSuffix();
        $ext = PHP_OS_FAMILY === 'Windows' ? '.exe' : '';

        $platformBinary = "{$binDir}/lpe-engine-{$platformSuffix}{$ext}";
        if (is_file($platformBinary)) {
            return $platformBinary;
        }

        return "{$binDir}/lpe-engine{$ext}";
    }

    protected function detectPlatformSuffix(): string
    {
        $os = match (PHP_OS_FAMILY) {
            'Darwin' => 'darwin',
            'Windows' => 'windows',
            default => 'linux',
        };

        $machine = strtolower(php_uname('m'));
        $arch = match (true) {
            str_contains($machine, 'arm64'), str_contains($machine, 'aarch64') => 'arm64',
            default => 'amd64',
        };

        return "{$os}-{$arch}";
    }

    public function isAvailable(): bool
    {
        $path = $this->binaryPath();

        return is_file($path) && is_executable($path);
    }

    /**
     * @return array<string, mixed>
     */
    public function version(): array
    {
        return $this->runCommand(['version']);
    }

    /**
     * @return array<string, mixed>
     */
    public function merge(
        string $localPath,
        ?string $remotePath,
        string $outPath,
        bool $autoWire = true,
        bool $preserveDeprecated = true,
    ): array {
        $args = [
            'merge',
            '--local=' . $localPath,
            '--out=' . $outPath,
            '--auto-wire=' . ($autoWire ? 'true' : 'false'),
            '--preserve-deprecated=' . ($preserveDeprecated ? 'true' : 'false'),
        ];

        if ($remotePath !== null && $remotePath !== '') {
            $args[] = '--remote=' . $remotePath;
        }

        return $this->runCommand($args);
    }

    /**
     * @return array<string, mixed>
     */
    public function sync(
        string $localPath,
        string $apiKey,
        ?string $uid = null,
        ?string $workspaceId = null,
        ?string $outPath = null,
        bool $autoWire = true,
        bool $preserveDeprecated = true,
    ): array {
        $args = [
            'sync',
            '--local=' . $localPath,
            '--api-key=' . $apiKey,
            '--auto-wire=' . ($autoWire ? 'true' : 'false'),
            '--preserve-deprecated=' . ($preserveDeprecated ? 'true' : 'false'),
        ];

        if ($uid !== null && $uid !== '') {
            $args[] = '--uid=' . $uid;
        }

        if ($workspaceId !== null && $workspaceId !== '') {
            $args[] = '--workspace=' . $workspaceId;
        }

        if ($outPath !== null && $outPath !== '') {
            $args[] = '--out=' . $outPath;
        }

        return $this->runCommand($args, 60);
    }

    /**
     * @return array<string, mixed>
     */
    public function fuse(string $collectionPath, string $recordingsPath, ?string $outPath = null): array
    {
        $args = [
            'fuse',
            '--collection=' . $collectionPath,
            '--recordings=' . $recordingsPath,
        ];

        if ($outPath !== null && $outPath !== '') {
            $args[] = '--out=' . $outPath;
        }

        return $this->runCommand($args);
    }

    /**
     * @return array<string, mixed>
     */
    public function audit(
        string $collectionPath,
        string $baseUrl,
        ?string $token = null,
        int $concurrency = 10,
        bool $chaos = true,
        int $timeout = 10,
    ): array {
        $args = [
            'audit',
            '--collection=' . $collectionPath,
            '--base-url=' . $baseUrl,
            '--concurrency=' . $concurrency,
            '--chaos=' . ($chaos ? 'true' : 'false'),
            '--timeout=' . $timeout,
        ];

        if ($token !== null && $token !== '') {
            $args[] = '--token=' . $token;
        }

        return $this->runCommand($args, max(30, $timeout * 4), allowExitCodeTwo: true);
    }

    /**
     * @param  array<int, string>  $args
     * @return array<string, mixed>
     */
    protected function runCommand(array $args, int $timeoutSeconds = 30, bool $allowExitCodeTwo = false): array
    {
        if (! $this->isAvailable()) {
            throw new RuntimeException('Go engine binary not found or not executable at: ' . $this->binaryPath());
        }

        $process = new Process(array_merge([$this->binaryPath()], $args));
        $process->setTimeout($timeoutSeconds);
        $process->run();

        $exitCode = $process->getExitCode();
        $stdout = trim($process->getOutput());

        $decoded = null;
        if ($stdout !== '') {
            $decoded = json_decode($stdout, true);
        }

        if ($exitCode !== 0 && ! ($allowExitCodeTwo && $exitCode === 2)) {
            $errorDetail = is_array($decoded) && isset($decoded['error'])
                ? (string) $decoded['error']
                : ($process->getErrorOutput() ?: $stdout);

            throw new RuntimeException('lpe-engine failed: ' . trim($errorDetail));
        }

        if (! is_array($decoded)) {
            throw new RuntimeException('Invalid JSON output from lpe-engine: ' . $stdout);
        }

        return $decoded;
    }
}

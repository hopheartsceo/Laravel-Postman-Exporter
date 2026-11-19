<?php

declare(strict_types=1);

namespace Hopheartsceo\PostmanExporter\Tests\Feature;

use Hopheartsceo\PostmanExporter\Middleware\RuntimeTrafficRecorderMiddleware;
use Hopheartsceo\PostmanExporter\Services\GoEngineBridgeService;
use Hopheartsceo\PostmanExporter\Tests\TestCase;
use Illuminate\Http\JsonResponse;
use Symfony\Component\Process\Process;

class HybridGoEngineTest extends TestCase
{
    protected function defineRoutes($router): void
    {
        $router->middleware(['api', RuntimeTrafficRecorderMiddleware::class])
            ->prefix('api')
            ->group(function ($router) {
                $router->post('login', [HybridTestController::class, 'login'])->name('auth.login');
                $router->get('users', [HybridTestController::class, 'users'])->name('users.index');
            });
    }

    public function test_go_engine_binary_is_available_and_reports_version(): void
    {
        $engine = $this->app->make(GoEngineBridgeService::class);

        $this->assertTrue($engine->isAvailable());
        $version = $engine->version();
        $this->assertSame('3.0.0', $version['version'] ?? null);
    }

    public function test_postman_sync_local_only_preserves_team_scripts_and_auto_wires_auth(): void
    {
        $outPath = sys_get_temp_dir() . '/lpe-sync-test-' . uniqid('', true) . '.json';

        $existingCollection = [
            'info' => [
                'name' => 'Test API Collection',
                'schema' => 'https://schema.getpostman.com/json/collection/v2.1.0/collection.json',
                '_postman_id' => 'team-collection-id-777',
            ],
            'variable' => [
                ['key' => 'token', 'value' => 'preserved_team_token', 'type' => 'string'],
            ],
            'item' => [
                [
                    'name' => 'users',
                    'item' => [
                        [
                            'name' => 'GET Users',
                            'request' => [
                                'method' => 'GET',
                                'url' => '{{base_url}}/api/users',
                            ],
                            'event' => [
                                [
                                    'listen' => 'test',
                                    'script' => [
                                        'type' => 'text/javascript',
                                        'exec' => ["pm.test('Preserved team assertion', () => {});"],
                                    ],
                                ],
                            ],
                        ],
                        [
                            'name' => 'DELETE Old Endpoint',
                            'request' => [
                                'method' => 'DELETE',
                                'url' => '{{base_url}}/api/old-endpoint',
                            ],
                        ],
                    ],
                ],
            ],
        ];

        file_put_contents($outPath, json_encode($existingCollection, JSON_PRETTY_PRINT));

        try {
            $this->artisan('postman:sync', [
                '--local-only' => true,
                '--output' => $outPath,
            ])->assertExitCode(0);

            $merged = json_decode((string) file_get_contents($outPath), true);

            $this->assertSame('team-collection-id-777', $merged['info']['_postman_id']);

            $tokenVar = collect($merged['variable'])->firstWhere('key', 'token');
            $this->assertSame('preserved_team_token', $tokenVar['value'] ?? null);

            $loginFolder = collect($merged['item'])->firstWhere('name', 'login');
            $loginExec = implode("\n", $loginFolder['item'][0]['event'][0]['script']['exec'] ?? []);
            $this->assertStringContainsString('[LPE-AUTO-WIRE]', $loginExec);

            $usersFolder = collect($merged['item'])->firstWhere('name', 'users');
            $usersExec = implode("\n", $usersFolder['item'][0]['event'][0]['script']['exec'] ?? []);
            $this->assertStringContainsString('Preserved team assertion', $usersExec);

            $deprecatedFolder = collect($merged['item'])->firstWhere('name', 'Deprecated Routes');
            $this->assertNotNull($deprecatedFolder);
            $this->assertStringContainsString('[DEPRECATED]', $deprecatedFolder['item'][0]['name']);
        } finally {
            @unlink($outPath);
        }
    }

    public function test_runtime_traffic_middleware_streams_and_redacts_secrets_via_unix_socket(): void
    {
        $engine = $this->app->make(GoEngineBridgeService::class);
        $tmpDir = sys_get_temp_dir();
        $uid = uniqid('', true);
        $socketPath = "{$tmpDir}/lpe-test-{$uid}.sock";
        $recordingsPath = "{$tmpDir}/lpe-rec-{$uid}.ndjson";
        $collectionPath = "{$tmpDir}/lpe-col-{$uid}.json";

        config([
            'postman-exporter.capture.enabled' => true,
            'postman-exporter.capture.socket_path' => $socketPath,
            'postman-exporter.capture.recordings_path' => $recordingsPath,
        ]);

        $listener = new Process([
            $engine->binaryPath(),
            'listen',
            '--socket=' . $socketPath,
            '--out=' . $recordingsPath,
        ]);
        $listener->start();

        for ($i = 0; $i < 40 && ! file_exists($socketPath); $i++) {
            usleep(25_000);
        }

        try {
            $this->postJson('/api/login', [
                'email' => 'ahmad@wessaal.com',
                'password' => 'SuperSecretPassword!',
            ])->assertOk();

            usleep(100_000);
        } finally {
            if ($listener->isRunning()) {
                $listener->signal(15);
                $listener->wait();
            }
            @unlink($socketPath);
        }

        $this->assertFileExists($recordingsPath);

        $this->artisan('postman:export', ['--output' => $collectionPath])->assertExitCode(0);
        $fuseStats = $engine->fuse($collectionPath, $recordingsPath, $collectionPath);

        $this->assertSame(1, $fuseStats['stats']['recordings_read'] ?? 0);
        $this->assertSame(1, $fuseStats['stats']['matched_routes'] ?? 0);

        $fused = json_decode((string) file_get_contents($collectionPath), true);
        $loginFolder = collect($fused['item'])->firstWhere('name', 'login');
        $runtimeResp = $loginFolder['item'][0]['response'][0] ?? [];

        $this->assertStringStartsWith('[Runtime 200]', $runtimeResp['name'] ?? '');
        $this->assertStringContainsString('[REDACTED]', $runtimeResp['body'] ?? '');
        $this->assertStringNotContainsString('live_secret_jwt_token_999', $runtimeResp['body'] ?? '');
        $this->assertStringNotContainsString('SuperSecretPassword!', $runtimeResp['originalRequest']['body']['raw'] ?? '');

        @unlink($recordingsPath);
        @unlink($collectionPath);
    }
}

class HybridTestController
{
    public function login(): JsonResponse
    {
        return response()->json([
            'token' => 'live_secret_jwt_token_999',
            'user' => ['id' => 1, 'name' => 'Ahmad'],
        ], 200);
    }

    public function users(): JsonResponse
    {
        return response()->json([['id' => 1]], 200);
    }
}

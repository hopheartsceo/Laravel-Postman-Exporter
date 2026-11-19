<?php

declare(strict_types=1);

namespace Hopheartsceo\PostmanExporter\Tests\Feature;

use Hopheartsceo\PostmanExporter\Tests\TestCase;
use Symfony\Component\Process\Process;

class LiveServerAuditTest extends TestCase
{
    public function test_postman_audit_detects_auth_bypass_and_chaos_500_on_live_http_server(): void
    {
        $tmpDir = sys_get_temp_dir();
        $uid = uniqid('', true);
        $routerScript = "{$tmpDir}/lpe-live-srv-{$uid}.php";
        $collectionFile = "{$tmpDir}/lpe-live-col-{$uid}.json";

        file_put_contents($routerScript, <<<'PHP'
<?php
$uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$method = $_SERVER['REQUEST_METHOD'];
header('Content-Type: application/json');

if ($uri === '/api/admin/secret' && $method === 'GET') {
    http_response_code(200);
    echo json_encode(['secret' => 'leaked_without_auth']);
    exit;
}

if ($uri === '/api/orders' && $method === 'POST') {
    $raw = file_get_contents('php://input');
    $data = json_decode($raw, true);
    if (!is_string($data['customer_name'] ?? null)) {
        http_response_code(500);
        echo json_encode(['message' => 'ErrorException: Array to string conversion']);
        exit;
    }
    http_response_code(201);
    echo json_encode(['created' => true]);
    exit;
}

http_response_code(200);
echo json_encode(['ok' => true]);
PHP);

        $collection = [
            'info' => [
                'name' => 'Live Audit E2E',
                'schema' => 'https://schema.getpostman.com/json/collection/v2.1.0/collection.json',
            ],
            'item' => [
                [
                    'name' => 'GET Admin Secret',
                    'request' => [
                        'method' => 'GET',
                        'header' => [['key' => 'Authorization', 'value' => 'Bearer {{token}}']],
                        'url' => '{{base_url}}/api/admin/secret',
                    ],
                ],
                [
                    'name' => 'POST Orders',
                    'request' => [
                        'method' => 'POST',
                        'header' => [['key' => 'Content-Type', 'value' => 'application/json']],
                        'body' => ['mode' => 'raw', 'raw' => '{"customer_name":"Ahmad"}'],
                        'url' => '{{base_url}}/api/orders',
                    ],
                ],
                [
                    'name' => 'GET Health',
                    'request' => [
                        'method' => 'GET',
                        'url' => '{{base_url}}/api/health',
                    ],
                ],
            ],
        ];
        file_put_contents($collectionFile, json_encode($collection, JSON_PRETTY_PRINT));

        $port = random_int(18100, 18999);
        $server = new Process(['php', '-S', "127.0.0.1:{$port}", $routerScript]);
        $server->start();

        for ($i = 0; $i < 40; $i++) {
            $fp = @fsockopen('127.0.0.1', $port, $errno, $errstr, 0.05);
            if (is_resource($fp)) {
                fclose($fp);
                break;
            }
            usleep(25_000);
        }

        try {
            $this->artisan('postman:audit', [
                '--base-url' => "http://127.0.0.1:{$port}",
                '--collection' => $collectionFile,
                '--concurrency' => 4,
            ])->assertExitCode(1);
        } finally {
            $server->stop(0);
            @unlink($routerScript);
            @unlink($collectionFile);
        }
    }
}

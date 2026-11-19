<?php

declare(strict_types=1);

namespace Hopheartsceo\PostmanExporter\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RuntimeTrafficRecorderMiddleware
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        if (! $this->shouldRecord()) {
            return $response;
        }

        $this->dispatchToSocket($request, $response);

        return $response;
    }

    protected function shouldRecord(): bool
    {
        $socketPath = (string) config('postman-exporter.capture.socket_path', '/tmp/lpe-recorder.sock');
        $enabled = (bool) config('postman-exporter.capture.enabled', false);

        return $enabled || ($socketPath !== '' && file_exists($socketPath));
    }

    protected function dispatchToSocket(Request $request, Response $response): void
    {
        $socketPath = (string) config('postman-exporter.capture.socket_path', '/tmp/lpe-recorder.sock');
        if ($socketPath === '' || ! file_exists($socketPath)) {
            return;
        }

        $maxBytes = (int) config('postman-exporter.capture.max_body_bytes', 32768);

        $reqHeaders = [];
        foreach ($request->headers->all() as $key => $values) {
            $reqHeaders[(string) $key] = implode(', ', $values);
        }

        $respHeaders = [];
        foreach ($response->headers->all() as $key => $values) {
            $respHeaders[(string) $key] = implode(', ', $values);
        }

        $route = $request->route();
        $uri = $route && method_exists($route, 'uri')
            ? $route->uri()
            : ltrim($request->getPathInfo(), '/');

        $payload = [
            'method' => $request->getMethod(),
            'uri' => $uri,
            'request_headers' => $reqHeaders,
            'request_body' => mb_substr((string) $request->getContent(), 0, $maxBytes),
            'status_code' => $response->getStatusCode(),
            'response_headers' => $respHeaders,
            'response_body' => mb_substr((string) $response->getContent(), 0, $maxBytes),
            'test_name' => $this->detectTestName(),
        ];

        $encoded = json_encode($payload, JSON_UNESCAPED_SLASHES);
        if ($encoded === false) {
            return;
        }

        $stream = @stream_socket_client('unix://' . $socketPath, $errno, $errstr, 0.05);
        if (is_resource($stream)) {
            @fwrite($stream, $encoded . "\n");
            @fclose($stream);
        }
    }

    protected function detectTestName(): string
    {
        if (! app()->runningUnitTests()) {
            return '';
        }

        foreach (debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 25) as $frame) {
            $fn = $frame['function'] ?? '';
            $class = $frame['class'] ?? '';
            if (
                str_starts_with($fn, 'test')
                && $class !== ''
                && ! str_starts_with($class, 'Illuminate\\')
                && ! str_starts_with($class, 'PHPUnit\\')
            ) {
                $clean = preg_replace('/^test_?/', '', $fn) ?? $fn;
                return ucfirst(str_replace('_', ' ', $clean));
            }
        }

        return '';
    }
}

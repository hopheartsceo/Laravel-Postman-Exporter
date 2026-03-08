<?php

declare(strict_types=1);

namespace Hopheartsceo\PostmanExporter\Services;

use Hopheartsceo\PostmanExporter\Contracts\ExampleResponseGeneratorInterface;

/**
 * Converts extracted response structures into formatted Postman response examples.
 */
class ExampleResponseGeneratorService implements ExampleResponseGeneratorInterface
{
    public function __construct(
        protected array $config = [],
    ) {}

    /**
     * Build a Postman-formatted response array from extracted response data.
     *
     * @param  array<string, mixed>  $responseData
     * @param  array<string, mixed>  $originalRequest
     * @return array<string, mixed>
     */
    public function generate(array $responseData, array $originalRequest): array
    {
        $statusCode = $responseData['status_code'] ?? 200;
        $statusText = $responseData['status_text'] ?? 'OK';
        $body = $responseData['body'] ?? ['message' => 'Success'];
        $name = $responseData['name'] ?? 'Success Response';

        return [
            'name' => $name,
            'originalRequest' => $originalRequest,
            'status' => $statusText,
            'code' => $statusCode,
            '_postman_previewlanguage' => 'json',
            'header' => [
                [
                    'key' => 'Content-Type',
                    'value' => 'application/json',
                ],
            ],
            'body' => json_encode($body, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
        ];
    }

    /**
     * Build the fallback default response.
     *
     * @param  array<string, mixed>  $originalRequest
     * @return array<string, mixed>
     */
    public function generateFallback(array $originalRequest): array
    {
        $fallbackStatus = (int) ($this->config['responses']['fallback_status'] ?? 200);
        $fallbackBody = $this->config['responses']['fallback_body'] ?? ['message' => 'Success'];

        return $this->generate(
            [
                'source' => 'fallback',
                'status_code' => $fallbackStatus,
                'status_text' => $this->getStatusText($fallbackStatus),
                'body' => $fallbackBody,
                'name' => 'Success Response',
            ],
            $originalRequest
        );
    }

    /**
     * Generate multiple Postman responses from an array of extracted responses.
     *
     * @param  array<int, array<string, mixed>>  $extractedResponses
     * @param  array<string, mixed>  $originalRequest
     * @return array<int, array<string, mixed>>
     */
    public function generateAll(array $extractedResponses, array $originalRequest): array
    {
        if (empty($extractedResponses)) {
            return [$this->generateFallback($originalRequest)];
        }

        $responses = [];
        foreach ($extractedResponses as $responseData) {
            $responses[] = $this->generate($responseData, $originalRequest);
        }

        return $responses;
    }

    /**
     * Get HTTP status text for a given status code.
     */
    protected function getStatusText(int $code): string
    {
        $texts = [
            200 => 'OK',
            201 => 'Created',
            202 => 'Accepted',
            204 => 'No Content',
            400 => 'Bad Request',
            401 => 'Unauthorized',
            403 => 'Forbidden',
            404 => 'Not Found',
            422 => 'Unprocessable Entity',
            500 => 'Internal Server Error',
        ];

        return $texts[$code] ?? 'OK';
    }
}

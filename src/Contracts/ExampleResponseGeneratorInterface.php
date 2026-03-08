<?php

declare(strict_types=1);

namespace Hopheartsceo\PostmanExporter\Contracts;

/**
 * Contract for example response generation services.
 */
interface ExampleResponseGeneratorInterface
{
    /**
     * Build a Postman-formatted response array from extracted response data.
     *
     * @param  array<string, mixed>  $responseData
     * @param  array<string, mixed>  $originalRequest
     * @return array<string, mixed>
     */
    public function generate(array $responseData, array $originalRequest): array;

    /**
     * Build the fallback default response.
     *
     * @param  array<string, mixed>  $originalRequest
     * @return array<string, mixed>
     */
    public function generateFallback(array $originalRequest): array;
}

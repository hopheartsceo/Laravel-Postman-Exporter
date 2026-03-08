<?php

declare(strict_types=1);

namespace Wessaal\PostmanExporter\Contracts;

/**
 * Contract for Postman upload services.
 */
interface UploaderInterface
{
    /**
     * Upload a collection to Postman.
     *
     * @param  array<string, mixed>  $collection
     * @param  string                $apiKey
     * @return array{success: bool, message: string, data: array<string, mixed>}
     */
    public function upload(array $collection, string $apiKey): array;
}

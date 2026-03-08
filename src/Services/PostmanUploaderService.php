<?php

declare(strict_types=1);

namespace Wessaal\PostmanExporter\Services;

use Wessaal\PostmanExporter\Contracts\UploaderInterface;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;

/**
 * Uploads Postman collections via the Postman API.
 */
class PostmanUploaderService implements UploaderInterface

{
    /**
     * The Postman API base URL.
     */
    protected string $apiUrl = 'https://api.getpostman.com/collections';

    /**
     * Upload a collection to Postman.
     *
     * @param  array<string, mixed>  $collection  The full Postman collection data
     * @param  string                $apiKey      Postman API key
     * @return array{success: bool, message: string, data: array<string, mixed>}
     *
     * @throws \RuntimeException
     */
    public function upload(array $collection, string $apiKey): array
    {
        if (empty($apiKey)) {
            throw new \RuntimeException(
                'Postman API key is required for upload. Set it in config or pass via --api-key option.'
            );
        }

        $client = new Client([
            'timeout' => 30,
            'headers' => [
                'X-Api-Key' => $apiKey,
                'Content-Type' => 'application/json',
            ],
        ]);

        try {
            $response = $client->post($this->apiUrl, [
                'json' => [
                    'collection' => $collection,
                ],
            ]);

            $body = json_decode((string) $response->getBody(), true);

            return [
                'success' => true,
                'message' => 'Collection uploaded successfully to Postman.',
                'data' => $body ?? [],
            ];
        } catch (GuzzleException $e) {
            return [
                'success' => false,
                'message' => 'Failed to upload collection: ' . $e->getMessage(),
                'data' => [],
            ];
        }
    }
}

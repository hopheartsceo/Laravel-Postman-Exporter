<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Base URL
    |--------------------------------------------------------------------------
    */
    'base_url' => env('APP_URL', 'http://localhost'),

    /*
    |--------------------------------------------------------------------------
    | Default Headers
    |--------------------------------------------------------------------------
    */
    'default_headers' => [
        'Accept' => 'application/json',
        'Content-Type' => 'application/json',
    ],

    /*
    |--------------------------------------------------------------------------
    | Output Path
    |--------------------------------------------------------------------------
    */
    'output_path' => storage_path('app/postman-collection.json'),

    /*
    |--------------------------------------------------------------------------
    | Route Filters
    |--------------------------------------------------------------------------
    */
    'route_filters' => [
        'include_prefixes' => [],
        'exclude_prefixes' => [
            '_ignition',
            '_debugbar',
            'sanctum',
            'telescope',
            'horizon',
        ],
        'include_middleware' => [],
        'exclude_middleware' => [],
    ],

    /*
    |--------------------------------------------------------------------------
    | Middleware to Headers Map
    |--------------------------------------------------------------------------
    */
    'middleware_to_headers_map' => [
        'auth:sanctum' => [
            'Authorization' => 'Bearer {{token}}',
        ],
        'auth:api' => [
            'Authorization' => 'Bearer {{token}}',
        ],
        'auth' => [
            'Authorization' => 'Bearer {{token}}',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Postman API Key & Cloud Sync
    |--------------------------------------------------------------------------
    */
    'postman_api_key' => env('POSTMAN_API_KEY', ''),

    'enable_upload' => false,

    'sync' => [
        'collection_uid' => env('POSTMAN_COLLECTION_UID', ''),
        'workspace_id' => env('POSTMAN_WORKSPACE_ID', ''),
        'auto_wire_auth' => true,
        'preserve_deprecated' => true,
    ],

    /*
    |--------------------------------------------------------------------------
    | Folder Grouping
    |--------------------------------------------------------------------------
    */
    'grouping' => [
        'enabled' => true,
        'strategy' => 'prefix',
        'fallback_folder' => 'general',
        'strip_prefixes' => [
            'api/v1',
            'api/v2',
            'api/v3',
            'api',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Response Examples
    |--------------------------------------------------------------------------
    */
    'responses' => [
        'enabled' => true,
        'fallback_status' => 200,
        'fallback_body' => null,
        'framework_validation_errors' => true,
    ],

    /*
    |--------------------------------------------------------------------------
    | API Contract Guard
    |--------------------------------------------------------------------------
    */
    'contract' => [
        'baseline_path' => base_path('openapi-baseline.json'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Hybrid Go Engine
    |--------------------------------------------------------------------------
    */
    'engine' => [
        'binary_path' => env('LPE_ENGINE_PATH', __DIR__ . '/../bin/lpe-engine'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Runtime Traffic Capture (Zero-Overhead Unix Socket Recorder)
    |--------------------------------------------------------------------------
    */
    'capture' => [
        'enabled' => env('LPE_CAPTURE_ENABLED', false),
        'socket_path' => env('LPE_CAPTURE_SOCKET', '/tmp/lpe-recorder.sock'),
        'recordings_path' => storage_path('app/lpe-recordings.ndjson'),
        'max_body_bytes' => 32768,
    ],

    /*
    |--------------------------------------------------------------------------
    | Concurrent Chaos & Security Audit
    |--------------------------------------------------------------------------
    */
    'audit' => [
        'base_url' => env('LPE_AUDIT_URL', env('APP_URL', 'http://127.0.0.1:8000')),
        'token' => env('LPE_AUDIT_TOKEN', ''),
        'concurrency' => 10,
        'chaos' => true,
        'timeout' => 10,
    ],

    /*
    |--------------------------------------------------------------------------
    | Include Web Routes
    |--------------------------------------------------------------------------
    */
    'include_web_routes' => false,

    /*
    |--------------------------------------------------------------------------
    | Collection Name & Description
    |--------------------------------------------------------------------------
    */
    'collection_name' => env('APP_NAME', 'Laravel') . ' API Collection',

    'collection_description' => 'Auto-generated API collection from Laravel routes.',

];

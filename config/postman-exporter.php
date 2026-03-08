<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Base URL
    |--------------------------------------------------------------------------
    |
    | The base URL used for all requests in the Postman collection.
    | This is set as a Postman variable {{base_url}} in the collection.
    |
    */
    'base_url' => env('APP_URL', 'http://localhost'),

    /*
    |--------------------------------------------------------------------------
    | Default Headers
    |--------------------------------------------------------------------------
    |
    | Headers that will be added to every request in the collection.
    |
    */
    'default_headers' => [
        'Accept' => 'application/json',
        'Content-Type' => 'application/json',
    ],

    /*
    |--------------------------------------------------------------------------
    | Output Path
    |--------------------------------------------------------------------------
    |
    | The default file path where the generated Postman collection will be saved.
    |
    */
    'output_path' => storage_path('app/postman-collection.json'),

    /*
    |--------------------------------------------------------------------------
    | Route Filters
    |--------------------------------------------------------------------------
    |
    | Filter which routes are included in the collection.
    |
    | include_prefixes: Only include routes matching these prefixes (empty = all).
    | exclude_prefixes: Exclude routes matching these prefixes.
    | include_middleware: Only include routes with these middleware.
    | exclude_middleware: Exclude routes with these middleware.
    |
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
    |
    | Map middleware names to headers that should be added to requests
    | that use those middleware.
    |
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
    | Postman API Key
    |--------------------------------------------------------------------------
    |
    | Your Postman API key for uploading collections directly to Postman.
    | Get your key from: https://go.postman.co/settings/me/api-keys
    |
    */
    'postman_api_key' => env('POSTMAN_API_KEY', ''),

    /*
    |--------------------------------------------------------------------------
    | Enable Upload
    |--------------------------------------------------------------------------
    |
    | Whether to automatically upload the collection to Postman after generation.
    |
    */
    'enable_upload' => false,

    /*
    |--------------------------------------------------------------------------
    | Group Routes
    |--------------------------------------------------------------------------
    |
    | Whether to group routes into folders by their URI prefix.
    |
    */
    'group_routes' => true,

    /*
    |--------------------------------------------------------------------------
    | Include Web Routes
    |--------------------------------------------------------------------------
    |
    | Whether to include web (non-API) routes in the collection.
    | By default, only API routes are included.
    |
    */
    'include_web_routes' => false,

    /*
    |--------------------------------------------------------------------------
    | Collection Name
    |--------------------------------------------------------------------------
    |
    | The name of the generated Postman collection.
    |
    */
    'collection_name' => env('APP_NAME', 'Laravel') . ' API Collection',

    /*
    |--------------------------------------------------------------------------
    | Collection Description
    |--------------------------------------------------------------------------
    |
    | Description text for the Postman collection.
    |
    */
    'collection_description' => 'Auto-generated API collection from Laravel routes.',

];

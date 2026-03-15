# Laravel Postman Exporter

[![Laravel](https://img.shields.io/badge/Laravel-10%2B-red.svg)](https://laravel.com)
[![PHP](https://img.shields.io/badge/PHP-8.1%2B-blue.svg)](https://php.net)
[![License](https://img.shields.io/badge/license-MIT-green.svg)](LICENSE)

Automatically generate **Postman Collection v2.1** files and **OpenAPI 3.0** specifications from your Laravel application routes, controllers, and FormRequest validations — complete with **hierarchical folder grouping** and **response examples**.

---

## ✨ Features

- 🔍 **Route Scanning** — Automatically reads all registered API routes
- 📝 **Request Analysis** — Extracts validation rules from FormRequest classes and inline `$request->validate()` calls
- 🎯 **Smart Example Data** — Generates realistic sample values based on validation rules and field names
- 🔐 **Authentication Detection** — Automatically adds auth headers based on middleware (Sanctum, Passport, etc.)
- 📁 **Hierarchical Grouping** — Organizes requests into nested folders based on route prefixes
- 📋 **Response Examples** — Extracts response structures from PHPDoc, API Resources, `response()->json()`, and Eloquent models
- 📄 **OpenAPI Support** — Export your API as a valid OpenAPI 3.0.3 specification
- 🚀 **Postman Upload** — Optionally upload collections directly via the Postman API
- ⚡ **Artisan Command** — Beautiful CLI with progress indicators and colored output

---

## 📦 Installation

You can install the package via composer:

```bash
composer require hopheartsceo/laravel-postman-exporter --dev
```

### Installation from GitHub (Development)

If you haven't published to Packagist yet, add this to your `composer.json`:

```json
"repositories": [
    {
        "type": "vcs",
        "url": "https://github.com/hopheartsceo/laravel-postman-exporter"
    }
],
"require": {
    "hopheartsceo/laravel-postman-exporter": "dev-main"
}
```

Publish the configuration file:

```bash
php artisan vendor:publish --tag=postman-exporter-config
```

---

## 🚀 Usage

### Artisan Command

```bash
# Export as Postman Collection (default)
php artisan postman:export

# Export as OpenAPI 3.0 specification
php artisan postman:export --format=openapi

# Custom output path
php artisan postman:export --output=./docs/api-spec.json

# Group routes by prefix (works for both formats)
php artisan postman:export --group-by-prefix

# Include response examples (Postman only currently)
php artisan postman:export --with-responses
```

### Facade API

```php
use Hopheartsceo\PostmanExporter\Facades\PostmanExporter;

// Generate Postman Collection (default)
$collection = PostmanExporter::generate();

// Generate OpenAPI spec
$openapi = PostmanExporter::generate('openapi');

// Generate and save to file
$path = PostmanExporter::save('/path/to/collection.json');

// Generate and upload to Postman
$result = PostmanExporter::upload('your-api-key');
```

---

## ⚙️ Configuration

After publishing, edit `config/postman-exporter.php`:

| Option | Type | Default | Description |
|--------|------|---------|-------------|
| `base_url` | string | `env('APP_URL')` | Base URL for all requests |
| `default_headers` | array | Accept + Content-Type JSON | Default headers on every request |
| `output_path` | string | `storage/app/postman-collection.json` | Default output path |
| `collection_name` | string | App name + " API Collection" | Name of the Postman collection |
| `grouping` | array | (see below) | Folder grouping configuration |
| `responses` | array | (see below) | Response examples configuration |
| `include_web_routes` | bool | `false` | Include non-API routes |
| `postman_api_key` | string | `''` | Postman API key for uploads |
| `enable_upload` | bool | `false` | Auto-upload after generation |

### Folder Grouping

Routes are grouped into **flat, single-level folders** by the first segment of the URI. No nesting is created — every route belongs to exactly one top-level folder.

```php
'grouping' => [
    'enabled'         => true,
    'strategy'        => 'prefix',      // Only 'prefix' strategy supported
    'fallback_folder' => 'general',     // Folder for unprefixed / root routes
],
```

**How it works:**

| URI | Folder |
|-----|--------|
| `api/users` | `api` |
| `api/users/{id}` | `api` |
| `auth/login` | `auth` |
| `auth/logout` | `auth` |
| `status` | `general` (fallback) |
| `/{id}` | `general` (fallback) |

- The first segment of the URI (`explode('/', $uri)[0]`) becomes the folder name.
- Routes whose first segment is empty or a parameter (e.g. `{id}`) go to the **fallback folder**.
- There are **no root-level requests** — every request lives inside a folder.
- There are **no nested folders** — the structure is always flat.

### Response Examples

Response examples are extracted automatically from your controller methods and attached to each Postman request item.

```php
'responses' => [
    'enabled'         => true,
    'fallback_status' => 200,
    'fallback_body'   => ['message' => 'Success'],
],
```

Or enable at export time with the `--with-responses` flag:

```bash
php artisan postman:export --with-responses
```

**Extraction priority (highest to lowest):**

1. **PHPDoc `@response`** — Parses `@response` tags with optional status codes:
   ```php
   /**
    * @response 200 {"id": 1, "name": "John Doe"}
    * @response {"data": []}
    */
   public function index() { ... }
   ```

2. **API Resource** — Detects `return new UserResource(...)` patterns and reads the Resource's `$fillable`/`$visible` fields.

3. **`response()->json()`** — Parses inline `response()->json([...], 200)` calls to extract the body and status code.

4. **Eloquent Model** — Detects `return User::find(...)` patterns and generates example data from the model's `$fillable` fields.

5. **Fallback** — Uses the configured `fallback_status` and `fallback_body`.

**Generated response format in Postman:**

```json
{
    "response": [
        {
            "name": "Success Response",
            "originalRequest": { "method": "GET", "url": { ... } },
            "status": "OK",
            "code": 200,
            "_postman_previewlanguage": "json",
            "header": [
                { "key": "Content-Type", "value": "application/json" }
            ],
            "body": "{\"id\": 1, \"name\": \"John Doe\"}"
        }
    ]
}
```

### Route Filters

```php
'route_filters' => [
    'include_prefixes'  => [],              // Only include these prefixes
    'exclude_prefixes'  => ['_ignition'],   // Exclude these prefixes
    'include_middleware' => [],              // Only include routes with these middleware
    'exclude_middleware' => [],              // Exclude routes with these middleware
],
```

### Middleware to Headers Map

```php
'middleware_to_headers_map' => [
    'auth:sanctum' => [
        'Authorization' => 'Bearer {{token}}',
    ],
    'auth:api' => [
        'Authorization' => 'Bearer {{token}}',
    ],
],
```

---

## 📄 Example Output

The generated collection follows the [Postman Collection v2.1 schema](https://schema.getpostman.com/json/collection/v2.1.0/collection.json). Folders are flat (single-level) and each request includes response examples:

```json
{
    "info": {
        "name": "My App API Collection",
        "schema": "https://schema.getpostman.com/json/collection/v2.1.0/collection.json"
    },
    "item": [
        {
            "name": "api",
            "item": [
                {
                    "name": "users.index",
                    "request": {
                        "method": "GET",
                        "url": {
                            "raw": "{{base_url}}/api/users",
                            "host": ["{{base_url}}"],
                            "path": ["api", "users"]
                        },
                        "header": [
                            { "key": "Accept", "value": "application/json" },
                            { "key": "Authorization", "value": "Bearer {{token}}" }
                        ]
                    },
                    "response": [
                        {
                            "name": "Success Response",
                            "originalRequest": {
                                "method": "GET",
                                "url": {
                                    "raw": "{{base_url}}/api/users",
                                    "host": ["{{base_url}}"],
                                    "path": ["api", "users"]
                                }
                            },
                            "status": "OK",
                            "code": 200,
                            "_postman_previewlanguage": "json",
                            "header": [
                                { "key": "Content-Type", "value": "application/json" }
                            ],
                            "body": "{\"data\": [{\"id\": 1, \"name\": \"John Doe\"}]}"
                        }
                    ]
                }
            ],
            "description": "Routes for api"
        },
        {
            "name": "general",
            "item": [
                {
                    "name": "GET Status",
                    "request": {
                        "method": "GET",
                        "url": {
                            "raw": "{{base_url}}/status",
                            "host": ["{{base_url}}"],
                            "path": ["status"]
                        }
                    },
                    "response": [
                        {
                            "name": "Success Response",
                            "originalRequest": {
                                "method": "GET",
                                "url": {
                                    "raw": "{{base_url}}/status",
                                    "host": ["{{base_url}}"],
                                    "path": ["status"]
                                }
                            },
                            "status": "OK",
                            "code": 200,
                            "_postman_previewlanguage": "json",
                            "header": [
                                { "key": "Content-Type", "value": "application/json" }
                            ],
                            "body": "{\"message\": \"Success\"}"
                        }
                    ]
                }
            ],
            "description": "Routes for general"
        }
    ],
    "variable": [
        { "key": "base_url", "value": "http://localhost" },
        { "key": "token", "value": "your-auth-token-here" }
    ]
}
```

> See [`examples/sample-collection.json`](examples/sample-collection.json) for a full example with multiple folders and response examples.

---

## 🧪 Testing

```bash
composer test
# or
vendor/bin/phpunit
```

---

## 🏗️ Architecture

| Service | Responsibility |
|---------|---------------|
| `RouteScannerService` | Scans Laravel routes via the Router; extracts return types, PHPDoc, and API Resource usage |
| `RequestAnalyzerService` | Extracts FormRequest/inline validation rules |
| `ValidationParserService` | Parses validation rules into structured format |
| `ExampleDataGeneratorService` | Generates realistic sample values |
| `FolderOrganizerService` | Groups routes into flat, single-level folders by first URI segment |
| `ResponseExtractorService` | Analyzes controller methods to extract response structures (PHPDoc → API Resource → JSON → Model → Fallback) |
| `ExampleResponseGeneratorService` | Converts extracted response data into Postman-formatted response arrays |
| `PostmanCollectionBuilderService` | Builds Postman v2.1 JSON structure with folders and response examples |
| `PostmanUploaderService` | Uploads collections to Postman API |

---

## 📋 Requirements

- PHP 8.1+
- Laravel 10, 11, or 12

---

## 📝 License

MIT License. See [LICENSE](LICENSE) for details.

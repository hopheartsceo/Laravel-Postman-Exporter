# Laravel Postman Exporter (v3.0 Hybrid Engine)

[![Tests](https://github.com/hopheartsceo/Laravel-Postman-Exporter/actions/workflows/tests.yml/badge.svg)](https://github.com/hopheartsceo/Laravel-Postman-Exporter/actions/workflows/tests.yml)
[![Laravel](https://img.shields.io/badge/Laravel-10%2B-red.svg)](https://laravel.com)
[![PHP](https://img.shields.io/badge/PHP-8.1%2B-blue.svg)](https://php.net)
[![Go Engine](https://img.shields.io/badge/Go_Engine-1.27%2B-00ADD8.svg)](https://go.dev)
[![License](https://img.shields.io/badge/license-MIT-green.svg)](LICENSE)

A **Hybrid Static AST (Laravel) + Native Concurrency Engine (Go)** that turns your Laravel API into a living, self-healing Postman workspace — combining static code inspection with **3-Way Smart Cloud Sync**, **Zero-Overhead Runtime Traffic Capture**, and **Concurrent Chaos & Security Auditing**.

---

## Why v3.0?

| Feature | Traditional Exporters | Laravel Postman Exporter v3.0 |
| :--- | :--- | :--- |
| **Postman Cloud Sync** | Overwrites team work or creates duplicates | **3-Way Smart Merge (`postman:sync`)** — preserves manual `Tests`, `Pre-request Scripts`, headers & variables |
| **Auth Token Wiring** | Manual copy-pasting after every login | **Auto-Wire Scripts** — injects `pm.collectionVariables.set("token", ...)` on login endpoints automatically |
| **Response Examples** | Static guesses only | **Runtime Recorder (`postman:record`)** — captures real `200/201/422` responses during `artisan test` with secret masking |
| **Pre-Deploy Verification** | Static schema diff only | **Concurrent Chaos Fuzzer (`postman:audit`)** — fires parallel Go workers to detect `500` crashes, SQL trace leaks & missing auth |
| **Zero Setup Runtime** | Requires external toolchains | Precompiled single-file Go binaries included for **Linux, macOS (Apple Silicon), and Windows** |
```

## Hybrid Engine Commands

```bash
php artisan postman:sync              # 3-Way Cloud Sync (or --local-only)
php artisan postman:record --fresh    # Live Unix Socket response capture + secret redaction
php artisan postman:audit --base-url=http://127.0.0.1:8000 --concurrency=15
php artisan postman:export --format=postman
php artisan postman:check --write-baseline && php artisan postman:check
```

---

## Usage

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

# Save the current API contract as a baseline
php artisan postman:check --write-baseline

# Fail when breaking API changes are detected
php artisan postman:check

# Compare against a custom OpenAPI baseline
php artisan postman:check --against=docs/openapi-baseline.json
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

## Configuration

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
    'fallback_body'   => null,
],
```

Or enable at export time with the `--with-responses` flag:

```bash
php artisan postman:export --with-responses
```

**Response discovery sources:**

The exporter combines distinct HTTP status codes from multiple sources instead of stopping at the first match. When two sources describe the same status, the earlier explicit source wins.

1. **PHPDoc `@response`** — Parses one or more `@response` tags with optional status codes:
   ```php
   /**
    * @response 200 {"id": 1, "name": "John Doe"}
    * @response {"data": []}
    */
   public function index() { ... }
   ```

2. **API Resource** — Detects direct Resource returns and builds response schemas from the Resource's `toArray()` structure.

3. **Direct Laravel responses** — Parses inline `response()->json([...], 200)` and `response()->noContent()` returns, including their status codes.

4. **Response Pipeline Tracing** — Follows custom `$this->helper(...)`, inherited/trait helpers, and static response helper methods until it reaches `response()->json(...)` or `response()->noContent()`.

5. **Eloquent Model** — Detects `return User::find(...)` patterns and generates example data from the model's `$fillable` fields.

6. **Fallback** — Uses the configured fallback status and body. By default, the body is `null` so unknown responses are not documented as fabricated success payloads.

Response pipelines are analyzed statically. Controller endpoints and response helpers are not executed during export or contract checks.

When validation rules are detected, the exporter also adds Laravel's default JSON `422` validation-error contract. This inference is skipped when a FormRequest overrides `failedValidation()`, and it can be disabled with `responses.framework_validation_errors`.

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

## Example Output

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
| `GoEngineBridgeService` | Bridges Laravel AST output and runtime streams with the native Go binary |
| `SyncPostmanCommand` | Executes 3-way smart merge with Postman Cloud and auto-wires login token scripts |
| `RecordTrafficCommand` | Captures live HTTP traffic over Unix Domain Socket with automatic secret redaction |
| `AuditApiCommand` | Runs concurrent Go worker goroutines for auth-bypass and chaos payload fuzzing |

---

## Requirements

- PHP 8.1+
- Laravel 10, 11, or 12

---

## License

MIT License. See [LICENSE](LICENSE) for details.

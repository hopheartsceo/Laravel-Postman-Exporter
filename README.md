# Laravel Postman Exporter

[![Laravel](https://img.shields.io/badge/Laravel-10%2B-red.svg)](https://laravel.com)
[![PHP](https://img.shields.io/badge/PHP-8.1%2B-blue.svg)](https://php.net)
[![License](https://img.shields.io/badge/license-MIT-green.svg)](LICENSE)

Automatically generate **Postman Collection v2.1** files from your Laravel application routes, controllers, and FormRequest validations.

---

## ✨ Features

- 🔍 **Route Scanning** — Automatically reads all registered API routes
- 📝 **Request Analysis** — Extracts validation rules from FormRequest classes and inline `$request->validate()` calls
- 🎯 **Smart Example Data** — Generates realistic sample values based on validation rules and field names
- 🔐 **Authentication Detection** — Automatically adds auth headers based on middleware (Sanctum, Passport, etc.)
- 📁 **Folder Grouping** — Organizes requests into folders by route prefix
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
# Basic export
php artisan postman:export

# Custom output path
php artisan postman:export --output=./docs/api-collection.json

# Group routes by prefix
php artisan postman:export --group-by-prefix

# Include web routes
php artisan postman:export --include-web-routes

# Upload to Postman
php artisan postman:export --upload --api-key=your-postman-api-key
```

### Facade API

```php
use Hopheartsceo\PostmanExporter\Facades\PostmanExporter;

// Generate and get array
$collection = PostmanExporter::generate();

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
| `group_routes` | bool | `true` | Group routes into folders by prefix |
| `include_web_routes` | bool | `false` | Include non-API routes |
| `postman_api_key` | string | `''` | Postman API key for uploads |
| `enable_upload` | bool | `false` | Auto-upload after generation |

### Route Filters

```php
'route_filters' => [
    'include_prefixes' => [],              // Only include these prefixes
    'exclude_prefixes' => ['_ignition'],   // Exclude these prefixes
    'include_middleware' => [],             // Only include routes with these middleware
    'exclude_middleware' => [],             // Exclude routes with these middleware
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

The generated collection follows the [Postman Collection v2.1 schema](https://schema.getpostman.com/json/collection/v2.1.0/collection.json):

```json
{
    "info": {
        "name": "My App API Collection",
        "schema": "https://schema.getpostman.com/json/collection/v2.1.0/collection.json"
    },
    "item": [
        {
            "name": "Api / Users",
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
                    }
                }
            ]
        }
    ],
    "variable": [
        { "key": "base_url", "value": "http://localhost" },
        { "key": "token", "value": "your-auth-token-here" }
    ]
}
```

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
| `RouteScannerService` | Scans Laravel routes via the Router |
| `RequestAnalyzerService` | Extracts FormRequest/inline validation rules |
| `ValidationParserService` | Parses validation rules into structured format |
| `ExampleDataGeneratorService` | Generates realistic sample values |
| `PostmanCollectionBuilderService` | Builds Postman v2.1 JSON structure |
| `PostmanUploaderService` | Uploads collections to Postman API |

---

## 📋 Requirements

- PHP 8.1+
- Laravel 10, 11, or 12

---

## 📝 License

MIT License. See [LICENSE](LICENSE) for details.

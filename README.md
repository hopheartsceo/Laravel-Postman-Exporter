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

## Running Tests

```bash
composer test
```

## License

MIT — Built by [Wessaal](https://github.com/hopheartsceo).

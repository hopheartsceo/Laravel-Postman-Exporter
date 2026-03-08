<?php

declare(strict_types=1);

namespace Wessaal\PostmanExporter\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * @method static array generate()
 * @method static string save(?string $path = null)
 * @method static array upload(?string $apiKey = null)
 * @method static array|null getCollection()
 *
 * @see \Wessaal\PostmanExporter\PostmanExporterManager
 */
class PostmanExporter extends Facade
{
    /**
     * Get the registered name of the component.
     */
    protected static function getFacadeAccessor(): string
    {
        return 'postman-exporter';
    }
}

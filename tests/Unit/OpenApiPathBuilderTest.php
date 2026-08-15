<?php

declare(strict_types=1);

namespace Hopheartsceo\PostmanExporter\Tests\Unit;

use Hopheartsceo\PostmanExporter\Builders\OpenApiPathBuilder;
use Hopheartsceo\PostmanExporter\Mappers\ValidationToJsonSchemaMapper;
use Hopheartsceo\PostmanExporter\Services\ResponseExtractorService;
use Hopheartsceo\PostmanExporter\Tests\TestCase;

class OpenApiPathBuilderTest extends TestCase
{
    public function test_uses_multipart_form_data_for_file_fields(): void
    {
        $builder = new OpenApiPathBuilder(
            new ValidationToJsonSchemaMapper(),
            new ResponseExtractorService()
        );

        $paths = $builder->build([
            [
                'method' => 'POST',
                'uri' => 'api/profile',
                'name' => 'profile.update',
                'controller' => null,
                'controller_method' => null,
                'parameters' => [],
                'query_params' => [],
                'middleware' => [],
                'body_params_parsed' => [
                    'name' => [
                        'type' => 'string',
                        'required' => true,
                        'rules' => ['required', 'string'],
                        'example' => 'Ahmed',
                    ],
                    'avatar' => [
                        'type' => 'file',
                        'required' => true,
                        'rules' => ['required', 'image', 'max:2048'],
                        'example' => '(binary)',
                    ],
                ],
            ],
        ]);

        $content = $paths['/api/profile']['post']['requestBody']['content'];

        $this->assertArrayHasKey('multipart/form-data', $content);
        $this->assertArrayNotHasKey('application/json', $content);
        $this->assertSame(
            'binary',
            $content['multipart/form-data']['schema']['properties']['avatar']['format']
        );
    }
}

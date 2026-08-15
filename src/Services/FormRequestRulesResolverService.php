<?php

declare(strict_types=1);

namespace Hopheartsceo\PostmanExporter\Services;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;

class FormRequestRulesResolverService
{
    public function __construct(
        protected Application $app,
        protected ExampleDataGeneratorService $exampleGenerator,
    ) {}

    /**
     * Resolve FormRequest rules using the route HTTP method, route name,
     * route parameters, and Laravel's container for method injection.
     *
     * @param  class-string<FormRequest>  $formRequestClass
     * @param  array<string, mixed>  $routeData
     * @return array<string, mixed>
     */
    public function resolve(string $formRequestClass, array $routeData): array
    {
        if (! is_subclass_of($formRequestClass, FormRequest::class)) {
            return [];
        }

        $method = strtoupper((string) ($routeData['method'] ?? 'GET'));
        $uri = ltrim((string) ($routeData['uri'] ?? '/'), '/');
        $requestUri = $this->materializeUri($uri, $routeData['parameters'] ?? []);

        $baseRequest = Request::create('/' . ltrim($requestUri, '/'), $method);
        $route = new Route([$method], $uri, static fn () => null);

        if (! empty($routeData['name'])) {
            $route->name((string) $routeData['name']);
        }

        $route->bind($baseRequest);

        $reflection = new \ReflectionClass($formRequestClass);

        /** @var FormRequest $formRequest */
        $formRequest = $reflection->newInstanceWithoutConstructor();
        $formRequest = FormRequest::createFrom($baseRequest, $formRequest);
        $formRequest->setContainer($this->app);
        $formRequest->setRouteResolver(static fn () => $route);

        $rules = $this->app->call([$formRequest, 'rules']);

        return is_array($rules) ? $rules : [];
    }

    /**
     * Build a concrete URI so Laravel can bind placeholder route parameters.
     *
     * @param  array<int, string>  $parameters
     */
    protected function materializeUri(string $uri, array $parameters): string
    {
        foreach ($parameters as $parameter) {
            $value = rawurlencode((string) $this->exampleGenerator->generateForRouteParam($parameter));

            $uri = str_replace(
                ['{' . $parameter . '}', '{' . $parameter . '?}'],
                $value,
                $uri
            );
        }

        return preg_replace('/\{[^}]+\??\}/', '1', $uri) ?? $uri;
    }
}

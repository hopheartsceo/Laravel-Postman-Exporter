<?php

declare(strict_types=1);

namespace Hopheartsceo\PostmanExporter\Tests\Feature;

use Hopheartsceo\PostmanExporter\Services\FormRequestRulesResolverService;
use Hopheartsceo\PostmanExporter\Tests\TestCase;
use Illuminate\Foundation\Http\FormRequest;

class FormRequestRulesResolverTest extends TestCase
{
    public function test_rules_use_the_actual_http_method(): void
    {
        $resolver = $this->app->make(FormRequestRulesResolverService::class);

        $postRules = $resolver->resolve(MethodAwareRequest::class, $this->routeData('POST'));
        $putRules = $resolver->resolve(MethodAwareRequest::class, $this->routeData('PUT'));

        $this->assertArrayHasKey('create_only', $postRules);
        $this->assertArrayNotHasKey('create_only', $putRules);
        $this->assertArrayHasKey('name', $postRules);
        $this->assertArrayHasKey('name', $putRules);
    }

    public function test_rules_method_dependencies_are_resolved_from_the_container(): void
    {
        $resolver = $this->app->make(FormRequestRulesResolverService::class);
        $rules = $resolver->resolve(InjectedRulesRequest::class, $this->routeData('POST'));

        $this->assertSame(['required', 'email'], $rules['email']);
    }

    public function test_route_parameters_and_route_name_are_available(): void
    {
        $resolver = $this->app->make(FormRequestRulesResolverService::class);
        $route = $this->routeData('POST');
        $route['uri'] = 'api/accounts/{account}/users';
        $route['name'] = 'accounts.users.store';
        $route['parameters'] = ['account'];

        $rules = $resolver->resolve(RouteAwareRequest::class, $route);

        $this->assertSame('required|in:1', $rules['account_id']);
        $this->assertSame('required|string', $rules['route_named_field']);
    }

    /** @return array<string, mixed> */
    protected function routeData(string $method): array
    {
        return [
            'method' => $method,
            'uri' => 'api/users',
            'name' => 'users.store',
            'parameters' => [],
        ];
    }
}

class MethodAwareRequest extends FormRequest
{
    public function rules(): array
    {
        $rules = ['name' => 'required|string'];

        if ($this->isMethod('POST')) {
            $rules['create_only'] = 'required|string';
        }

        return $rules;
    }
}

class InjectedRuleSource
{
    public function rules(): array
    {
        return ['email' => ['required', 'email']];
    }
}

class InjectedRulesRequest extends FormRequest
{
    public function rules(InjectedRuleSource $source): array
    {
        return $source->rules();
    }
}

class RouteAwareRequest extends FormRequest
{
    public function rules(): array
    {
        $rules = [
            'account_id' => 'required|in:' . $this->route('account'),
        ];

        if ($this->routeIs('accounts.users.store')) {
            $rules['route_named_field'] = 'required|string';
        }

        return $rules;
    }
}

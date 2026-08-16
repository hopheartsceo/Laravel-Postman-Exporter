<?php

declare(strict_types=1);

namespace Hopheartsceo\PostmanExporter\Services;

class ContractDiffService
{
    /** @var array<int, string> */
    protected array $httpMethods = ['get', 'post', 'put', 'patch', 'delete', 'options', 'head'];

    /**
     * Return breaking changes between two OpenAPI documents.
     *
     * @param  array<string, mixed>  $baseline
     * @param  array<string, mixed>  $current
     * @return array<int, string>
     */
    public function diff(array $baseline, array $current): array
    {
        $changes = [];
        $baselinePaths = $baseline['paths'] ?? [];
        $currentPaths = $current['paths'] ?? [];

        foreach ($baselinePaths as $path => $pathItem) {
            if (! is_array($pathItem)) {
                continue;
            }

            foreach ($this->httpMethods as $method) {
                if (! isset($pathItem[$method]) || ! is_array($pathItem[$method])) {
                    continue;
                }

                $label = strtoupper($method) . ' ' . $path;

                if (! isset($currentPaths[$path][$method]) || ! is_array($currentPaths[$path][$method])) {
                    $changes[] = $label . ': endpoint removed';
                    continue;
                }

                $baselineOperation = $pathItem[$method];
                $currentOperation = $currentPaths[$path][$method];

                $changes = array_merge(
                    $changes,
                    $this->compareParameters($label, $baselineOperation, $currentOperation),
                    $this->compareRequestBody($label, $baselineOperation, $currentOperation),
                    $this->compareResponses($label, $baselineOperation, $currentOperation),
                );
            }
        }

        return array_values(array_unique($changes));
    }

    /** @return array<int, string> */
    protected function compareParameters(string $label, array $baseline, array $current): array
    {
        $changes = [];
        $baselineParameters = $this->parameterMap($baseline['parameters'] ?? []);
        $currentParameters = $this->parameterMap($current['parameters'] ?? []);

        foreach ($currentParameters as $key => $parameter) {
            if (! ($parameter['required'] ?? false)) {
                continue;
            }

            $wasRequired = (bool) ($baselineParameters[$key]['required'] ?? false);

            if (! isset($baselineParameters[$key]) || ! $wasRequired) {
                $changes[] = $label . ': required parameter added: ' . $key;
            }
        }

        foreach ($baselineParameters as $key => $parameter) {
            if (isset($currentParameters[$key])) {
                continue;
            }

            $changes[] = $label . ': parameter removed: ' . $key;
        }

        return $changes;
    }

    /** @return array<string, array<string, mixed>> */
    protected function parameterMap(array $parameters): array
    {
        $map = [];

        foreach ($parameters as $parameter) {
            if (! is_array($parameter) || empty($parameter['name']) || empty($parameter['in'])) {
                continue;
            }

            $map[$parameter['in'] . ':' . $parameter['name']] = $parameter;
        }

        return $map;
    }

    /** @return array<int, string> */
    protected function compareRequestBody(string $label, array $baseline, array $current): array
    {
        $baselineSchema = $this->contentSchema($baseline['requestBody']['content'] ?? []);
        $currentSchema = $this->contentSchema($current['requestBody']['content'] ?? []);

        if ($baselineSchema === null && $currentSchema === null) {
            return [];
        }

        if ($baselineSchema !== null && $currentSchema === null) {
            return [$label . ': request body removed'];
        }

        if ($baselineSchema === null || $currentSchema === null) {
            return [];
        }

        return $this->compareSchema($label, $baselineSchema, $currentSchema, 'request');
    }

    /** @return array<int, string> */
    protected function compareResponses(string $label, array $baseline, array $current): array
    {
        $changes = [];
        $baselineResponses = $baseline['responses'] ?? [];
        $currentResponses = $current['responses'] ?? [];

        foreach ($baselineResponses as $status => $response) {
            if (! isset($currentResponses[$status])) {
                $changes[] = $label . ': response status removed: ' . $status;
                continue;
            }

            if (! is_array($response) || ! is_array($currentResponses[$status])) {
                continue;
            }

            $baselineSchema = $this->contentSchema($response['content'] ?? []);
            $currentSchema = $this->contentSchema($currentResponses[$status]['content'] ?? []);

            if ($baselineSchema !== null && $currentSchema === null) {
                $changes[] = $label . ': response body removed for status ' . $status;
                continue;
            }

            if ($baselineSchema !== null && $currentSchema !== null) {
                $changes = array_merge(
                    $changes,
                    $this->compareSchema($label, $baselineSchema, $currentSchema, 'response', (string) $status)
                );
            }
        }

        return $changes;
    }

    /** @return array<string, mixed>|null */
    protected function contentSchema(array $content): ?array
    {
        foreach (['application/json', 'multipart/form-data', 'application/x-www-form-urlencoded'] as $type) {
            if (isset($content[$type]['schema']) && is_array($content[$type]['schema'])) {
                return $content[$type]['schema'];
            }
        }

        foreach ($content as $media) {
            if (is_array($media) && isset($media['schema']) && is_array($media['schema'])) {
                return $media['schema'];
            }
        }

        return null;
    }

    /** @return array<int, string> */
    protected function compareSchema(
        string $label,
        array $baseline,
        array $current,
        string $direction,
        ?string $status = null,
        string $fieldPath = '',
    ): array {
        $changes = [];
        $baselineType = $baseline['type'] ?? null;
        $currentType = $current['type'] ?? null;

        if ($baselineType !== null && $currentType !== null && $baselineType !== $currentType) {
            $target = $fieldPath !== '' ? $fieldPath : 'body';
            $changes[] = $this->schemaMessage($label, $direction, $status, "type changed for {$target}: {$baselineType} -> {$currentType}");
            return $changes;
        }

        if ($baselineType === 'array' || $currentType === 'array') {
            $baselineItems = is_array($baseline['items'] ?? null) ? $baseline['items'] : [];
            $currentItems = is_array($current['items'] ?? null) ? $current['items'] : [];

            return array_merge(
                $changes,
                $this->compareSchema($label, $baselineItems, $currentItems, $direction, $status, $fieldPath . '[]')
            );
        }

        $baselineProperties = is_array($baseline['properties'] ?? null) ? $baseline['properties'] : [];
        $currentProperties = is_array($current['properties'] ?? null) ? $current['properties'] : [];

        $currentRequired = array_values(array_filter($current['required'] ?? [], 'is_string'));
        $baselineRequired = array_values(array_filter($baseline['required'] ?? [], 'is_string'));

        if ($direction === 'request') {
            foreach (array_diff($currentRequired, $baselineRequired) as $requiredField) {
                $changes[] = $label . ': required request field added: ' . $this->joinPath($fieldPath, $requiredField);
            }
        } elseif ($direction === 'response') {
            foreach (array_diff($baselineRequired, $currentRequired) as $requiredField) {
                if (! isset($currentProperties[$requiredField])) {
                    continue;
                }

                $changes[] = $this->schemaMessage(
                    $label,
                    $direction,
                    $status,
                    'field became optional: ' . $this->joinPath($fieldPath, $requiredField),
                );
            }
        }

        foreach ($baselineProperties as $name => $baselineProperty) {
            if (! isset($currentProperties[$name])) {
                $path = $this->joinPath($fieldPath, (string) $name);
                $changes[] = $this->schemaMessage($label, $direction, $status, "field removed: {$path}");
                continue;
            }

            if (! is_array($baselineProperty) || ! is_array($currentProperties[$name])) {
                continue;
            }

            $changes = array_merge(
                $changes,
                $this->compareSchema(
                    $label,
                    $baselineProperty,
                    $currentProperties[$name],
                    $direction,
                    $status,
                    $this->joinPath($fieldPath, (string) $name),
                )
            );
        }

        return $changes;
    }

    protected function schemaMessage(string $label, string $direction, ?string $status, string $message): string
    {
        if ($direction === 'response') {
            return $label . ': response ' . ($status ?? 'default') . ' ' . $message;
        }

        return $label . ': request ' . $message;
    }

    protected function joinPath(string $prefix, string $name): string
    {
        return $prefix === '' ? $name : $prefix . '.' . $name;
    }
}

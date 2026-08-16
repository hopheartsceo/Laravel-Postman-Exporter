<?php

declare(strict_types=1);

namespace Hopheartsceo\PostmanExporter\Services;

use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Http\Resources\Json\ResourceCollection;
use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\Array_;
use PhpParser\Node\ArrayItem;
use PhpParser\Node\Expr\ArrowFunction;
use PhpParser\Node\Expr\Closure;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\New_;
use PhpParser\Node\Expr\PropertyFetch;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Name;
use PhpParser\Node\Scalar;
use PhpParser\Node\Stmt;
use PhpParser\NodeFinder;
use PhpParser\Parser;
use PhpParser\ParserFactory;
use ReflectionClass;
use ReflectionMethod;

class ResourceSchemaAnalyzerService
{
    protected Parser $parser;

    protected NodeFinder $nodeFinder;

    protected ExampleDataGeneratorService $exampleGenerator;

    /** @var array<string, array{schema: array<string, mixed>, example: mixed}|null> */
    protected array $payloadCache = [];

    /** @var array<string, array<string, string>> */
    protected array $useStatementCache = [];

    public function __construct(?ExampleDataGeneratorService $exampleGenerator = null)
    {
        $this->exampleGenerator = $exampleGenerator ?? new ExampleDataGeneratorService();
        $this->parser = (new ParserFactory())->createForNewestSupportedVersion();
        $this->nodeFinder = new NodeFinder();
    }

    /**
     * Analyze a resource as it appears in an HTTP response.
     *
     * @return array{schema: array<string, mixed>, example: mixed}|null
     */
    public function analyzeResponse(string $resourceClass, bool $collection = false, bool $paginated = false): ?array
    {
        if (! class_exists($resourceClass)) {
            return null;
        }

        if (is_subclass_of($resourceClass, ResourceCollection::class)) {
            return $this->analyzeResourceCollectionResponse($resourceClass, $paginated);
        }

        if (! is_subclass_of($resourceClass, JsonResource::class)) {
            return null;
        }

        $payload = $this->analyzePayload($resourceClass);
        if ($payload === null) {
            return null;
        }

        if ($collection) {
            $payload = [
                'schema' => [
                    'type' => 'array',
                    'items' => $payload['schema'],
                ],
                'example' => [$payload['example']],
            ];
        }

        if ($paginated) {
            return $this->buildPaginatedContract($payload);
        }

        return $this->applyTopLevelResponseShape($resourceClass, $payload);
    }

    /**
     * Analyze the array returned by a JsonResource without applying outer response wrapping.
     *
     * @return array{schema: array<string, mixed>, example: mixed}|null
     */
    protected function analyzePayload(string $resourceClass, int $depth = 0, array $visited = []): ?array
    {
        if ($depth > 8 || isset($visited[$resourceClass])) {
            return null;
        }

        if (array_key_exists($resourceClass, $this->payloadCache)) {
            return $this->payloadCache[$resourceClass];
        }

        try {
            $reflection = new ReflectionClass($resourceClass);
        } catch (\ReflectionException) {
            return null;
        }

        if (! $reflection->hasMethod('toArray')) {
            return null;
        }

        $method = $reflection->getMethod('toArray');
        if ($method->getDeclaringClass()->getName() !== $resourceClass) {
            return null;
        }

        $expression = $this->extractReturnExpression($method);
        if (! $expression instanceof Expr) {
            return null;
        }

        $visited[$resourceClass] = true;
        $contract = $this->inferExpression($expression, 'data', $reflection, $depth, $visited);

        if (($contract['schema']['type'] ?? null) !== 'object') {
            return null;
        }

        $result = [
            'schema' => $contract['schema'],
            'example' => $contract['example'],
        ];

        $this->payloadCache[$resourceClass] = $result;

        return $result;
    }

    /**
     * @return array{schema: array<string, mixed>, example: mixed}|null
     */
    protected function analyzeResourceCollectionResponse(string $collectionClass, bool $paginated): ?array
    {
        try {
            $reflection = new ReflectionClass($collectionClass);
        } catch (\ReflectionException) {
            return null;
        }

        $itemClass = $this->resolveCollectedResourceClass($reflection);
        $itemContract = $itemClass ? $this->analyzePayload($itemClass) : null;

        $payload = null;
        if ($reflection->hasMethod('toArray')) {
            $method = $reflection->getMethod('toArray');
            if ($method->getDeclaringClass()->getName() === $collectionClass) {
                $expression = $this->extractReturnExpression($method);
                if ($expression instanceof Expr) {
                    $contract = $this->inferExpression(
                        $expression,
                        'data',
                        $reflection,
                        0,
                        [$collectionClass => true],
                        $itemContract,
                    );

                    $payload = [
                        'schema' => $contract['schema'],
                        'example' => $contract['example'],
                    ];
                }
            }
        }

        if ($payload === null && $itemContract !== null) {
            $payload = [
                'schema' => [
                    'type' => 'array',
                    'items' => $itemContract['schema'],
                ],
                'example' => [$itemContract['example']],
            ];
        }

        if ($payload === null) {
            return null;
        }

        if ($paginated) {
            $items = $payload;
            if (($payload['schema']['type'] ?? null) === 'object'
                && isset($payload['schema']['properties']['data'])
                && is_array($payload['schema']['properties']['data'])) {
                $items = [
                    'schema' => $payload['schema']['properties']['data'],
                    'example' => is_array($payload['example']) ? ($payload['example']['data'] ?? []) : [],
                ];
            }

            return $this->buildPaginatedContract($items);
        }

        return $this->applyTopLevelResponseShape($collectionClass, $payload);
    }

    /**
     * @param  array{schema: array<string, mixed>, example: mixed}  $payload
     * @return array{schema: array<string, mixed>, example: mixed}
     */
    protected function applyTopLevelResponseShape(string $resourceClass, array $payload): array
    {
        $wrap = $this->resolveWrapKey($resourceClass);
        $schema = $payload['schema'];
        $example = $payload['example'];

        if ($wrap !== null) {
            $alreadyWrapped = ($schema['type'] ?? null) === 'object'
                && isset($schema['properties'][$wrap]);

            if (! $alreadyWrapped) {
                $schema = [
                    'type' => 'object',
                    'properties' => [
                        $wrap => $schema,
                    ],
                    'required' => [$wrap],
                ];
                $example = [$wrap => $example];
            }
        }

        $metadata = $this->analyzeTopLevelMetadata($resourceClass);
        if ($metadata !== null && ($schema['type'] ?? null) === 'object') {
            $schema['properties'] = array_merge(
                $schema['properties'] ?? [],
                $metadata['schema']['properties'] ?? [],
            );

            $required = array_merge(
                $schema['required'] ?? [],
                $metadata['schema']['required'] ?? [],
            );

            if ($required !== []) {
                $schema['required'] = array_values(array_unique($required));
            }

            if (is_array($example) && is_array($metadata['example'])) {
                $example = array_merge($example, $metadata['example']);
            }
        }

        return [
            'schema' => $schema,
            'example' => $example,
        ];
    }

    /**
     * @return array{schema: array<string, mixed>, example: mixed}|null
     */
    protected function analyzeTopLevelMetadata(string $resourceClass): ?array
    {
        try {
            $reflection = new ReflectionClass($resourceClass);
            if (! $reflection->hasMethod('with')) {
                return null;
            }

            $method = $reflection->getMethod('with');
            if ($method->getDeclaringClass()->getName() !== $resourceClass) {
                return null;
            }

            $expression = $this->extractReturnExpression($method);
            if (! $expression instanceof Expr) {
                return null;
            }

            $contract = $this->inferExpression(
                $expression,
                'meta',
                $reflection,
                0,
                [$resourceClass => true],
            );

            if (($contract['schema']['type'] ?? null) !== 'object') {
                return null;
            }

            return [
                'schema' => $contract['schema'],
                'example' => $contract['example'],
            ];
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * @param  array{schema: array<string, mixed>, example: mixed}  $items
     * @return array{schema: array<string, mixed>, example: mixed}
     */
    protected function buildPaginatedContract(array $items): array
    {
        $linksSchema = [
            'type' => 'object',
            'properties' => [
                'first' => ['type' => 'string', 'nullable' => true],
                'last' => ['type' => 'string', 'nullable' => true],
                'prev' => ['type' => 'string', 'nullable' => true],
                'next' => ['type' => 'string', 'nullable' => true],
            ],
        ];

        $metaSchema = [
            'type' => 'object',
            'properties' => [
                'current_page' => ['type' => 'integer'],
                'from' => ['type' => 'integer', 'nullable' => true],
                'last_page' => ['type' => 'integer'],
                'path' => ['type' => 'string'],
                'per_page' => ['type' => 'integer'],
                'to' => ['type' => 'integer', 'nullable' => true],
                'total' => ['type' => 'integer'],
            ],
        ];

        return [
            'schema' => [
                'type' => 'object',
                'properties' => [
                    'data' => $items['schema'],
                    'links' => $linksSchema,
                    'meta' => $metaSchema,
                ],
                'required' => ['data', 'links', 'meta'],
            ],
            'example' => [
                'data' => $items['example'],
                'links' => [
                    'first' => 'https://example.com/api/resources?page=1',
                    'last' => 'https://example.com/api/resources?page=1',
                    'prev' => null,
                    'next' => null,
                ],
                'meta' => [
                    'current_page' => 1,
                    'from' => 1,
                    'last_page' => 1,
                    'path' => 'https://example.com/api/resources',
                    'per_page' => 15,
                    'to' => 1,
                    'total' => 1,
                ],
            ],
        ];
    }

    /**
     * @param  array<string, bool>  $visited
     * @param  array{schema: array<string, mixed>, example: mixed}|null  $collectionItemContract
     * @return array{schema: array<string, mixed>, example: mixed, optional: bool}
     */
    protected function inferExpression(
        Expr $expression,
        string $fieldName,
        ReflectionClass $context,
        int $depth,
        array $visited,
        ?array $collectionItemContract = null,
    ): array {
        if ($expression instanceof Array_) {
            return $this->inferArray($expression, $context, $depth, $visited, $collectionItemContract);
        }

        if ($expression instanceof Scalar\String_) {
            return $this->contract(['type' => 'string'], $expression->value);
        }

        if ($expression instanceof Scalar\Int_) {
            return $this->contract(['type' => 'integer'], $expression->value);
        }

        if ($expression instanceof Scalar\Float_) {
            return $this->contract(['type' => 'number', 'format' => 'float'], $expression->value);
        }

        if ($expression instanceof Expr\ConstFetch) {
            $name = strtolower($expression->name->toString());

            if ($name === 'true' || $name === 'false') {
                return $this->contract(['type' => 'boolean'], $name === 'true');
            }

            if ($name === 'null') {
                return $this->contract(['type' => 'string', 'nullable' => true], null);
            }
        }

        if ($expression instanceof Expr\Cast\Int_) {
            return $this->contract(['type' => 'integer'], 1);
        }

        if ($expression instanceof Expr\Cast\Double) {
            return $this->contract(['type' => 'number'], 1.0);
        }

        if ($expression instanceof Expr\Cast\Bool_) {
            return $this->contract(['type' => 'boolean'], true);
        }

        if ($expression instanceof Expr\Cast\String_) {
            return $this->contract(['type' => 'string'], 'example');
        }

        if ($expression instanceof New_) {
            $resourceClass = $this->resolveName($expression->class, $context);
            if ($resourceClass && is_subclass_of($resourceClass, JsonResource::class)) {
                $nested = $this->analyzePayload($resourceClass, $depth + 1, $visited);
                if ($nested !== null) {
                    return [
                        'schema' => $nested['schema'],
                        'example' => $nested['example'],
                        'optional' => $this->containsConditionalExpression($expression),
                    ];
                }
            }
        }

        if ($expression instanceof StaticCall) {
            $method = $this->identifierName($expression->name);
            if ($method === 'collection') {
                $resourceClass = $this->resolveName($expression->class, $context);
                if ($resourceClass && is_subclass_of($resourceClass, JsonResource::class)) {
                    $nested = $this->analyzePayload($resourceClass, $depth + 1, $visited);
                    if ($nested !== null) {
                        return [
                            'schema' => [
                                'type' => 'array',
                                'items' => $nested['schema'],
                            ],
                            'example' => [$nested['example']],
                            'optional' => $this->containsConditionalExpression($expression),
                        ];
                    }
                }
            }
        }

        if ($expression instanceof MethodCall) {
            $method = $this->identifierName($expression->name);
            $conditional = $this->isConditionalMethod($method);

            if ($method === 'whenCounted') {
                return $this->contract(['type' => 'integer'], 1, true);
            }

            if ($method === 'whenExistsLoaded') {
                return $this->contract(['type' => 'boolean'], true, true);
            }

            if ($method === 'whenAggregated') {
                return $this->contract(['type' => 'number'], 1, true);
            }

            $valueExpression = $this->conditionalValueExpression($expression, $method);
            if ($valueExpression instanceof Expr) {
                $contract = $this->inferExpression(
                    $valueExpression,
                    $fieldName,
                    $context,
                    $depth,
                    $visited,
                    $collectionItemContract,
                );
                $contract['optional'] = $contract['optional'] || $conditional;

                if ($method === 'whenNull') {
                    $contract['schema']['nullable'] = true;
                }

                return $contract;
            }

            if ($method === 'whenLoaded') {
                $relationship = strtolower($fieldName);
                $isCollection = str_ends_with($relationship, 's');

                return $this->contract(
                    $isCollection
                        ? ['type' => 'array', 'items' => ['type' => 'object', 'properties' => []]]
                        : ['type' => 'object', 'properties' => []],
                    $isCollection ? [] : [],
                    true,
                );
            }

            if ($conditional) {
                $fallback = $this->inferFromFieldName($fieldName);
                $fallback['optional'] = true;
                return $fallback;
            }
        }

        if ($expression instanceof PropertyFetch) {
            $property = $this->identifierName($expression->name);
            if ($property === 'collection' && $collectionItemContract !== null) {
                return $this->contract(
                    [
                        'type' => 'array',
                        'items' => $collectionItemContract['schema'],
                    ],
                    [$collectionItemContract['example']],
                );
            }
        }

        if ($expression instanceof Expr\NullsafePropertyFetch) {
            $contract = $this->inferFromFieldName($fieldName);
            $contract['schema']['nullable'] = true;
            return $contract;
        }

        if ($expression instanceof Expr\Ternary) {
            $if = $expression->if instanceof Expr ? $expression->if : $expression->cond;
            $contract = $this->inferExpression($if, $fieldName, $context, $depth, $visited, $collectionItemContract);

            if ($this->isNullExpression($expression->else) || $this->isNullExpression($expression->if)) {
                $contract['schema']['nullable'] = true;
            }

            return $contract;
        }

        if ($expression instanceof ArrowFunction && $expression->expr instanceof Expr) {
            return $this->inferExpression($expression->expr, $fieldName, $context, $depth, $visited, $collectionItemContract);
        }

        if ($expression instanceof Closure) {
            $return = $this->findReturnInStatements($expression->stmts);
            if ($return instanceof Expr) {
                return $this->inferExpression($return, $fieldName, $context, $depth, $visited, $collectionItemContract);
            }
        }

        if ($expression instanceof Expr\BinaryOp\Concat) {
            return $this->contract(['type' => 'string'], 'example');
        }

        return $this->inferFromFieldName($fieldName);
    }

    /**
     * @param  array<string, bool>  $visited
     * @param  array{schema: array<string, mixed>, example: mixed}|null  $collectionItemContract
     * @return array{schema: array<string, mixed>, example: mixed, optional: bool}
     */
    protected function inferArray(
        Array_ $array,
        ReflectionClass $context,
        int $depth,
        array $visited,
        ?array $collectionItemContract,
    ): array {
        $isList = true;
        foreach ($array->items as $item) {
            if ($item instanceof ArrayItem && $item->key !== null) {
                $isList = false;
                break;
            }
        }

        if ($isList) {
            $items = [];
            $example = [];

            foreach ($array->items as $item) {
                if (! $item instanceof ArrayItem || ! $item->value instanceof Expr) {
                    continue;
                }

                $contract = $this->inferExpression(
                    $item->value,
                    'item',
                    $context,
                    $depth,
                    $visited,
                    $collectionItemContract,
                );
                $items = $contract['schema'];
                $example[] = $contract['example'];

                break;
            }

            return $this->contract([
                'type' => 'array',
                'items' => $items,
            ], $example);
        }

        $properties = [];
        $required = [];
        $example = [];

        foreach ($array->items as $item) {
            if (! $item instanceof ArrayItem || ! $item->value instanceof Expr) {
                continue;
            }

            if ($item->key === null) {
                $merged = $this->inferMergedFields($item->value, $context, $depth, $visited, $collectionItemContract);
                if ($merged !== null) {
                    $properties = array_merge($properties, $merged['schema']['properties'] ?? []);
                    $example = array_merge($example, is_array($merged['example']) ? $merged['example'] : []);

                    if (! $merged['optional']) {
                        $required = array_merge($required, $merged['schema']['required'] ?? []);
                    }
                }
                continue;
            }

            $key = $this->arrayKey($item->key);
            if ($key === null) {
                continue;
            }

            $contract = $this->inferExpression(
                $item->value,
                $key,
                $context,
                $depth,
                $visited,
                $collectionItemContract,
            );

            $properties[$key] = $contract['schema'];
            $example[$key] = $contract['example'];

            if (! $contract['optional']) {
                $required[] = $key;
            }
        }

        $schema = [
            'type' => 'object',
            'properties' => $properties,
        ];

        if ($required !== []) {
            $schema['required'] = array_values(array_unique($required));
        }

        return $this->contract($schema, $example);
    }

    /**
     * @param  array<string, bool>  $visited
     * @param  array{schema: array<string, mixed>, example: mixed}|null  $collectionItemContract
     * @return array{schema: array<string, mixed>, example: mixed, optional: bool}|null
     */
    protected function inferMergedFields(
        Expr $expression,
        ReflectionClass $context,
        int $depth,
        array $visited,
        ?array $collectionItemContract,
    ): ?array {
        if (! $expression instanceof MethodCall) {
            return null;
        }

        $method = $this->identifierName($expression->name);
        if (! in_array($method, ['merge', 'mergeWhen', 'mergeUnless', 'attributes'], true)) {
            return null;
        }

        $argumentIndex = in_array($method, ['mergeWhen', 'mergeUnless'], true) ? 1 : 0;
        $argument = $expression->args[$argumentIndex]->value ?? null;
        if (! $argument instanceof Expr) {
            return null;
        }

        $contract = $this->inferExpression(
            $argument,
            'merged',
            $context,
            $depth,
            $visited,
            $collectionItemContract,
        );
        $contract['optional'] = in_array($method, ['mergeWhen', 'mergeUnless'], true);

        return $contract;
    }

    /**
     * @return array{schema: array<string, mixed>, example: mixed, optional: bool}
     */
    protected function inferFromFieldName(string $fieldName): array
    {
        $name = strtolower($fieldName);
        $example = match (true) {
            str_contains($name, 'uuid') => '550e8400-e29b-41d4-a716-446655440000',
            str_contains($name, '_at') || str_contains($name, 'timestamp') => '2025-01-15T10:30:00Z',
            default => $this->exampleGenerator->inferFromFieldName($fieldName),
        };
        $schema = $this->schemaFromExample($example, $fieldName);

        return $this->contract($schema, $example);
    }

    /** @return array<string, mixed> */
    protected function schemaFromExample(mixed $example, string $fieldName): array
    {
        $name = strtolower($fieldName);

        if (is_bool($example)) {
            return ['type' => 'boolean'];
        }

        if (is_int($example)) {
            return ['type' => 'integer'];
        }

        if (is_float($example)) {
            return ['type' => 'number', 'format' => 'float'];
        }

        if (is_array($example)) {
            return ['type' => 'array', 'items' => []];
        }

        $schema = ['type' => 'string'];

        if (str_contains($name, 'email')) {
            $schema['format'] = 'email';
        } elseif (str_contains($name, 'uuid')) {
            $schema['format'] = 'uuid';
        } elseif (str_contains($name, '_at') || str_contains($name, 'timestamp')) {
            $schema['format'] = 'date-time';
        } elseif (str_contains($name, 'date')) {
            $schema['format'] = 'date';
        } elseif (str_contains($name, 'url') || str_contains($name, 'link') || str_contains($name, 'website')) {
            $schema['format'] = 'uri';
        }

        return $schema;
    }

    protected function extractReturnExpression(ReflectionMethod $method): ?Expr
    {
        $source = $this->getMethodSource($method);
        if ($source === null) {
            return null;
        }

        try {
            $nodes = $this->parser->parse("<?php class __PostmanExporterResourceAnalysis {\n{$source}\n}");
            if ($nodes === null) {
                return null;
            }

            $classMethod = $this->nodeFinder->findFirstInstanceOf($nodes, Stmt\ClassMethod::class);
            if (! $classMethod instanceof Stmt\ClassMethod) {
                return null;
            }

            return $this->findReturnInStatements($classMethod->stmts ?? []);
        } catch (\Throwable) {
            return null;
        }
    }

    /** @param array<int, Stmt> $statements */
    protected function findReturnInStatements(array $statements): ?Expr
    {
        foreach ($statements as $statement) {
            if ($statement instanceof Stmt\Return_ && $statement->expr instanceof Expr) {
                return $statement->expr;
            }

            if ($statement instanceof Stmt\If_) {
                $found = $this->findReturnInStatements($statement->stmts);
                if ($found !== null) {
                    return $found;
                }
            }
        }

        return null;
    }

    protected function resolveCollectedResourceClass(ReflectionClass $collectionClass): ?string
    {
        $defaults = $collectionClass->getDefaultProperties();
        $collects = $defaults['collects'] ?? null;

        if (is_string($collects) && class_exists($collects) && is_subclass_of($collects, JsonResource::class)) {
            return $collects;
        }

        $shortName = $collectionClass->getShortName();
        if (str_ends_with($shortName, 'Collection')) {
            $candidate = $collectionClass->getNamespaceName() . '\\' . substr($shortName, 0, -10) . 'Resource';
            if (class_exists($candidate) && is_subclass_of($candidate, JsonResource::class)) {
                return $candidate;
            }
        }

        return null;
    }

    protected function resolveWrapKey(string $resourceClass): ?string
    {
        try {
            $reflection = new ReflectionClass($resourceClass);
            $wrap = $reflection->getStaticPropertyValue('wrap', 'data');

            return is_string($wrap) && $wrap !== '' ? $wrap : null;
        } catch (\Throwable) {
            return 'data';
        }
    }

    protected function resolveName(Node $node, ReflectionClass $context): ?string
    {
        if (! $node instanceof Name) {
            return null;
        }

        $name = $node->toString();
        if ($node instanceof Name\FullyQualified && class_exists($name)) {
            return $name;
        }

        if (class_exists($name)) {
            return $name;
        }

        $uses = $this->useStatements($context);
        $firstPart = explode('\\', $name)[0];

        if (isset($uses[$firstPart])) {
            $suffix = substr($name, strlen($firstPart));
            $candidate = $uses[$firstPart] . $suffix;
            if (class_exists($candidate)) {
                return $candidate;
            }
        }

        $candidate = $context->getNamespaceName() . '\\' . $name;
        if (class_exists($candidate)) {
            return $candidate;
        }

        return null;
    }

    /** @return array<string, string> */
    protected function useStatements(ReflectionClass $context): array
    {
        $filename = $context->getFileName();
        if (! is_string($filename) || $filename === '') {
            return [];
        }

        if (isset($this->useStatementCache[$filename])) {
            return $this->useStatementCache[$filename];
        }

        $source = file_get_contents($filename);
        if ($source === false) {
            return [];
        }

        $uses = [];
        if (preg_match_all('/^use\s+([\\w\\\\]+)(?:\s+as\s+(\w+))?\s*;/m', $source, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                $class = $match[1];
                $alias = $match[2] ?? class_basename($class);
                $uses[$alias] = $class;
            }
        }

        return $this->useStatementCache[$filename] = $uses;
    }

    protected function getMethodSource(ReflectionMethod $method): ?string
    {
        $filename = $method->getFileName();
        $startLine = $method->getStartLine();
        $endLine = $method->getEndLine();

        if (! $filename || ! $startLine || ! $endLine) {
            return null;
        }

        $source = file_get_contents($filename);
        if ($source === false) {
            return null;
        }

        $lines = array_slice(explode("\n", $source), $startLine - 1, $endLine - $startLine + 1);

        return implode("\n", $lines);
    }

    protected function containsConditionalExpression(Node $node): bool
    {
        $found = $this->nodeFinder->findFirst($node, function (Node $child): bool {
            return $child instanceof MethodCall
                && $this->isConditionalMethod($this->identifierName($child->name));
        });

        return $found instanceof Node;
    }

    protected function isConditionalMethod(?string $method): bool
    {
        return in_array($method, [
            'when',
            'unless',
            'whenHas',
            'whenNull',
            'whenNotNull',
            'whenAppended',
            'whenLoaded',
            'whenCounted',
            'whenAggregated',
            'whenExistsLoaded',
            'whenPivotLoaded',
            'whenPivotLoadedAs',
            'transform',
        ], true);
    }

    protected function conditionalValueExpression(MethodCall $call, ?string $method): ?Expr
    {
        $index = match ($method) {
            'when', 'unless', 'whenHas', 'whenLoaded', 'whenAppended' => 1,
            'whenPivotLoaded' => 1,
            'whenPivotLoadedAs' => 2,
            'transform' => 1,
            'whenNull', 'whenNotNull' => 0,
            default => null,
        };

        if ($index === null) {
            return null;
        }

        $value = $call->args[$index]->value ?? null;
        if (! $value instanceof Expr) {
            return null;
        }

        if ($value instanceof Closure) {
            return $this->findReturnInStatements($value->stmts);
        }

        if ($value instanceof ArrowFunction) {
            return $value->expr;
        }

        return $value;
    }

    protected function arrayKey(Expr $key): ?string
    {
        if ($key instanceof Scalar\String_) {
            return $key->value;
        }

        if ($key instanceof Scalar\Int_) {
            return (string) $key->value;
        }

        return null;
    }

    protected function identifierName(Node|string|null $identifier): ?string
    {
        if ($identifier instanceof Node\Identifier) {
            return $identifier->toString();
        }

        return is_string($identifier) ? $identifier : null;
    }

    protected function isNullExpression(?Expr $expression): bool
    {
        return $expression instanceof Expr\ConstFetch
            && strtolower($expression->name->toString()) === 'null';
    }

    /**
     * @param  array<string, mixed>  $schema
     * @return array{schema: array<string, mixed>, example: mixed, optional: bool}
     */
    protected function contract(array $schema, mixed $example, bool $optional = false): array
    {
        return [
            'schema' => $schema,
            'example' => $example,
            'optional' => $optional,
        ];
    }
}

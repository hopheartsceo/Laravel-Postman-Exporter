<?php

declare(strict_types=1);

namespace Hopheartsceo\PostmanExporter\Services;

use Illuminate\Http\Resources\Json\JsonResource;
use PhpParser\Node;
use PhpParser\Node\Arg;
use PhpParser\Node\ArrayItem;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\Array_;
use PhpParser\Node\Expr\Assign;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\New_;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Name;
use PhpParser\Node\Scalar;
use PhpParser\Node\Stmt;
use PhpParser\NodeFinder;
use PhpParser\Parser;
use PhpParser\ParserFactory;
use ReflectionClass;
use ReflectionMethod;
use ReflectionParameter;

class ResponsePipelineTracerService
{
    protected Parser $parser;

    protected NodeFinder $nodeFinder;

    /** @var array<string, array<string, string>> */
    protected array $useStatementCache = [];

    public function __construct(
        protected ResourceSchemaAnalyzerService $resourceAnalyzer,
        protected ?ExampleDataGeneratorService $exampleGenerator = null,
    ) {
        $this->exampleGenerator ??= new ExampleDataGeneratorService();
        $this->parser = (new ParserFactory())->createForNewestSupportedVersion();
        $this->nodeFinder = new NodeFinder();
    }

    /**
     * @return array{status_code: int, schema: array<string, mixed>, example: mixed}|null
     */
    public function trace(ReflectionMethod $method): ?array
    {
        try {
            return $this->traceMethod(
                $method,
                $method->getDeclaringClass(),
                [],
                0,
                [],
            );
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * @param  array<string, array<string, mixed>>  $bindings
     * @param  array<string, bool>  $visited
     * @return array{status_code: int, schema: array<string, mixed>, example: mixed}|null
     */
    protected function traceMethod(
        ReflectionMethod $method,
        ReflectionClass $runtimeClass,
        array $bindings,
        int $depth,
        array $visited,
    ): ?array {
        if ($depth > 8) {
            return null;
        }

        $key = $runtimeClass->getName() . '::' . $method->getName();

        if (isset($visited[$key])) {
            return null;
        }

        $statements = $this->methodStatements($method);

        if ($statements === null) {
            return null;
        }

        $visited[$key] = true;
        $localBindings = $bindings;

        foreach ($statements as $statement) {
            if ($statement instanceof Stmt\Expression
                && $statement->expr instanceof Assign
                && $statement->expr->var instanceof Expr\Variable
                && is_string($statement->expr->var->name)) {
                $name = $statement->expr->var->name;
                $localBindings[$name] = $this->inferValue(
                    $statement->expr->expr,
                    $name,
                    $method,
                    $runtimeClass,
                    $localBindings,
                );

                continue;
            }

            if ($statement instanceof Stmt\Return_ && $statement->expr instanceof Expr) {
                return $this->traceReturn(
                    $statement->expr,
                    $method,
                    $runtimeClass,
                    $localBindings,
                    $depth,
                    $visited,
                );
            }
        }

        return null;
    }

    /**
     * @param  array<string, array<string, mixed>>  $bindings
     * @param  array<string, bool>  $visited
     * @return array{status_code: int, schema: array<string, mixed>, example: mixed}|null
     */
    protected function traceReturn(
        Expr $expression,
        ReflectionMethod $scopeMethod,
        ReflectionClass $runtimeClass,
        array $bindings,
        int $depth,
        array $visited,
    ): ?array {
        $terminal = $this->traceJsonResponse(
            $expression,
            $scopeMethod,
            $runtimeClass,
            $bindings,
        );

        if ($terminal !== null) {
            return $terminal;
        }

        $helper = $this->resolveHelper($expression, $scopeMethod, $runtimeClass);

        if ($helper === null) {
            return null;
        }

        [$helperMethod, $helperRuntimeClass] = $helper;

        return $this->traceMethod(
            $helperMethod,
            $helperRuntimeClass,
            $this->bindArguments(
                $helperMethod,
                $expression->args,
                $scopeMethod,
                $runtimeClass,
                $bindings,
            ),
            $depth + 1,
            $visited,
        );
    }

    /**
     * @param  array<string, array<string, mixed>>  $bindings
     * @return array{status_code: int, schema: array<string, mixed>, example: mixed}|null
     */
    protected function traceJsonResponse(
        Expr $expression,
        ReflectionMethod $scopeMethod,
        ReflectionClass $runtimeClass,
        array $bindings,
    ): ?array {
        if ($expression instanceof MethodCall
            && $this->identifierName($expression->name) === 'json'
            && $this->isResponseFactory($expression->var)) {
            return $this->buildJsonResponse(
                $expression->args,
                $scopeMethod,
                $runtimeClass,
                $bindings,
            );
        }

        if ($expression instanceof StaticCall
            && $this->identifierName($expression->name) === 'json'
            && $this->nameLastPart($expression->class) === 'Response') {
            return $this->buildJsonResponse(
                $expression->args,
                $scopeMethod,
                $runtimeClass,
                $bindings,
            );
        }

        return null;
    }

    /**
     * @param  array<int, Arg>  $arguments
     * @param  array<string, array<string, mixed>>  $bindings
     * @return array{status_code: int, schema: array<string, mixed>, example: mixed}
     */
    protected function buildJsonResponse(
        array $arguments,
        ReflectionMethod $scopeMethod,
        ReflectionClass $runtimeClass,
        array $bindings,
    ): array {
        $payloadExpression = $this->argumentExpression($arguments, 'data', 0);
        $payload = $payloadExpression instanceof Expr
            ? $this->inferValue(
                $payloadExpression,
                'data',
                $scopeMethod,
                $runtimeClass,
                $bindings,
            )
            : $this->literalContract(null);

        $statusCode = 200;
        $statusExpression = $this->argumentExpression($arguments, 'status', 1);

        if ($statusExpression instanceof Expr) {
            $status = $this->inferValue(
                $statusExpression,
                'status',
                $scopeMethod,
                $runtimeClass,
                $bindings,
            );

            if (is_int($status['example'] ?? null)) {
                $statusCode = $status['example'];
            }
        }

        return [
            'status_code' => $statusCode,
            'schema' => $payload['schema'],
            'example' => $payload['example'],
        ];
    }

    /**
     * Resolve positional or named arguments using the target method's parameter name.
     *
     * @param  array<int, Arg>  $arguments
     */
    protected function argumentExpression(
        array $arguments,
        string $name,
        int $position,
    ): ?Expr {
        foreach ($arguments as $argument) {
            if ($argument->name instanceof Node\Identifier
                && $argument->name->toString() === $name) {
                return $argument->value;
            }
        }

        $positioned = array_values(array_filter(
            $arguments,
            static fn (Arg $argument): bool => $argument->name === null,
        ));

        return isset($positioned[$position])
            ? $positioned[$position]->value
            : null;
    }

    /**
     * @return array{ReflectionMethod, ReflectionClass}|null
     */
    protected function resolveHelper(
        Expr $expression,
        ReflectionMethod $scopeMethod,
        ReflectionClass $runtimeClass,
    ): ?array {
        if ($expression instanceof MethodCall
            && $expression->var instanceof Expr\Variable
            && $expression->var->name === 'this') {
            $method = $this->identifierName($expression->name);

            if ($method === null || ! $runtimeClass->hasMethod($method)) {
                return null;
            }

            $reflection = $runtimeClass->getMethod($method);

            return $reflection->isInternal()
                ? null
                : [$reflection, $runtimeClass];
        }

        if (! $expression instanceof StaticCall) {
            return null;
        }

        $method = $this->identifierName($expression->name);
        $className = $this->resolveClassName(
            $expression->class,
            $scopeMethod,
            $runtimeClass,
        );

        if ($method === null
            || $className === null
            || ! class_exists($className)
            || (is_subclass_of($className, JsonResource::class)
                && in_array($method, ['make', 'collection'], true))) {
            return null;
        }

        $class = new ReflectionClass($className);

        if (! $class->hasMethod($method)) {
            return null;
        }

        $reflection = $class->getMethod($method);

        return $reflection->isInternal()
            ? null
            : [$reflection, $class];
    }

    /**
     * @param  array<int, Arg>  $arguments
     * @param  array<string, array<string, mixed>>  $callerBindings
     * @return array<string, array<string, mixed>>
     */
    protected function bindArguments(
        ReflectionMethod $method,
        array $arguments,
        ReflectionMethod $callerMethod,
        ReflectionClass $callerRuntimeClass,
        array $callerBindings,
    ): array {
        $named = [];
        $positioned = [];

        foreach ($arguments as $argument) {
            if ($argument->name instanceof Node\Identifier) {
                $named[$argument->name->toString()] = $argument->value;
            } else {
                $positioned[] = $argument->value;
            }
        }

        $bindings = [];
        $position = 0;

        foreach ($method->getParameters() as $parameter) {
            $name = $parameter->getName();

            if (isset($named[$name])) {
                $bindings[$name] = $this->inferValue(
                    $named[$name],
                    $name,
                    $callerMethod,
                    $callerRuntimeClass,
                    $callerBindings,
                );
                continue;
            }

            if (isset($positioned[$position])) {
                $bindings[$name] = $this->inferValue(
                    $positioned[$position],
                    $name,
                    $callerMethod,
                    $callerRuntimeClass,
                    $callerBindings,
                );
                $position++;
                continue;
            }

            $bindings[$name] = $parameter->isDefaultValueAvailable()
                ? $this->literalContract($this->defaultValue($parameter))
                : $this->fallbackContract($name);
        }

        return $bindings;
    }

    protected function defaultValue(ReflectionParameter $parameter): mixed
    {
        try {
            return $parameter->getDefaultValue();
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * @param  array<string, array<string, mixed>>  $bindings
     * @return array<string, mixed>
     */
    protected function inferValue(
        Expr $expression,
        string $fieldName,
        ReflectionMethod $scopeMethod,
        ReflectionClass $runtimeClass,
        array $bindings,
    ): array {
        if ($expression instanceof Expr\Variable && is_string($expression->name)) {
            return $bindings[$expression->name]
                ?? $this->fallbackContract($expression->name);
        }

        if ($expression instanceof Array_) {
            return $this->inferArray(
                $expression,
                $scopeMethod,
                $runtimeClass,
                $bindings,
            );
        }

        if ($expression instanceof Scalar\String_) {
            return $this->literalContract($expression->value);
        }

        if ($expression instanceof Scalar\Int_) {
            return $this->literalContract($expression->value);
        }

        if ($expression instanceof Scalar\Float_) {
            return $this->literalContract($expression->value);
        }

        if ($expression instanceof Expr\ConstFetch) {
            return match (strtolower($expression->name->toString())) {
                'true' => $this->literalContract(true),
                'false' => $this->literalContract(false),
                'null' => $this->literalContract(null),
                default => $this->fallbackContract($fieldName),
            };
        }

        if ($expression instanceof Expr\ClassConstFetch
            && $expression->name instanceof Node\Identifier) {
            $className = $this->resolveClassName(
                $expression->class,
                $scopeMethod,
                $runtimeClass,
            );

            if ($className !== null) {
                try {
                    $constant = (new ReflectionClass($className))
                        ->getReflectionConstant($expression->name->toString());

                    if ($constant !== false) {
                        return $this->literalContract($constant->getValue());
                    }
                } catch (\Throwable) {
                    // Fall through to the inferred fallback below.
                }
            }
        }

        if ($expression instanceof Expr\Cast\Int_) {
            return $this->literalContract(1);
        }

        if ($expression instanceof Expr\Cast\Double) {
            return $this->literalContract(1.0);
        }

        if ($expression instanceof Expr\Cast\Bool_) {
            return $this->literalContract(true);
        }

        if ($expression instanceof Expr\Cast\String_) {
            return $this->literalContract('example');
        }

        if ($expression instanceof Expr\BinaryOp\Concat) {
            return $this->literalContract('example');
        }

        if ($expression instanceof Expr\PropertyFetch
            || $expression instanceof Expr\NullsafePropertyFetch) {
            return $this->fallbackContract(
                $this->identifierName($expression->name) ?? $fieldName,
            );
        }

        if ($expression instanceof New_) {
            $className = $this->resolveClassName(
                $expression->class,
                $scopeMethod,
                $runtimeClass,
            );

            if ($className !== null && is_subclass_of($className, JsonResource::class)) {
                $contract = $this->resourceAnalyzer->analyzePayloadContract($className);

                if ($contract !== null) {
                    return [
                        'schema' => $contract['schema'],
                        'example' => $contract['example'],
                    ];
                }
            }
        }

        if ($expression instanceof StaticCall) {
            $method = $this->identifierName($expression->name);
            $className = $this->resolveClassName(
                $expression->class,
                $scopeMethod,
                $runtimeClass,
            );

            if ($className !== null
                && is_subclass_of($className, JsonResource::class)
                && in_array($method, ['make', 'collection'], true)) {
                $contract = $this->resourceAnalyzer->analyzePayloadContract($className);

                if ($contract !== null) {
                    return $method === 'collection'
                        ? [
                            'schema' => [
                                'type' => 'array',
                                'items' => $contract['schema'],
                            ],
                            'example' => [$contract['example']],
                        ]
                        : $contract;
                }
            }
        }

        return $this->fallbackContract($fieldName);
    }

    /**
     * @param  array<string, array<string, mixed>>  $bindings
     * @return array<string, mixed>
     */
    protected function inferArray(
        Array_ $array,
        ReflectionMethod $scopeMethod,
        ReflectionClass $runtimeClass,
        array $bindings,
    ): array {
        $isList = true;

        foreach ($array->items as $item) {
            if ($item instanceof ArrayItem && $item->key !== null) {
                $isList = false;
                break;
            }
        }

        if ($isList) {
            $example = [];
            $items = [];

            foreach ($array->items as $item) {
                if (! $item instanceof ArrayItem) {
                    continue;
                }

                $contract = $this->inferValue(
                    $item->value,
                    'item',
                    $scopeMethod,
                    $runtimeClass,
                    $bindings,
                );

                $items = $contract['schema'];
                $example[] = $contract['example'];

                break;
            }

            return [
                'schema' => [
                    'type' => 'array',
                    'items' => $items,
                ],
                'example' => $example,
            ];
        }

        $properties = [];
        $required = [];
        $example = [];

        foreach ($array->items as $item) {
            if (! $item instanceof ArrayItem || $item->key === null) {
                continue;
            }

            $key = $this->arrayKey($item->key);

            if ($key === null) {
                continue;
            }

            $contract = $this->inferValue(
                $item->value,
                $key,
                $scopeMethod,
                $runtimeClass,
                $bindings,
            );

            $properties[$key] = $contract['schema'];
            $example[$key] = $contract['example'];
            $required[] = $key;
        }

        return [
            'schema' => [
                'type' => 'object',
                'properties' => $properties,
                'required' => $required,
            ],
            'example' => $example,
        ];
    }

    /** @return array<string, mixed> */
    protected function literalContract(mixed $value): array
    {
        if (is_bool($value)) {
            return ['schema' => ['type' => 'boolean'], 'example' => $value];
        }

        if (is_int($value)) {
            return ['schema' => ['type' => 'integer'], 'example' => $value];
        }

        if (is_float($value)) {
            return [
                'schema' => ['type' => 'number', 'format' => 'float'],
                'example' => $value,
            ];
        }

        if (is_string($value)) {
            return ['schema' => ['type' => 'string'], 'example' => $value];
        }

        if ($value === null) {
            return [
                'schema' => ['type' => 'string', 'nullable' => true],
                'example' => null,
            ];
        }

        return $this->fallbackContract('value');
    }

    /** @return array<string, mixed> */
    protected function fallbackContract(string $fieldName): array
    {
        $example = $this->exampleGenerator?->inferFromFieldName($fieldName) ?? 'example';

        return [
            'schema' => $this->schemaFromExample($example, $fieldName),
            'example' => $example,
        ];
    }

    /** @return array<string, mixed> */
    protected function schemaFromExample(mixed $example, string $fieldName): array
    {
        if (is_bool($example)) {
            return ['type' => 'boolean'];
        }

        if (is_int($example)) {
            return ['type' => 'integer'];
        }

        if (is_float($example)) {
            return ['type' => 'number', 'format' => 'float'];
        }

        $schema = ['type' => 'string'];
        $name = strtolower($fieldName);

        if (str_contains($name, 'email')) {
            $schema['format'] = 'email';
        } elseif (str_contains($name, 'uuid')) {
            $schema['format'] = 'uuid';
        } elseif (str_contains($name, '_at') || str_contains($name, 'timestamp')) {
            $schema['format'] = 'date-time';
        } elseif (str_contains($name, 'date')) {
            $schema['format'] = 'date';
        }

        return $schema;
    }

    /** @return array<int, Stmt>|null */
    protected function methodStatements(ReflectionMethod $method): ?array
    {
        $source = $this->getMethodSource($method);

        if ($source === null) {
            return null;
        }

        try {
            $nodes = $this->parser->parse(
                "<?php class __PostmanExporterPipelineTrace {\n{$source}\n}",
            );

            if ($nodes === null) {
                return null;
            }

            $classMethod = $this->nodeFinder->findFirstInstanceOf(
                $nodes,
                Stmt\ClassMethod::class,
            );

            return $classMethod instanceof Stmt\ClassMethod
                ? ($classMethod->stmts ?? [])
                : null;
        } catch (\Throwable) {
            return null;
        }
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

        return implode(
            "\n",
            array_slice(
                explode("\n", $source),
                $startLine - 1,
                $endLine - $startLine + 1,
            ),
        );
    }

    protected function resolveClassName(
        Node $node,
        ReflectionMethod $scopeMethod,
        ReflectionClass $runtimeClass,
    ): ?string {
        if (! $node instanceof Name) {
            return null;
        }

        $name = $node->toString();

        if (strtolower($name) === 'self') {
            return $scopeMethod->getDeclaringClass()->getName();
        }

        if (strtolower($name) === 'static') {
            return $runtimeClass->getName();
        }

        if (strtolower($name) === 'parent') {
            $parent = $scopeMethod->getDeclaringClass()->getParentClass();

            return $parent ? $parent->getName() : null;
        }

        if ($node instanceof Name\FullyQualified || class_exists($name)) {
            return class_exists($name) ? $name : null;
        }

        $parts = explode('\\', $name);
        $alias = $parts[0];
        $uses = $this->useStatements($scopeMethod);

        if (isset($uses[$alias])) {
            array_shift($parts);
            $candidate = $uses[$alias]
                . ($parts !== [] ? '\\' . implode('\\', $parts) : '');

            if (class_exists($candidate)) {
                return $candidate;
            }
        }

        $namespace = $scopeMethod->getDeclaringClass()->getNamespaceName();
        $candidate = $namespace !== '' ? $namespace . '\\' . $name : $name;

        return class_exists($candidate) ? $candidate : null;
    }

    /** @return array<string, string> */
    protected function useStatements(ReflectionMethod $method): array
    {
        $filename = $method->getFileName();

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

        if (preg_match_all(
            '/^use\s+([\w\\\\]+)(?:\s+as\s+(\w+))?\s*;/m',
            $source,
            $matches,
            PREG_SET_ORDER,
        )) {
            foreach ($matches as $match) {
                $class = $match[1];
                $alias = $match[2] ?? class_basename($class);
                $uses[$alias] = $class;
            }
        }

        return $this->useStatementCache[$filename] = $uses;
    }

    protected function isResponseFactory(Expr $expression): bool
    {
        return $expression instanceof FuncCall
            && $expression->name instanceof Name
            && strtolower($expression->name->toString()) === 'response';
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

    protected function nameLastPart(Node $node): ?string
    {
        return $node instanceof Name ? $node->getLast() : null;
    }
}

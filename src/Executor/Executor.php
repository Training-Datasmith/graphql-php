<?php

declare (strict_types=1);
namespace Graph_Ql\Executor;

use Graph_Ql\Error\Invariant_Violation;
use Graph_Ql\Executor\Promise\Adapter\Sync_Promise_Adapter;
use Graph_Ql\Executor\Promise\Promise;
use Graph_Ql\Executor\Promise\Promise_Adapter;
use Graph_Ql\Language\AST\Document_Node;
use Graph_Ql\Language\AST\Field_Node;
use Graph_Ql\Type\Definition\Field_Definition;
use Graph_Ql\Type\Definition\Resolve_Info;
use Graph_Ql\Type\Schema;
use Graph_Ql\Utils\Utils;
/**
 * Implements the "Evaluating requests" section of the GraphQL specification.
 *
 * @phpstan-type ArgsMapper callable(array<string, mixed>, FieldDefinition, FieldNode, mixed): mixed
 * @phpstan-type FieldResolver callable(mixed, array<string, mixed>, mixed, ResolveInfo): mixed
 * @phpstan-type ImplementationFactory callable(PromiseAdapter, Schema, DocumentNode, mixed, mixed, array<mixed>, ?string, callable, callable): ExecutorImplementation
 *
 * @see \GraphQL\Tests\Executor\ExecutorTest
 */
class Executor
{
    /**
     * @var callable
     *
     * @phpstan-var FieldResolver
     */
    private static $default_field_resolver = [self::class, 'defaultFieldResolver'];
    /**
     * @var callable
     *
     * @phpstan-var ArgsMapper
     */
    private static $default_args_mapper = [self::class, 'defaultArgsMapper'];
    private static ?Promise_Adapter $default_promise_adapter;
    /**
     * @var callable
     *
     * @phpstan-var ImplementationFactory
     */
    private static $implementation_factory = [Reference_Executor::class, 'create'];
    /** @phpstan-return FieldResolver */
    public static function get_default_field_resolver(): callable
    {
        return self::$default_field_resolver;
    }
    /**
     * Set a custom default resolve function.
     *
     * @phpstan-param FieldResolver $fieldResolver
     */
    public static function set_default_field_resolver(callable $field_resolver): void
    {
        self::$default_field_resolver = $field_resolver;
    }
    /** @phpstan-return ArgsMapper */
    public static function get_default_args_mapper(): callable
    {
        return self::$default_args_mapper;
    }
    /** @phpstan-param ArgsMapper $argsMapper */
    public static function set_default_args_mapper(callable $args_mapper): void
    {
        self::$default_args_mapper = $args_mapper;
    }
    public static function get_default_promise_adapter(): Promise_Adapter
    {
        return self::$default_promise_adapter ??= new Sync_Promise_Adapter();
    }
    /** Set a custom default promise adapter. */
    public static function set_default_promise_adapter(?Promise_Adapter $default_promise_adapter = null): void
    {
        self::$default_promise_adapter = $default_promise_adapter;
    }
    /** @phpstan-return ImplementationFactory */
    public static function get_implementation_factory(): callable
    {
        return self::$implementation_factory;
    }
    /**
     * Set a custom executor implementation factory.
     *
     * @phpstan-param ImplementationFactory $implementationFactory
     */
    public static function set_implementation_factory(callable $implementation_factory): void
    {
        self::$implementation_factory = $implementation_factory;
    }
    /**
     * Executes DocumentNode against given $schema.
     *
     * Always returns ExecutionResult and never throws.
     * All errors which occur during operation execution are collected in `$result->errors`.
     *
     * @param mixed $rootValue
     * @param mixed $contextValue
     * @param array<string, mixed>|null $variableValues
     *
     * @phpstan-param FieldResolver|null $fieldResolver
     *
     * @api
     *
     * @throws InvariantViolation
     */
    public static function execute(Schema $schema, Document_Node $document_node, $root_value = null, $context_value = null, ?array $variable_values = null, ?string $operation_name = null, ?callable $field_resolver = null): Execution_Result
    {
        $promise_adapter = new Sync_Promise_Adapter();
        $result = static::promise_to_execute($promise_adapter, $schema, $document_node, $root_value, $context_value, $variable_values, $operation_name, $field_resolver);
        return $promise_adapter->wait($result);
    }
    /**
     * Same as execute(), but requires promise adapter and returns a promise which is always
     * fulfilled with an instance of ExecutionResult and never rejected.
     *
     * Useful for async PHP platforms.
     *
     * @param mixed $rootValue
     * @param mixed $contextValue
     * @param array<string, mixed>|null $variableValues
     *
     * @phpstan-param FieldResolver|null $fieldResolver
     * @phpstan-param ArgsMapper|null $argsMapper
     *
     * @api
     */
    public static function promise_to_execute(Promise_Adapter $promise_adapter, Schema $schema, Document_Node $document_node, $root_value = null, $context_value = null, ?array $variable_values = null, ?string $operation_name = null, ?callable $field_resolver = null, ?callable $args_mapper = null): Promise
    {
        $executor = (self::$implementation_factory)($promise_adapter, $schema, $document_node, $root_value, $context_value, $variable_values ?? [], $operation_name, $field_resolver ?? self::$default_field_resolver, $args_mapper ?? self::$default_args_mapper);
        return $executor->do_execute();
    }
    /**
     * If a resolve function is not given, then a default resolve behavior is used
     * which takes the property of the root value of the same name as the field
     * and returns it as the result, or if it's a function, returns the result
     * of calling that function while passing along args and context.
     *
     * @param mixed $objectLikeValue
     * @param array<string, mixed> $args
     * @param mixed $contextValue
     *
     * @return mixed
     */
    public static function default_field_resolver($object_like_value, array $args, $context_value, Resolve_Info $info)
    {
        $property = Utils::extract_key($object_like_value, $info->field_name);
        return $property instanceof \Closure ? $property($object_like_value, $args, $context_value, $info) : $property;
    }
    /**
     * @template T of array<string, mixed>
     *
     * @param T $args
     *
     * @return T
     */
    public static function default_args_mapper(array $args): array
    {
        return $args;
    }
}
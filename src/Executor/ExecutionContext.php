<?php

declare (strict_types=1);
namespace Graph_Ql\Executor;

use Graph_Ql\Error\Error;
use Graph_Ql\Executor\Promise\Promise_Adapter;
use Graph_Ql\Language\AST\Fragment_Definition_Node;
use Graph_Ql\Language\AST\Operation_Definition_Node;
use Graph_Ql\Type\Schema;
/**
 * Data that must be available at all points during query execution.
 *
 * Namely, schema of the type system that is currently executing,
 * and the fragments defined in the query document.
 *
 * @phpstan-import-type FieldResolver from Executor
 * @phpstan-import-type ArgsMapper from Executor
 */
class Execution_Context
{
    public Schema $schema;
    /** @var array<string, FragmentDefinitionNode> */
    public array $fragments;
    /** @var mixed */
    public $root_value;
    /** @var mixed */
    public $context_value;
    public Operation_Definition_Node $operation;
    /** @var array<string, mixed> */
    public array $variable_values;
    /**
     * @var callable
     *
     * @phpstan-var FieldResolver
     */
    public $field_resolver;
    /**
     * @var callable
     *
     * @phpstan-var ArgsMapper
     */
    public $args_mapper;
    /** @var list<Error> */
    public array $errors;
    public Promise_Adapter $promise_adapter;
    /**
     * @param array<string, FragmentDefinitionNode> $fragments
     * @param mixed $rootValue
     * @param mixed $contextValue
     * @param array<string, mixed> $variableValues
     * @param list<Error> $errors
     *
     * @phpstan-param FieldResolver $fieldResolver
     */
    public function __construct(Schema $schema, array $fragments, $root_value, $context_value, Operation_Definition_Node $operation, array $variable_values, array $errors, callable $field_resolver, callable $args_mapper, Promise_Adapter $promise_adapter)
    {
        $this->schema = $schema;
        $this->fragments = $fragments;
        $this->root_value = $root_value;
        $this->context_value = $context_value;
        $this->operation = $operation;
        $this->variable_values = $variable_values;
        $this->errors = $errors;
        $this->field_resolver = $field_resolver;
        $this->args_mapper = $args_mapper;
        $this->promise_adapter = $promise_adapter;
    }
    public function add_error(Error $error): void
    {
        $this->errors[] = $error;
    }
}
<?php

declare (strict_types=1);
namespace Graph_Ql\Executor;

use Graph_Ql\Error\Error;
use Graph_Ql\Error\Invariant_Violation;
use Graph_Ql\Error\Warning;
use Graph_Ql\Executor\Promise\Promise;
use Graph_Ql\Executor\Promise\Promise_Adapter;
use Graph_Ql\Language\AST\Document_Node;
use Graph_Ql\Language\AST\Field_Node;
use Graph_Ql\Language\AST\Fragment_Definition_Node;
use Graph_Ql\Language\AST\Fragment_Spread_Node;
use Graph_Ql\Language\AST\Inline_Fragment_Node;
use Graph_Ql\Language\AST\Node;
use Graph_Ql\Language\AST\Operation_Definition_Node;
use Graph_Ql\Language\AST\Selection_Node;
use Graph_Ql\Language\AST\Selection_Set_Node;
use Graph_Ql\Type\Definition\Abstract_Type;
use Graph_Ql\Type\Definition\Directive;
use Graph_Ql\Type\Definition\Field_Definition;
use Graph_Ql\Type\Definition\Interface_Type;
use Graph_Ql\Type\Definition\Leaf_Type;
use Graph_Ql\Type\Definition\List_Of_Type;
use Graph_Ql\Type\Definition\Named_Type;
use Graph_Ql\Type\Definition\Non_Null;
use Graph_Ql\Type\Definition\Object_Type;
use Graph_Ql\Type\Definition\Output_Type;
use Graph_Ql\Type\Definition\Resolve_Info;
use Graph_Ql\Type\Definition\Type;
use Graph_Ql\Type\Introspection;
use Graph_Ql\Type\Schema;
use Graph_Ql\Type\Schema_Validation_Context;
use Graph_Ql\Utils\AST;
use Graph_Ql\Utils\Utils;
/**
 * @phpstan-import-type FieldResolver from Executor
 * @phpstan-import-type Path from ResolveInfo
 * @phpstan-import-type ArgsMapper from Executor
 *
 * @phpstan-type Fields \ArrayObject<string, \ArrayObject<int, FieldNode>>
 */
class Reference_Executor implements Executor_Implementation
{
    protected static \stdClass $UNDEFINED;
    protected Execution_Context $exe_context;
    /**
     * @var \SplObjectStorage<
     *     ObjectType,
     *     \SplObjectStorage<
     *         \ArrayObject<int, FieldNode>,
     *         \ArrayObject<
     *             string,
     *             \ArrayObject<int, FieldNode>
     *         >
     *     >
     * >
     */
    protected \Spl_Object_Storage $sub_field_cache;
    /**
     * @var \SplObjectStorage<
     *     FieldDefinition,
     *     \SplObjectStorage<FieldNode, mixed>
     * >
     */
    protected \Spl_Object_Storage $field_args_cache;
    protected Field_Definition $schema_meta_field_def;
    protected Field_Definition $type_meta_field_def;
    protected Field_Definition $type_name_meta_field_def;
    protected function __construct(Execution_Context $context)
    {
        if (!isset(static::$UNDEFINED)) {
            static::$UNDEFINED = Utils::undefined();
        }
        $this->exe_context = $context;
        $this->sub_field_cache = new \Spl_Object_Storage();
        $this->field_args_cache = new \Spl_Object_Storage();
    }
    /**
     * @param mixed $rootValue
     * @param mixed $contextValue
     * @param array<string, mixed> $variableValues
     *
     * @phpstan-param FieldResolver $fieldResolver
     * @phpstan-param ArgsMapper $argsMapper
     *
     * @throws \Exception
     */
    public static function create(Promise_Adapter $promise_adapter, Schema $schema, Document_Node $document_node, $root_value, $context_value, array $variable_values, ?string $operation_name, callable $field_resolver, ?callable $args_mapper = null): Executor_Implementation
    {
        $exe_context = static::build_execution_context($schema, $document_node, $root_value, $context_value, $variable_values, $operation_name, $field_resolver, $args_mapper ?? Executor::get_default_args_mapper(), $promise_adapter);
        if (is_array($exe_context)) {
            $execution_result = new Execution_Result(null, $exe_context);
            $fulfilled_promise = $promise_adapter->create_fulfilled($execution_result);
            return new Promise_Executor($fulfilled_promise);
        }
        return new static($exe_context);
    }
    /**
     * Constructs an ExecutionContext object from the arguments passed to execute,
     * which we will pass throughout the other execution methods.
     *
     * @param mixed $rootValue
     * @param mixed $contextValue
     * @param array<string, mixed> $rawVariableValues
     *
     * @phpstan-param FieldResolver $fieldResolver
     *
     * @throws \Exception
     *
     * @return ExecutionContext|list<Error>
     */
    protected static function build_execution_context(Schema $schema, Document_Node $document_node, $root_value, $context_value, array $raw_variable_values, ?string $operation_name, callable $field_resolver, callable $args_mapper, Promise_Adapter $promise_adapter)
    {
        /** @var list<Error> $errors */
        $errors = [];
        /** @var array<string, FragmentDefinitionNode> $fragments */
        $fragments = [];
        /** @var OperationDefinitionNode|null $operation */
        $operation = null;
        /** @var bool $hasMultipleAssumedOperations */
        $has_multiple_assumed_operations = false;
        foreach ($document_node->definitions as $definition) {
            switch (true) {
                case $definition instanceof Operation_Definition_Node:
                    if ($operation_name === null && $operation !== null) {
                        $has_multiple_assumed_operations = true;
                    }
                    if ($operation_name === null || isset($definition->name) && $definition->name->value === $operation_name) {
                        $operation = $definition;
                    }
                    break;
                case $definition instanceof Fragment_Definition_Node:
                    $fragments[$definition->name->value] = $definition;
                    break;
            }
        }
        if ($operation === null) {
            $message = $operation_name === null ? 'Must provide an operation.' : "Unknown operation named \"{$operation_name}\".";
            $errors[] = new Error($message);
        } elseif ($has_multiple_assumed_operations) {
            $errors[] = new Error('Must provide operation name if query contains multiple operations.');
        }
        $variable_values = null;
        if ($operation !== null) {
            [$coercion_errors, $coerced_variable_values] = Values::get_variable_values($schema, $operation->variable_definitions, $raw_variable_values);
            if ($coercion_errors === null) {
                $variable_values = $coerced_variable_values;
            } else {
                $errors = array_merge($errors, $coercion_errors);
            }
        }
        if ($errors !== []) {
            return $errors;
        }
        assert($operation instanceof Operation_Definition_Node, 'Has operation if no errors.');
        assert(is_array($variable_values), 'Has variables if no errors.');
        return new Execution_Context($schema, $fragments, $root_value, $context_value, $operation, $variable_values, $errors, $field_resolver, $args_mapper, $promise_adapter);
    }
    /**
     * @throws \Exception
     * @throws Error
     */
    public function do_execute(): Promise
    {
        // Return a Promise that will eventually resolve to the data described by
        // the "Response" section of the GraphQL specification.
        //
        // If errors are encountered while executing a GraphQL field, only that
        // field and its descendants will be omitted, and sibling fields will still
        // be executed. An execution which encounters errors will still result in a
        // resolved Promise.
        $data = $this->execute_operation($this->exe_context->operation, $this->exe_context->root_value);
        $result = $this->build_response($data);
        // Note: we deviate here from the reference implementation a bit by always returning promise
        // But for the "sync" case it is always fulfilled
        $promise = $this->get_promise($result);
        if ($promise !== null) {
            return $promise;
        }
        return $this->exe_context->promise_adapter->create_fulfilled($result);
    }
    /**
     * @param mixed $data
     *
     * @return ExecutionResult|Promise
     */
    protected function build_response($data)
    {
        if ($data instanceof Promise) {
            return $data->then(fn($resolved) => $this->build_response($resolved));
        }
        $promise_adapter = $this->exe_context->promise_adapter;
        if ($promise_adapter->is_thenable($data)) {
            return $promise_adapter->convert_thenable($data)->then(fn($resolved) => $this->build_response($resolved));
        }
        if ($data !== null) {
            $data = (array) $data;
        }
        return new Execution_Result($data, $this->exe_context->errors);
    }
    /**
     * Implements the "Evaluating operations" section of the spec.
     *
     * @param mixed $rootValue
     *
     * @throws \Exception
     *
     * @return array<mixed>|Promise|\stdClass|null
     */
    protected function execute_operation(Operation_Definition_Node $operation, $root_value)
    {
        $type = $this->get_operation_root_type($this->exe_context->schema, $operation);
        $fields = $this->collect_fields($type, $operation->selection_set, new \ArrayObject(), new \ArrayObject());
        $path = [];
        $unaliased_path = [];
        // Errors from sub-fields of a NonNull type may propagate to the top level,
        // at which point we still log the error and null the parent field, which
        // in this case is the entire response.
        //
        // Similar to completeValueCatchingError.
        try {
            $result = $operation->operation === 'mutation' ? $this->execute_fields_serially($type, $root_value, $path, $unaliased_path, $fields, $this->exe_context->context_value) : $this->execute_fields($type, $root_value, $path, $unaliased_path, $fields, $this->exe_context->context_value);
            $promise = $this->get_promise($result);
            if ($promise !== null) {
                return $promise->then(null, [$this, 'onError']);
            }
            return $result;
        } catch (Error $error) {
            $this->exe_context->add_error($error);
            return null;
        }
    }
    /** @param mixed $error */
    public function on_error($error): ?Promise
    {
        if ($error instanceof Error) {
            $this->exe_context->add_error($error);
            return $this->exe_context->promise_adapter->create_fulfilled();
        }
        return null;
    }
    /**
     * Extracts the root type of the operation from the schema.
     *
     * @throws \Exception
     * @throws Error
     */
    protected function get_operation_root_type(Schema $schema, Operation_Definition_Node $operation): Object_Type
    {
        switch ($operation->operation) {
            case 'query':
                $query_type = $schema->get_query_type();
                if ($query_type === null) {
                    throw new Error('Schema does not define the required query root type.', [$operation]);
                }
                return $query_type;
            case 'mutation':
                $mutation_type = $schema->get_mutation_type();
                if ($mutation_type === null) {
                    throw new Error('Schema is not configured for mutations.', [$operation]);
                }
                return $mutation_type;
            case 'subscription':
                $subscription_type = $schema->get_subscription_type();
                if ($subscription_type === null) {
                    throw new Error('Schema is not configured for subscriptions.', [$operation]);
                }
                return $subscription_type;
            default:
                throw new Error('Can only execute queries, mutations and subscriptions.', [$operation]);
        }
    }
    /**
     * Given a selectionSet, adds all fields in that selection to
     * the passed in map of fields, and returns it at the end.
     *
     * CollectFields requires the "runtime type" of an object. For a field which
     * returns an Interface or Union type, the "runtime type" will be the actual
     * Object type returned by that field.
     *
     * @param \ArrayObject<string, true> $visitedFragmentNames
     *
     * @phpstan-param Fields $fields
     *
     * @throws \Exception
     * @throws Error
     *
     * @phpstan-return Fields
     */
    protected function collect_fields(Object_Type $runtime_type, Selection_Set_Node $selection_set, \ArrayObject $fields, \ArrayObject $visited_fragment_names): \ArrayObject
    {
        $exe_context = $this->exe_context;
        foreach ($selection_set->selections as $selection) {
            switch (true) {
                case $selection instanceof Field_Node:
                    if (!$this->should_include_node($selection)) {
                        break;
                    }
                    $name = static::get_field_entry_key($selection);
                    $fields[$name] ??= new \ArrayObject();
                    $fields[$name][] = $selection;
                    break;
                case $selection instanceof Inline_Fragment_Node:
                    if (!$this->should_include_node($selection) || !$this->does_fragment_condition_match($selection, $runtime_type)) {
                        break;
                    }
                    $this->collect_fields($runtime_type, $selection->selection_set, $fields, $visited_fragment_names);
                    break;
                case $selection instanceof Fragment_Spread_Node:
                    $frag_name = $selection->name->value;
                    if (isset($visited_fragment_names[$frag_name]) || !$this->should_include_node($selection)) {
                        break;
                    }
                    $visited_fragment_names[$frag_name] = true;
                    if (!isset($exe_context->fragments[$frag_name])) {
                        break;
                    }
                    $fragment = $exe_context->fragments[$frag_name];
                    if (!$this->does_fragment_condition_match($fragment, $runtime_type)) {
                        break;
                    }
                    $this->collect_fields($runtime_type, $fragment->selection_set, $fields, $visited_fragment_names);
                    break;
            }
        }
        return $fields;
    }
    /**
     * Determines if a field should be included based on the @include and @skip
     * directives, where @skip has higher precedence than @include.
     *
     * @param FragmentSpreadNode|FieldNode|InlineFragmentNode $node
     *
     * @throws \Exception
     * @throws Error
     */
    protected function should_include_node(Selection_Node $node): bool
    {
        $variable_values = $this->exe_context->variable_values;
        $skip = Values::get_directive_values(Directive::skip_directive(), $node, $variable_values);
        if (isset($skip['if']) && $skip['if'] === true) {
            return false;
        }
        $include = Values::get_directive_values(Directive::include_directive(), $node, $variable_values);
        return !isset($include['if']) || $include['if'] !== false;
    }
    /** Implements the logic to compute the key of a given fields entry. */
    protected static function get_field_entry_key(Field_Node $node): string
    {
        return $node->alias->value ?? $node->name->value;
    }
    /**
     * Determines if a fragment is applicable to the given type.
     *
     * @param FragmentDefinitionNode|InlineFragmentNode $fragment
     *
     * @throws \Exception
     */
    protected function does_fragment_condition_match(Node $fragment, Object_Type $type): bool
    {
        $type_condition_node = $fragment->type_condition;
        if ($type_condition_node === null) {
            return true;
        }
        $conditional_type = AST::type_from_ast([$this->exe_context->schema, 'getType'], $type_condition_node);
        if ($conditional_type === $type) {
            return true;
        }
        if ($conditional_type instanceof Abstract_Type) {
            return $this->exe_context->schema->is_sub_type($conditional_type, $type);
        }
        return false;
    }
    /**
     * Implements the "Evaluating selection sets" section of the spec for "write" mode.
     *
     * @param mixed $rootValue
     * @param list<string|int> $path
     * @param list<string|int> $unaliasedPath
     * @param mixed $contextValue
     *
     * @phpstan-param Fields $fields
     *
     * @return array<mixed>|Promise|\stdClass
     */
    protected function execute_fields_serially(Object_Type $parent_type, $root_value, array $path, array $unaliased_path, \ArrayObject $fields, $context_value)
    {
        $result = $this->promise_reduce(array_keys($fields->get_array_copy()), function (array $results, string $response_name) use ($context_value, $path, $unaliased_path, $parent_type, $root_value, $fields) {
            $field_nodes = $fields[$response_name];
            assert($field_nodes instanceof \ArrayObject, 'The keys of $fields populate $responseName');
            $result = $this->resolve_field($parent_type, $root_value, $field_nodes, $response_name, $path, $unaliased_path, $this->maybe_scope_context($context_value));
            if ($result === static::$UNDEFINED) {
                return $results;
            }
            $promise = $this->get_promise($result);
            if ($promise !== null) {
                return $promise->then(static function ($resolved_result) use ($response_name, $results): array {
                    $results[$response_name] = $resolved_result;
                    return $results;
                });
            }
            $results[$response_name] = $result;
            return $results;
        }, []);
        $promise = $this->get_promise($result);
        if ($promise !== null) {
            return $result->then(static fn($resolved_results) => static::fix_results_if_empty_array($resolved_results));
        }
        return static::fix_results_if_empty_array($result);
    }
    /**
     * Resolves the field on the given root value.
     *
     * In particular, this figures out the value that the field returns
     * by calling its resolve function, then calls completeValue to complete promises,
     * serialize scalars, or execute the sub-selection-set for objects.
     *
     * @param mixed $rootValue
     * @param list<string|int> $path
     * @param list<string|int> $unaliasedPath
     * @param mixed $contextValue
     * @param \ArrayObject<int, FieldNode> $fieldNodes
     *
     * @phpstan-param Path                $path
     * @phpstan-param Path                $unaliasedPath
     *
     * @throws Error
     * @throws InvariantViolation
     *
     * @return array<mixed>|\Throwable|mixed|null
     */
    protected function resolve_field(Object_Type $parent_type, $root_value, \ArrayObject $field_nodes, string $response_name, array $path, array $unaliased_path, $context_value)
    {
        $exe_context = $this->exe_context;
        $field_node = $field_nodes[0];
        assert($field_node instanceof Field_Node, '$fieldNodes is non-empty');
        $field_name = $field_node->name->value;
        $field_def = $this->get_field_def($exe_context->schema, $parent_type, $field_name);
        if ($field_def === null || !$field_def->is_visible()) {
            return static::$UNDEFINED;
        }
        $path[] = $response_name;
        $unaliased_path[] = $field_name;
        $return_type = $field_def->get_type();
        // The resolve function's optional 3rd argument is a context value that
        // is provided to every resolve function within an execution. It is commonly
        // used to represent an authenticated user, or request-specific caches.
        // The resolve function's optional 4th argument is a collection of
        // information about the current execution state.
        $info = new Resolve_Info($field_def, $field_nodes, $parent_type, $path, $exe_context->schema, $exe_context->fragments, $exe_context->root_value, $exe_context->operation, $exe_context->variable_values, $unaliased_path);
        $resolve_fn = $field_def->resolve_fn ?? $parent_type->resolve_field_fn ?? $this->exe_context->field_resolver;
        $args_mapper = $field_def->args_mapper ?? $parent_type->args_mapper ?? $this->exe_context->args_mapper;
        // Get the resolve function, regardless of if its result is normal
        // or abrupt (error).
        $result = $this->resolve_field_value_or_error($field_def, $field_node, $resolve_fn, $args_mapper, $root_value, $info, $context_value);
        return $this->complete_value_catching_error($return_type, $field_nodes, $info, $path, $unaliased_path, $result, $context_value);
    }
    /**
     * This method looks up the field on the given type definition.
     *
     * It has special casing for the two introspection fields, __schema
     * and __typename. __typename is special because it can always be
     * queried as a field, even in situations where no other fields
     * are allowed, like on a Union. __schema could get automatically
     * added to the query type, but that would require mutating type
     * definitions, which would cause issues.
     *
     * @throws InvariantViolation
     */
    protected function get_field_def(Schema $schema, Object_Type $parent_type, string $field_name): ?Field_Definition
    {
        $this->schema_meta_field_def ??= Introspection::schema_meta_field_def();
        $this->type_meta_field_def ??= Introspection::type_meta_field_def();
        $this->type_name_meta_field_def ??= Introspection::type_name_meta_field_def();
        $query_type = $schema->get_query_type();
        if ($field_name === $this->schema_meta_field_def->name && $query_type === $parent_type) {
            return $this->schema_meta_field_def;
        }
        if ($field_name === $this->type_meta_field_def->name && $query_type === $parent_type) {
            return $this->type_meta_field_def;
        }
        if ($field_name === $this->type_name_meta_field_def->name) {
            return $this->type_name_meta_field_def;
        }
        return $parent_type->find_field($field_name);
    }
    /**
     * Isolates the "ReturnOrAbrupt" behavior to not de-opt the `resolveField` function.
     * Returns the result of resolveFn or the abrupt-return Error object.
     *
     * @param mixed $rootValue
     * @param mixed $contextValue
     *
     * @phpstan-param FieldResolver $resolveFn
     *
     * @return \Throwable|Promise|mixed
     */
    protected function resolve_field_value_or_error(Field_Definition $field_def, Field_Node $field_node, callable $resolve_fn, callable $args_mapper, $root_value, Resolve_Info $info, $context_value)
    {
        try {
            // Build a map of arguments from the field.arguments AST, using the
            // variables scope to fulfill any variable references.
            // @phpstan-ignore-next-line generics of SplObjectStorage are not inferred from empty instantiation
            $this->field_args_cache[$field_def] ??= new \Spl_Object_Storage();
            $args = $this->field_args_cache[$field_def][$field_node] ??= $args_mapper(Values::get_argument_values($field_def, $field_node, $this->exe_context->variable_values), $field_def, $field_node, $context_value);
            return $resolve_fn($root_value, $args, $context_value, $info);
        } catch (\Throwable $error) {
            return $error;
        }
    }
    /**
     * This is a small wrapper around completeValue which detects and logs errors
     * in the execution context.
     *
     * @param \ArrayObject<int, FieldNode> $fieldNodes
     * @param list<string|int> $path
     * @param list<string|int> $unaliasedPath
     * @param mixed $contextValue
     * @param mixed $result
     *
     * @phpstan-param Path                $path
     * @phpstan-param Path                $unaliasedPath
     *
     * @throws Error
     *
     * @return array<mixed>|Promise|\stdClass|null
     */
    protected function complete_value_catching_error(Type $return_type, \ArrayObject $field_nodes, Resolve_Info $info, array $path, array $unaliased_path, $result, $context_value)
    {
        // Otherwise, error protection is applied, logging the error and resolving
        // a null value for this field if one is encountered.
        try {
            $promise = $this->get_promise($result);
            if ($promise !== null) {
                $completed = $promise->then(fn(&$resolved) => $this->complete_value($return_type, $field_nodes, $info, $path, $unaliased_path, $resolved, $context_value));
            } else {
                $completed = $this->complete_value($return_type, $field_nodes, $info, $path, $unaliased_path, $result, $context_value);
            }
            $promise = $this->get_promise($completed);
            if ($promise !== null) {
                return $promise->then(null, function ($error) use ($field_nodes, $path, $unaliased_path, $return_type): void {
                    $this->handle_field_error($error, $field_nodes, $path, $unaliased_path, $return_type);
                });
            }
            return $completed;
        } catch (\Throwable $err) {
            $this->handle_field_error($err, $field_nodes, $path, $unaliased_path, $return_type);
            return null;
        }
    }
    /**
     * @param mixed $rawError
     * @param \ArrayObject<int, FieldNode> $fieldNodes
     * @param list<string|int> $path
     * @param list<string|int> $unaliasedPath
     *
     * @throws Error
     */
    protected function handle_field_error($raw_error, \ArrayObject $field_nodes, array $path, array $unaliased_path, Type $return_type): void
    {
        $error = Error::create_located_error($raw_error, $field_nodes, $path, $unaliased_path);
        // If the field type is non-nullable, then it is resolved without any
        // protection from errors, however it still properly locates the error.
        if ($return_type instanceof Non_Null) {
            throw $error;
        }
        // Otherwise, error protection is applied, logging the error and resolving
        // a null value for this field if one is encountered.
        $this->exe_context->add_error($error);
    }
    /**
     * Implements the instructions for completeValue as defined in the
     * "Field entries" section of the spec.
     *
     * If the field type is Non-Null, then this recursively completes the value
     * for the inner type. It throws a field error if that completion returns null,
     * as per the "Nullability" section of the spec.
     *
     * If the field type is a List, then this recursively completes the value
     * for the inner type on each item in the list.
     *
     * If the field type is a Scalar or Enum, ensures the completed value is a legal
     * value of the type by calling the `serialize` method of GraphQL type
     * definition.
     *
     * If the field is an abstract type, determine the runtime type of the value
     * and then complete based on that type.
     *
     * Otherwise, the field type expects a sub-selection set, and will complete the
     * value by evaluating all sub-selections.
     *
     * @param \ArrayObject<int, FieldNode> $fieldNodes
     * @param list<string|int> $path
     * @param list<string|int> $unaliasedPath
     * @param mixed $result
     * @param mixed $contextValue
     *
     * @throws \Throwable
     * @throws Error
     *
     * @return array<mixed>|mixed|Promise|null
     */
    protected function complete_value(Type $return_type, \ArrayObject $field_nodes, Resolve_Info $info, array $path, array $unaliased_path, $result, $context_value)
    {
        // If result is an Error, throw a located error.
        if ($result instanceof \Throwable) {
            throw $result;
        }
        // If field type is NonNull, complete for inner type, and throw field error
        // if result is null.
        if ($return_type instanceof Non_Null) {
            $completed = $this->complete_value($return_type->get_wrapped_type(), $field_nodes, $info, $path, $unaliased_path, $result, $context_value);
            if ($completed === null) {
                throw new Invariant_Violation("Cannot return null for non-nullable field \"{$info->parent_type}.{$info->field_name}\".");
            }
            return $completed;
        }
        if ($result === null) {
            return null;
        }
        // If field type is List, complete each item in the list with the inner type
        if ($return_type instanceof List_Of_Type) {
            if (!is_iterable($result)) {
                $result_type = gettype($result);
                throw new Invariant_Violation("Expected field {$info->parent_type}.{$info->field_name} to return iterable, but got: {$result_type}.");
            }
            return $this->complete_list_value($return_type, $field_nodes, $info, $path, $unaliased_path, $result, $context_value);
        }
        assert($return_type instanceof Named_Type, 'Wrapping types should return early');
        // Account for invalid schema definition when typeLoader returns different
        // instance than `resolveType` or $field->getType() or $arg->getType()
        assert($return_type === $this->exe_context->schema->get_type($return_type->name) || Type::is_built_in_scalar($return_type), Schema_Validation_Context::duplicate_type($this->exe_context->schema, "{$info->parent_type}.{$info->field_name}", $return_type->name));
        if ($return_type instanceof Leaf_Type) {
            if (Type::is_built_in_scalar($return_type)) {
                $schema_type = $this->exe_context->schema->get_type($return_type->name);
                assert($schema_type instanceof Leaf_Type, "Schema must provide a LeafType for built-in scalar \"{$return_type->name}\".");
                $return_type = $schema_type;
            }
            return $this->complete_leaf_value($return_type, $result);
        }
        if ($return_type instanceof Abstract_Type) {
            return $this->complete_abstract_value($return_type, $field_nodes, $info, $path, $unaliased_path, $result, $context_value);
        }
        // Field type must be and Object, Interface or Union and expect sub-selections.
        if ($return_type instanceof Object_Type) {
            return $this->complete_object_value($return_type, $field_nodes, $info, $path, $unaliased_path, $result, $context_value);
        }
        $safe_return_type = Utils::print_safe($return_type);
        throw new \RuntimeException("Cannot complete value of unexpected type {$safe_return_type}.");
    }
    /** @param mixed $value */
    protected function is_promise($value): bool
    {
        return $value instanceof Promise || $this->exe_context->promise_adapter->is_thenable($value);
    }
    /**
     * Only returns the value if it acts like a Promise, i.e. has a "then" function,
     * otherwise returns null.
     *
     * @param mixed $value
     */
    protected function get_promise($value): ?Promise
    {
        if ($value === null || $value instanceof Promise) {
            return $value;
        }
        $promise_adapter = $this->exe_context->promise_adapter;
        if ($promise_adapter->is_thenable($value)) {
            return $promise_adapter->convert_thenable($value);
        }
        return null;
    }
    /**
     * Similar to array_reduce(), however the reducing callback may return
     * a Promise, in which case reduction will continue after each promise resolves.
     *
     * If the callback does not return a Promise, then this function will also not
     * return a Promise.
     *
     * @param array<mixed> $values
     * @param Promise|mixed|null $initialValue
     *
     * @return Promise|mixed|null
     */
    protected function promise_reduce(array $values, callable $callback, $initial_value)
    {
        return array_reduce($values, function ($previous, $value) use ($callback) {
            $promise = $this->get_promise($previous);
            if ($promise !== null) {
                return $promise->then(static fn($resolved) => $callback($resolved, $value));
            }
            return $callback($previous, $value);
        }, $initial_value);
    }
    /**
     * Complete a list value by completing each item in the list with the inner type.
     *
     * @param ListOfType<Type&OutputType> $returnType
     * @param \ArrayObject<int, FieldNode> $fieldNodes
     * @param list<string|int> $path
     * @param list<string|int> $unaliasedPath
     * @param iterable<mixed> $results
     * @param mixed $contextValue
     *
     * @throws Error
     *
     * @return array<mixed>|Promise|\stdClass
     */
    protected function complete_list_value(List_Of_Type $return_type, \ArrayObject $field_nodes, Resolve_Info $info, array $path, array $unaliased_path, iterable $results, $context_value)
    {
        $item_type = $return_type->get_wrapped_type();
        $i = 0;
        $contains_promise = false;
        $completed_items = [];
        foreach ($results as $item) {
            $item_path = [...$path, $i];
            $info->path = $item_path;
            $item_unaliased_path = [...$unaliased_path, $i];
            $info->unaliased_path = $item_unaliased_path;
            ++$i;
            $completed_item = $this->complete_value_catching_error($item_type, $field_nodes, $info, $item_path, $item_unaliased_path, $item, $context_value);
            if (!$contains_promise && $this->get_promise($completed_item) !== null) {
                $contains_promise = true;
            }
            $completed_items[] = $completed_item;
        }
        return $contains_promise ? $this->exe_context->promise_adapter->all($completed_items) : $completed_items;
    }
    /**
     * Complete a Scalar or Enum by serializing to a valid value, throwing if serialization is not possible.
     *
     * @param mixed $result
     *
     * @throws \Exception
     *
     * @return mixed
     */
    protected function complete_leaf_value(Leaf_Type $return_type, $result)
    {
        try {
            return $return_type->serialize($result);
        } catch (\Throwable $error) {
            $safe_return_type = Utils::print_safe($return_type);
            $safe_result = Utils::print_safe($result);
            throw new Invariant_Violation("Expected a value of type {$safe_return_type} but received: {$safe_result}. {$error->get_message()}", 0, $error);
        }
    }
    /**
     * Complete a value of an abstract type by determining the runtime object type
     * of that value, then complete the value for that type.
     *
     * @param AbstractType&Type $returnType
     * @param \ArrayObject<int, FieldNode> $fieldNodes
     * @param list<string|int> $path
     * @param list<string|int> $unaliasedPath
     * @param mixed $result
     * @param mixed $contextValue
     *
     * @throws \Exception
     * @throws Error
     * @throws InvariantViolation
     *
     * @return array<mixed>|Promise|\stdClass
     */
    protected function complete_abstract_value(Abstract_Type $return_type, \ArrayObject $field_nodes, Resolve_Info $info, array $path, array $unaliased_path, $result, $context_value)
    {
        $result = $return_type->resolve_value($result, $context_value, $info);
        $type_candidate = $return_type->resolve_type($result, $context_value, $info);
        if ($type_candidate === null) {
            $runtime_type = static::default_type_resolver($result, $context_value, $info, $return_type);
        } elseif (!is_string($type_candidate) && is_callable($type_candidate)) {
            $runtime_type = $type_candidate();
        } else {
            $runtime_type = $type_candidate;
        }
        $promise = $this->get_promise($runtime_type);
        if ($promise !== null) {
            return $promise->then(fn($resolved_runtime_type) => $this->complete_object_value($this->ensure_valid_runtime_type($resolved_runtime_type, $return_type, $info, $result), $field_nodes, $info, $path, $unaliased_path, $result, $context_value));
        }
        return $this->complete_object_value($this->ensure_valid_runtime_type($runtime_type, $return_type, $info, $result), $field_nodes, $info, $path, $unaliased_path, $result, $context_value);
    }
    /**
     * If a resolveType function is not given, then a default resolve behavior is
     * used which attempts two strategies:.
     *
     * First, See if the provided value has a `__typename` field defined, if so, use
     * that value as name of the resolved type.
     *
     * Otherwise, test each possible type for the abstract type by calling
     * isTypeOf for the object being coerced, returning the first type that matches.
     *
     * @param mixed|null $value
     * @param mixed|null $contextValue
     * @param AbstractType&Type $abstractType
     *
     * @throws InvariantViolation
     *
     * @return Promise|Type|string|null
     */
    protected function default_type_resolver($value, $context_value, Resolve_Info $info, Abstract_Type $abstract_type)
    {
        $typename = Utils::extract_key($value, '__typename');
        if (is_string($typename)) {
            return $typename;
        }
        if ($abstract_type instanceof Interface_Type && isset($info->schema->get_config()->type_loader)) {
            $safe_value = Utils::print_safe($value);
            Warning::warn_once("GraphQL Interface Type `{$abstract_type->name}` returned `null` from its `resolveType` function for value: {$safe_value}. Switching to slow resolution method using `isTypeOf` of all possible implementations. It requires full schema scan and degrades query performance significantly. Make sure your `resolveType` function always returns a valid implementation or throws.", Warning::WARNING_FULL_SCHEMA_SCAN);
        }
        $possible_types = $info->schema->get_possible_types($abstract_type);
        $promised_is_type_of_results = [];
        foreach ($possible_types as $index => $type) {
            $is_type_of_result = $type->is_type_of($value, $context_value, $info);
            if ($is_type_of_result === null) {
                continue;
            }
            $promise = $this->get_promise($is_type_of_result);
            if ($promise !== null) {
                $promised_is_type_of_results[$index] = $promise;
            } elseif ($is_type_of_result === true) {
                return $type;
            }
        }
        if ($promised_is_type_of_results !== []) {
            return $this->exe_context->promise_adapter->all($promised_is_type_of_results)->then(static function ($is_type_of_results) use ($possible_types): ?Object_Type {
                foreach ($is_type_of_results as $index => $result) {
                    if ($result) {
                        return $possible_types[$index];
                    }
                }
                return null;
            });
        }
        return null;
    }
    /**
     * Complete an Object value by executing all sub-selections.
     *
     * @param \ArrayObject<int, FieldNode> $fieldNodes
     * @param list<string|int> $path
     * @param list<string|int> $unaliasedPath
     * @param mixed $result
     * @param mixed $contextValue
     *
     * @throws \Exception
     * @throws Error
     *
     * @return array<mixed>|Promise|\stdClass
     */
    protected function complete_object_value(Object_Type $return_type, \ArrayObject $field_nodes, Resolve_Info $info, array $path, array $unaliased_path, $result, $context_value)
    {
        // If there is an isTypeOf predicate function, call it with the
        // current result. If isTypeOf returns false, then raise an error rather
        // than continuing execution.
        $is_type_of = $return_type->is_type_of($result, $context_value, $info);
        if ($is_type_of !== null) {
            $promise = $this->get_promise($is_type_of);
            if ($promise !== null) {
                return $promise->then(function ($is_type_of_result) use ($context_value, $return_type, $field_nodes, $path, $unaliased_path, $result) {
                    if (!$is_type_of_result) {
                        throw $this->invalid_return_type_error($return_type, $result, $field_nodes);
                    }
                    return $this->collect_and_execute_subfields($return_type, $field_nodes, $path, $unaliased_path, $result, $context_value);
                });
            }
            assert(is_bool($is_type_of), 'Promise would return early');
            if (!$is_type_of) {
                throw $this->invalid_return_type_error($return_type, $result, $field_nodes);
            }
        }
        return $this->collect_and_execute_subfields($return_type, $field_nodes, $path, $unaliased_path, $result, $context_value);
    }
    /**
     * @param \ArrayObject<int, FieldNode> $fieldNodes
     * @param mixed $result
     */
    protected function invalid_return_type_error(Object_Type $return_type, $result, \ArrayObject $field_nodes): Error
    {
        $safe_result = Utils::print_safe($result);
        return new Error("Expected value of type \"{$return_type->name}\" but got: {$safe_result}.", $field_nodes);
    }
    /**
     * @param \ArrayObject<int, FieldNode> $fieldNodes
     * @param list<string|int> $path
     * @param list<string|int> $unaliasedPath
     * @param mixed $result
     * @param mixed $contextValue
     *
     * @throws \Exception
     * @throws Error
     *
     * @return array<mixed>|Promise|\stdClass
     */
    protected function collect_and_execute_subfields(Object_Type $return_type, \ArrayObject $field_nodes, array $path, array $unaliased_path, $result, $context_value)
    {
        $sub_field_nodes = $this->collect_sub_fields($return_type, $field_nodes);
        return $this->execute_fields($return_type, $result, $path, $unaliased_path, $sub_field_nodes, $context_value);
    }
    /**
     * A memoized collection of relevant subfields with regard to the return
     * type. Memoizing ensures the subfields are not repeatedly calculated, which
     * saves overhead when resolving lists of values.
     *
     * @param \ArrayObject<int, FieldNode> $fieldNodes
     *
     * @throws \Exception
     * @throws Error
     *
     * @phpstan-return Fields
     */
    protected function collect_sub_fields(Object_Type $return_type, \ArrayObject $field_nodes): \ArrayObject
    {
        // @phpstan-ignore-next-line generics of SplObjectStorage are not inferred from empty instantiation
        $return_type_cache = $this->sub_field_cache[$return_type] ??= new \Spl_Object_Storage();
        if (!isset($return_type_cache[$field_nodes])) {
            // Collect sub-fields to execute to complete this value.
            $sub_field_nodes = new \ArrayObject();
            $visited_fragment_names = new \ArrayObject();
            foreach ($field_nodes as $field_node) {
                if (isset($field_node->selection_set)) {
                    $sub_field_nodes = $this->collect_fields($return_type, $field_node->selection_set, $sub_field_nodes, $visited_fragment_names);
                }
            }
            $return_type_cache[$field_nodes] = $sub_field_nodes;
        }
        return $return_type_cache[$field_nodes];
    }
    /**
     * Implements the "Evaluating selection sets" section of the spec for "read" mode.
     *
     * @param mixed $rootValue
     * @param list<string|int> $path
     * @param list<string|int> $unaliasedPath
     * @param mixed $contextValue
     *
     * @phpstan-param Fields $fields
     *
     * @throws Error
     * @throws InvariantViolation
     *
     * @return Promise|\stdClass|array<mixed>
     */
    protected function execute_fields(Object_Type $parent_type, $root_value, array $path, array $unaliased_path, \ArrayObject $fields, $context_value)
    {
        $contains_promise = false;
        $results = [];
        foreach ($fields as $response_name => $field_nodes) {
            $result = $this->resolve_field($parent_type, $root_value, $field_nodes, $response_name, $path, $unaliased_path, $this->maybe_scope_context($context_value));
            if ($result === static::$UNDEFINED) {
                continue;
            }
            if (!$contains_promise && $this->is_promise($result)) {
                $contains_promise = true;
            }
            $results[$response_name] = $result;
        }
        // If there are no promises, we can just return the object
        if (!$contains_promise) {
            return static::fix_results_if_empty_array($results);
        }
        // Otherwise, results is a map from field name to the result of resolving that
        // field, which is possibly a promise. Return a promise that will return this
        // same map, but with any promises replaced with the values they resolved to.
        return $this->promise_for_assoc_array($results);
    }
    /**
     * Differentiate empty objects from empty lists.
     *
     * @see https://github.com/webonyx/graphql-php/issues/59
     *
     * @param array<mixed>|mixed $results
     *
     * @return non-empty-array<mixed>|\stdClass|mixed
     */
    protected static function fix_results_if_empty_array($results)
    {
        if ($results === []) {
            return new \stdClass();
        }
        return $results;
    }
    /**
     * Transform an associative array with Promises to a Promise which resolves to an
     * associative array where all Promises were resolved.
     *
     * @param array<string, Promise|mixed> $assoc
     */
    protected function promise_for_assoc_array(array $assoc): Promise
    {
        $keys = array_keys($assoc);
        $values_and_promises = array_values($assoc);
        $promise = $this->exe_context->promise_adapter->all($values_and_promises);
        return $promise->then(static function ($values) use ($keys) {
            $resolved_results = [];
            foreach ($values as $i => $value) {
                $resolved_results[$keys[$i]] = $value;
            }
            return static::fix_results_if_empty_array($resolved_results);
        });
    }
    /**
     * @param mixed $runtimeTypeOrName
     * @param AbstractType&Type $returnType
     * @param mixed $result
     *
     * @throws InvariantViolation
     */
    protected function ensure_valid_runtime_type($runtime_type_or_name, Abstract_Type $return_type, Resolve_Info $info, $result): Object_Type
    {
        $runtime_type = is_string($runtime_type_or_name) ? $this->exe_context->schema->get_type($runtime_type_or_name) : $runtime_type_or_name;
        if (!$runtime_type instanceof Object_Type) {
            $safe_result = Utils::print_safe($result);
            $not_object_type = Utils::print_safe($runtime_type);
            throw new Invariant_Violation("Abstract type {$return_type} must resolve to an Object type at runtime for field {$info->parent_type}.{$info->field_name} with value {$safe_result}, received \"{$not_object_type}\". Either the {$return_type} type should provide a \"resolveType\" function or each possible type should provide an \"isTypeOf\" function.");
        }
        if (!$this->exe_context->schema->is_sub_type($return_type, $runtime_type)) {
            throw new Invariant_Violation("Runtime Object type \"{$runtime_type}\" is not a possible type for \"{$return_type}\".");
        }
        assert($this->exe_context->schema->get_type($runtime_type->name) !== null, "Schema does not contain type \"{$runtime_type}\". This can happen when an object type is only referenced indirectly through abstract types and never directly through fields.List the type in the option \"types\" during schema construction, see https://webonyx.github.io/graphql-php/schema-definition/#configuration-options.");
        assert($runtime_type === $this->exe_context->schema->get_type($runtime_type->name), "Schema must contain unique named types but contains multiple types named \"{$runtime_type}\". Make sure that `resolveType` function of abstract type \"{$return_type}\" returns the same type instance as referenced anywhere else within the schema (see https://webonyx.github.io/graphql-php/type-definitions/#type-registry).");
        return $runtime_type;
    }
    /**
     * @param mixed $contextValue
     *
     * @return mixed
     */
    private function maybe_scope_context($context_value)
    {
        if ($context_value instanceof Scoped_Context) {
            return $context_value->clone();
        }
        return $context_value;
    }
}
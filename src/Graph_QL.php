<?php

declare (strict_types=1);
namespace Graph_Ql;

use Graph_Ql\Error\Error;
use Graph_Ql\Error\Invariant_Violation;
use Graph_Ql\Executor\Execution_Result;
use Graph_Ql\Executor\Executor;
use Graph_Ql\Executor\Promise\Adapter\Sync_Promise_Adapter;
use Graph_Ql\Executor\Promise\Promise;
use Graph_Ql\Executor\Promise\Promise_Adapter;
use Graph_Ql\Language\AST\Document_Node;
use Graph_Ql\Language\Parser;
use Graph_Ql\Language\Source;
use Graph_Ql\Type\Definition\Directive;
use Graph_Ql\Type\Definition\Scalar_Type;
use Graph_Ql\Type\Definition\Type;
use Graph_Ql\Type\Schema as SchemaType;
use Graph_Ql\Validator\Document_Validator;
use Graph_Ql\Validator\Rules\Query_Complexity;
use Graph_Ql\Validator\Rules\Validation_Rule;
/**
 * This is the primary facade for fulfilling GraphQL operations.
 * See [related documentation](executing-queries.md).
 *
 * @phpstan-import-type ArgsMapper from Executor
 * @phpstan-import-type FieldResolver from Executor
 *
 * @see \GraphQL\Tests\GraphQLTest
 */
class Graph_Ql
{
    /**
     * Executes graphql query.
     *
     * More sophisticated GraphQL servers, such as those which persist queries,
     * may wish to separate the validation and execution phases to a static time
     * tooling step, and a server runtime step.
     *
     * Available options:
     *
     * schema:
     *    The GraphQL type system to use when validating and executing a query.
     * source:
     *    A GraphQL language formatted string representing the requested operation.
     * rootValue:
     *    The value provided as the first argument to resolver functions on the top
     *    level type (e.g. the query object type).
     * contextValue:
     *    The context value is provided as an argument to resolver functions after
     *    field arguments. It is used to pass shared information useful at any point
     *    during executing this query, for example the currently logged in user and
     *    connections to databases or other services.
     *    If the passed object implements the `ScopedContext` interface,
     *    its `clone()` method will be called before passing the context down to a field.
     *    This allows passing information to child fields in the query tree without affecting sibling or parent fields.
     * variableValues:
     *    A mapping of variable name to runtime value to use for all variables
     *    defined in the requestString.
     * operationName:
     *    The name of the operation to use if requestString contains multiple
     *    possible operations. Can be omitted if requestString contains only
     *    one operation.
     * fieldResolver:
     *    A resolver function to use when one is not provided by the schema.
     *    If not provided, the default field resolver is used (which looks for a
     *    value on the source value with the field's name).
     * validationRules:
     *    A set of rules for query validation step. Default value is all available rules.
     *    Empty array would allow to skip query validation (may be convenient for persisted
     *    queries which are validated before persisting and assumed valid during execution)
     *
     * @param string|DocumentNode $source
     * @param mixed $rootValue
     * @param mixed $contextValue
     * @param array<string, mixed>|null $variableValues
     * @param array<ValidationRule>|null $validationRules
     *
     * @api
     *
     * @throws \Exception
     * @throws InvariantViolation
     */
    public static function execute_query(Schema_Type $schema, $source, $root_value = null, $context_value = null, ?array $variable_values = null, ?string $operation_name = null, ?callable $field_resolver = null, ?array $validation_rules = null): Execution_Result
    {
        $promise_adapter = new Sync_Promise_Adapter();
        $promise = self::promise_to_execute($promise_adapter, $schema, $source, $root_value, $context_value, $variable_values, $operation_name, $field_resolver, $validation_rules);
        return $promise_adapter->wait($promise);
    }
    /**
     * Same as executeQuery(), but requires PromiseAdapter and always returns a Promise.
     * Useful for Async PHP platforms.
     *
     * @param string|DocumentNode $source
     * @param mixed $rootValue
     * @param mixed $context
     * @param array<string, mixed>|null $variableValues
     * @param array<ValidationRule>|null $validationRules Defaults to using all available rules
     *
     * @api
     *
     * @throws \Exception
     */
    public static function promise_to_execute(Promise_Adapter $promise_adapter, Schema_Type $schema, $source, $root_value = null, $context = null, ?array $variable_values = null, ?string $operation_name = null, ?callable $field_resolver = null, ?array $validation_rules = null): Promise
    {
        try {
            $document_node = $source instanceof Document_Node ? $source : Parser::parse(new Source($source, 'GraphQL'));
            if ($validation_rules === null) {
                $query_complexity = Document_Validator::get_rule(Query_Complexity::class);
                assert($query_complexity instanceof Query_Complexity, 'should not register a different rule for QueryComplexity');
                $query_complexity->set_raw_variable_values($variable_values);
            } else {
                foreach ($validation_rules as $rule) {
                    if ($rule instanceof Query_Complexity) {
                        $rule->set_raw_variable_values($variable_values);
                    }
                }
            }
            $validation_errors = Document_Validator::validate($schema, $document_node, $validation_rules);
            if ($validation_errors !== []) {
                return $promise_adapter->create_fulfilled(new Execution_Result(null, $validation_errors));
            }
            return Executor::promise_to_execute($promise_adapter, $schema, $document_node, $root_value, $context, $variable_values, $operation_name, $field_resolver);
        } catch (Error $e) {
            return $promise_adapter->create_fulfilled(new Execution_Result(null, [$e]));
        }
    }
    /**
     * Returns directives defined in GraphQL spec.
     *
     * @deprecated use {@see Directive::builtInDirectives()}
     *
     * @throws InvariantViolation
     *
     * @return array<string, Directive>
     *
     * @api
     */
    public static function get_standard_directives(): array
    {
        return Directive::built_in_directives();
    }
    /**
     * Returns built-in scalar types defined in GraphQL spec.
     *
     * @deprecated use {@see Type::builtInScalars()}
     *
     * @throws InvariantViolation
     *
     * @return array<string, ScalarType>
     *
     * @api
     */
    public static function get_standard_types(): array
    {
        return Type::built_in_scalars();
    }
    /**
     * Replaces standard types with types from this list (matching by name).
     *
     * Standard types not listed here remain untouched.
     *
     * @deprecated prefer per-schema scalar overrides via {@see \GraphQL\Type\SchemaConfig::$types} or {@see \GraphQL\Type\SchemaConfig::$typeLoader}
     *
     * @param array<string, ScalarType> $types
     *
     * @api
     *
     * @throws InvariantViolation
     */
    public static function override_standard_types(array $types): void
    {
        Type::override_standard_types($types);
    }
    /**
     * Returns standard validation rules implementing GraphQL spec.
     *
     * @return array<class-string<ValidationRule>, ValidationRule>
     *
     * @api
     */
    public static function get_standard_validation_rules(): array
    {
        return Document_Validator::default_rules();
    }
    /**
     * Set default resolver implementation.
     *
     * @phpstan-param FieldResolver $fn
     *
     * @api
     */
    public static function set_default_field_resolver(callable $fn): void
    {
        Executor::set_default_field_resolver($fn);
    }
    /**
     * Set default args mapper implementation.
     *
     * @phpstan-param ArgsMapper $fn
     *
     * @api
     */
    public static function set_default_args_mapper(callable $fn): void
    {
        Executor::set_default_args_mapper($fn);
    }
}
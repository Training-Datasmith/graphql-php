<?php

declare (strict_types=1);
namespace Graph_Ql\Utils;

use Graph_Ql\Error\Error;
use Graph_Ql\Error\Invariant_Violation;
use Graph_Ql\Error\Serialization_Error;
use Graph_Ql\Language\AST\String_Value_Node;
use Graph_Ql\Language\Block_String;
use Graph_Ql\Language\Printer;
use Graph_Ql\Type\Definition\Argument;
use Graph_Ql\Type\Definition\Directive;
use Graph_Ql\Type\Definition\Enum_Type;
use Graph_Ql\Type\Definition\Enum_Value_Definition;
use Graph_Ql\Type\Definition\Field_Definition;
use Graph_Ql\Type\Definition\Implementing_Type;
use Graph_Ql\Type\Definition\Input_Object_Field;
use Graph_Ql\Type\Definition\Input_Object_Type;
use Graph_Ql\Type\Definition\Interface_Type;
use Graph_Ql\Type\Definition\Named_Type;
use Graph_Ql\Type\Definition\Object_Type;
use Graph_Ql\Type\Definition\Scalar_Type;
use Graph_Ql\Type\Definition\Type;
use Graph_Ql\Type\Definition\Union_Type;
use Graph_Ql\Type\Introspection;
use Graph_Ql\Type\Schema;
/**
 * Prints the contents of a Schema in schema definition language.
 *
 * All sorting options sort alphabetically. If not given or `false`, the original schema definition order will be used.
 *
 * @phpstan-type Options array{
 *   sortArguments?: bool,
 *   sortEnumValues?: bool,
 *   sortFields?: bool,
 *   sortInputFields?: bool,
 *   sortTypes?: bool,
 * }
 *
 * @see \GraphQL\Tests\Utils\SchemaPrinterTest
 */
class Schema_Printer
{
    /**
     * @param array<string, bool> $options
     *
     * @phpstan-param Options $options
     *
     * @api
     *
     * @throws \JsonException
     * @throws Error
     * @throws InvariantViolation
     * @throws SerializationError
     */
    public static function do_print(Schema $schema, array $options = []): string
    {
        return static::print_filtered_schema($schema, static fn(Directive $directive): bool => !Directive::is_specified_directive($directive), static fn(Named_Type $type): bool => !$type->is_built_in_type(), $options);
    }
    /**
     * @param array<string, bool> $options
     *
     * @phpstan-param Options $options
     *
     * @api
     *
     * @throws \JsonException
     * @throws Error
     * @throws InvariantViolation
     * @throws SerializationError
     */
    public static function print_introspection_schema(Schema $schema, array $options = []): string
    {
        return static::print_filtered_schema($schema, [Directive::class, 'isSpecifiedDirective'], [Introspection::class, 'isIntrospectionType'], $options);
    }
    /**
     * @param array<string, bool> $options
     *
     * @phpstan-param Options $options
     *
     * @throws \JsonException
     * @throws Error
     * @throws InvariantViolation
     * @throws SerializationError
     */
    public static function print_type(Type $type, array $options = []): string
    {
        if ($type instanceof Scalar_Type) {
            return static::print_scalar($type, $options);
        }
        if ($type instanceof Object_Type) {
            return static::print_object($type, $options);
        }
        if ($type instanceof Interface_Type) {
            return static::print_interface($type, $options);
        }
        if ($type instanceof Union_Type) {
            return static::print_union($type, $options);
        }
        if ($type instanceof Enum_Type) {
            return static::print_enum($type, $options);
        }
        if ($type instanceof Input_Object_Type) {
            return static::print_input_object($type, $options);
        }
        $unknown_type = Utils::print_safe($type);
        throw new Error("Unknown type: {$unknown_type}.");
    }
    /**
     * @param callable(Directive  $directive): bool $directiveFilter
     * @param callable(Type&NamedType $type): bool $typeFilter
     * @param array<string, bool> $options
     *
     * @phpstan-param Options $options
     *
     * @throws \JsonException
     * @throws Error
     * @throws InvariantViolation
     * @throws SerializationError
     */
    protected static function print_filtered_schema(Schema $schema, callable $directive_filter, callable $type_filter, array $options): string
    {
        $directives = array_filter($schema->get_directives(), $directive_filter);
        $types = array_filter($schema->get_type_map(), $type_filter);
        if (isset($options['sortTypes']) && $options['sortTypes']) {
            ksort($types);
        }
        $elements = [static::print_schema_definition($schema)];
        foreach ($directives as $directive) {
            $elements[] = static::print_directive($directive, $options);
        }
        foreach ($types as $type) {
            $elements[] = static::print_type($type, $options);
        }
        /** @phpstan-ignore arrayFilter.strict */
        return implode("\n\n", array_filter($elements)) . "\n";
    }
    /**
     * @throws \JsonException
     * @throws InvariantViolation
     */
    protected static function print_schema_definition(Schema $schema): ?string
    {
        $query_type = $schema->get_query_type();
        $mutation_type = $schema->get_mutation_type();
        $subscription_type = $schema->get_subscription_type();
        // Special case: When a schema has no root operation types, no valid schema
        // definition can be printed.
        if ($query_type === null && $mutation_type === null && $subscription_type === null) {
            return null;
        }
        // Only print a schema definition if there is a description or if it should
        // not be omitted because of having default type names.
        if ($schema->description !== null || !static::has_default_root_operation_types($schema)) {
            return static::print_description([], $schema) . "schema {\n" . ($query_type !== null ? "  query: {$query_type->name}\n" : '') . ($mutation_type !== null ? "  mutation: {$mutation_type->name}\n" : '') . ($subscription_type !== null ? "  subscription: {$subscription_type->name}\n" : '') . '}';
        }
        return null;
    }
    /**
     * GraphQL schema define root types for each type of operation. These types are
     * the same as any other type and can be named in any manner, however there is
     * a common naming convention:.
     *
     * ```graphql
     *   schema {
     *     query: Query
     *     mutation: Mutation
     *     subscription: Subscription
     *   }
     * ```
     *
     * When using this naming convention, the schema description can be omitted.
     * When using this naming convention, the schema description can be omitted so
     * long as these names are only used for operation types.
     *
     * Note however that if any of these default names are used elsewhere in the
     * schema but not as a root operation type, the schema definition must still
     * be printed to avoid ambiguity.
     *
     * @throws InvariantViolation
     */
    protected static function has_default_root_operation_types(Schema $schema): bool
    {
        return $schema->get_query_type() === $schema->get_type('Query') && $schema->get_mutation_type() === $schema->get_type('Mutation') && $schema->get_subscription_type() === $schema->get_type('Subscription');
    }
    /**
     * @param array<string, bool> $options
     *
     * @phpstan-param Options $options
     *
     * @throws \JsonException
     * @throws InvariantViolation
     * @throws SerializationError
     */
    protected static function print_directive(Directive $directive, array $options): string
    {
        return static::print_description($options, $directive) . 'directive @' . $directive->name . static::print_args($options, $directive->args) . ($directive->is_repeatable ? ' repeatable' : '') . ' on ' . implode(' | ', $directive->locations);
    }
    /**
     * @param array<string, bool> $options
     * @param (Type&NamedType)|Directive|EnumValueDefinition|Argument|FieldDefinition|InputObjectField|Schema $def
     *
     * @throws \JsonException
     */
    protected static function print_description(array $options, $def, string $indentation = '', bool $first_in_block = true): string
    {
        $description = $def->description;
        if ($description === null) {
            return '';
        }
        $prefix = $indentation !== '' && !$first_in_block ? "\n{$indentation}" : $indentation;
        if (count(Utils::split_lines($description)) === 1) {
            $description = json_encode($description, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        } else {
            $description = Block_String::print($description);
            $description = $indentation !== '' ? str_replace("\n", "\n{$indentation}", $description) : $description;
        }
        return "{$prefix}{$description}\n";
    }
    /**
     * @param array<string, bool> $options
     * @param array<int, Argument> $args
     *
     * @phpstan-param Options $options
     *
     * @throws \JsonException
     * @throws InvariantViolation
     * @throws SerializationError
     */
    protected static function print_args(array $options, array $args, string $indentation = ''): string
    {
        if ($args === []) {
            return '';
        }
        if (isset($options['sortArguments']) && $options['sortArguments']) {
            usort($args, static fn(Argument $left, Argument $right): int => $left->name <=> $right->name);
        }
        $all_args_without_description = true;
        foreach ($args as $arg) {
            $description = $arg->description;
            if ($description !== null && $description !== '') {
                $all_args_without_description = false;
                break;
            }
        }
        if ($all_args_without_description) {
            return '(' . implode(', ', array_map([static::class, 'printInputValue'], $args)) . ')';
        }
        $args_strings = [];
        $first_in_block = true;
        $previous_has_description = false;
        foreach ($args as $arg) {
            $has_description = $arg->description !== null;
            if ($previous_has_description && !$has_description) {
                $args_strings[] = '';
            }
            $args_strings[] = static::print_description($options, $arg, '  ' . $indentation, $first_in_block) . '  ' . $indentation . static::print_input_value($arg);
            $first_in_block = false;
            $previous_has_description = $has_description;
        }
        return "(\n" . implode("\n", $args_strings) . "\n" . $indentation . ')';
    }
    /**
     * @param InputObjectField|Argument $arg
     *
     * @throws \JsonException
     * @throws InvariantViolation
     * @throws SerializationError
     */
    protected static function print_input_value($arg): string
    {
        $arg_decl = "{$arg->name}: {$arg->get_type()->to_string()}";
        if ($arg->default_value_exists()) {
            $default_value_ast = AST::ast_from_value($arg->default_value, $arg->get_type());
            if ($default_value_ast === null) {
                $inconvertible_default_value = Utils::print_safe($arg->default_value);
                throw new Invariant_Violation("Unable to convert defaultValue of argument {$arg->name} into AST: {$inconvertible_default_value}.");
            }
            $printed_default_value = Printer::do_print($default_value_ast);
            $arg_decl .= " = {$printed_default_value}";
        }
        return $arg_decl . static::print_deprecated($arg);
    }
    /**
     * @param array<string, bool> $options
     *
     * @phpstan-param Options $options
     *
     * @throws \JsonException
     */
    protected static function print_scalar(Scalar_Type $type, array $options): string
    {
        return static::print_description($options, $type) . "scalar {$type->name}";
    }
    /**
     * @param array<string, bool> $options
     *
     * @phpstan-param Options $options
     *
     * @throws \JsonException
     * @throws InvariantViolation
     * @throws SerializationError
     */
    protected static function print_object(Object_Type $type, array $options): string
    {
        return static::print_description($options, $type) . "type {$type->name}" . static::print_implemented_interfaces($type) . static::print_fields($options, $type);
    }
    /**
     * @param array<string, bool> $options
     * @param ObjectType|InterfaceType $type
     *
     * @phpstan-param Options $options
     *
     * @throws \JsonException
     * @throws InvariantViolation
     * @throws SerializationError
     */
    protected static function print_fields(array $options, $type): string
    {
        $fields = [];
        $first_in_block = true;
        $previous_has_description = false;
        $field_definitions = $type->get_fields();
        if (isset($options['sortFields']) && $options['sortFields']) {
            ksort($field_definitions);
        }
        foreach ($field_definitions as $f) {
            $has_description = $f->description !== null;
            if ($previous_has_description && !$has_description) {
                $fields[] = '';
            }
            $fields[] = static::print_description($options, $f, '  ', $first_in_block) . '  ' . $f->name . static::print_args($options, $f->args, '  ') . ': ' . $f->get_type()->to_string() . static::print_deprecated($f);
            $first_in_block = false;
            $previous_has_description = $has_description;
        }
        return static::print_block($fields);
    }
    /**
     * @param FieldDefinition|EnumValueDefinition|InputObjectField|Argument $deprecation
     *
     * @throws \JsonException
     * @throws InvariantViolation
     * @throws SerializationError
     */
    protected static function print_deprecated($deprecation): string
    {
        $reason = $deprecation->deprecation_reason;
        if ($reason === null) {
            return '';
        }
        if ($reason === '' || $reason === Directive::DEFAULT_DEPRECATION_REASON) {
            return ' @deprecated';
        }
        $reason_ast = AST::ast_from_value($reason, Type::string());
        assert($reason_ast instanceof String_Value_Node);
        $reason_ast_string = Printer::do_print($reason_ast);
        return " @deprecated(reason: {$reason_ast_string})";
    }
    protected static function print_implemented_interfaces(Implementing_Type $type): string
    {
        $interfaces = $type->get_interfaces();
        return $interfaces === [] ? '' : ' implements ' . implode(' & ', array_map(static fn(Interface_Type $interface): string => $interface->name, $interfaces));
    }
    /**
     * @param array<string, bool> $options
     *
     * @phpstan-param Options $options
     *
     * @throws \JsonException
     * @throws InvariantViolation
     * @throws SerializationError
     */
    protected static function print_interface(Interface_Type $type, array $options): string
    {
        return static::print_description($options, $type) . "interface {$type->name}" . static::print_implemented_interfaces($type) . static::print_fields($options, $type);
    }
    /**
     * @param array<string, bool> $options
     *
     * @phpstan-param Options $options
     *
     * @throws \JsonException
     * @throws InvariantViolation
     */
    protected static function print_union(Union_Type $type, array $options): string
    {
        $types = $type->get_types();
        $types = $types === [] ? '' : ' = ' . implode(' | ', $types);
        return static::print_description($options, $type) . 'union ' . $type->name . $types;
    }
    /**
     * @param array<string, bool> $options
     *
     * @phpstan-param Options $options
     *
     * @throws \JsonException
     * @throws InvariantViolation
     * @throws SerializationError
     */
    protected static function print_enum(Enum_Type $type, array $options): string
    {
        $values = [];
        $first_in_block = true;
        $value_definitions = $type->get_values();
        if (isset($options['sortEnumValues']) && $options['sortEnumValues']) {
            usort($value_definitions, static fn(Enum_Value_Definition $left, Enum_Value_Definition $right): int => $left->name <=> $right->name);
        }
        foreach ($value_definitions as $value) {
            $values[] = static::print_description($options, $value, '  ', $first_in_block) . '  ' . $value->name . static::print_deprecated($value);
            $first_in_block = false;
        }
        return static::print_description($options, $type) . "enum {$type->name}" . static::print_block($values);
    }
    /**
     * @param array<string, bool> $options
     *
     * @phpstan-param Options $options
     *
     * @throws \JsonException
     * @throws InvariantViolation
     * @throws SerializationError
     */
    protected static function print_input_object(Input_Object_Type $type, array $options): string
    {
        $fields = [];
        $first_in_block = true;
        $field_definitions = $type->get_fields();
        if (isset($options['sortInputFields']) && $options['sortInputFields']) {
            ksort($field_definitions);
        }
        foreach ($field_definitions as $field) {
            $fields[] = static::print_description($options, $field, '  ', $first_in_block) . '  ' . static::print_input_value($field);
            $first_in_block = false;
        }
        return static::print_description($options, $type) . "input {$type->name}" . ($type->is_one_of() ? ' @oneOf' : '') . static::print_block($fields);
    }
    /** @param array<string> $items */
    protected static function print_block(array $items): string
    {
        return $items === [] ? '' : " {\n" . implode("\n", $items) . "\n}";
    }
}
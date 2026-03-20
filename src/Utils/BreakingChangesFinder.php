<?php

declare (strict_types=1);
namespace Graph_Ql\Utils;

use Graph_Ql\Error\Invariant_Violation;
use Graph_Ql\Type\Definition\Argument;
use Graph_Ql\Type\Definition\Directive;
use Graph_Ql\Type\Definition\Enum_Type;
use Graph_Ql\Type\Definition\Implementing_Type;
use Graph_Ql\Type\Definition\Input_Object_Type;
use Graph_Ql\Type\Definition\Interface_Type;
use Graph_Ql\Type\Definition\List_Of_Type;
use Graph_Ql\Type\Definition\Named_Type;
use Graph_Ql\Type\Definition\Non_Null;
use Graph_Ql\Type\Definition\Object_Type;
use Graph_Ql\Type\Definition\Scalar_Type;
use Graph_Ql\Type\Definition\Type;
use Graph_Ql\Type\Definition\Union_Type;
use Graph_Ql\Type\Schema;
/**
 * Utility for finding breaking/dangerous changes between two schemas.
 *
 * @phpstan-type Change array{type: string, description: string}
 * @phpstan-type Changes array{
 *     breakingChanges: array<int, Change>,
 *     dangerousChanges: array<int, Change>
 * }
 *
 * @see \GraphQL\Tests\Utils\BreakingChangesFinderTest
 */
class Breaking_Changes_Finder
{
    public const BREAKING_CHANGE_FIELD_CHANGED_KIND = 'FIELD_CHANGED_KIND';
    public const BREAKING_CHANGE_FIELD_REMOVED = 'FIELD_REMOVED';
    public const BREAKING_CHANGE_TYPE_CHANGED_KIND = 'TYPE_CHANGED_KIND';
    public const BREAKING_CHANGE_TYPE_REMOVED = 'TYPE_REMOVED';
    public const BREAKING_CHANGE_TYPE_REMOVED_FROM_UNION = 'TYPE_REMOVED_FROM_UNION';
    public const BREAKING_CHANGE_VALUE_REMOVED_FROM_ENUM = 'VALUE_REMOVED_FROM_ENUM';
    public const BREAKING_CHANGE_ARG_REMOVED = 'ARG_REMOVED';
    public const BREAKING_CHANGE_ARG_CHANGED_KIND = 'ARG_CHANGED_KIND';
    public const BREAKING_CHANGE_REQUIRED_ARG_ADDED = 'REQUIRED_ARG_ADDED';
    public const BREAKING_CHANGE_REQUIRED_INPUT_FIELD_ADDED = 'REQUIRED_INPUT_FIELD_ADDED';
    public const BREAKING_CHANGE_IMPLEMENTED_INTERFACE_REMOVED = 'IMPLEMENTED_INTERFACE_REMOVED';
    public const BREAKING_CHANGE_DIRECTIVE_REMOVED = 'DIRECTIVE_REMOVED';
    public const BREAKING_CHANGE_DIRECTIVE_ARG_REMOVED = 'DIRECTIVE_ARG_REMOVED';
    public const BREAKING_CHANGE_DIRECTIVE_LOCATION_REMOVED = 'DIRECTIVE_LOCATION_REMOVED';
    public const BREAKING_CHANGE_REQUIRED_DIRECTIVE_ARG_ADDED = 'REQUIRED_DIRECTIVE_ARG_ADDED';
    public const DANGEROUS_CHANGE_ARG_DEFAULT_VALUE_CHANGED = 'ARG_DEFAULT_VALUE_CHANGE';
    public const DANGEROUS_CHANGE_VALUE_ADDED_TO_ENUM = 'VALUE_ADDED_TO_ENUM';
    public const DANGEROUS_CHANGE_IMPLEMENTED_INTERFACE_ADDED = 'IMPLEMENTED_INTERFACE_ADDED';
    public const DANGEROUS_CHANGE_TYPE_ADDED_TO_UNION = 'TYPE_ADDED_TO_UNION';
    public const DANGEROUS_CHANGE_OPTIONAL_INPUT_FIELD_ADDED = 'OPTIONAL_INPUT_FIELD_ADDED';
    public const DANGEROUS_CHANGE_OPTIONAL_ARG_ADDED = 'OPTIONAL_ARG_ADDED';
    /**
     * Given two schemas, returns an Array containing descriptions of all the types
     * of breaking changes covered by the other functions down below.
     *
     * @throws \TypeError
     * @throws InvariantViolation
     *
     * @return array<int, Change>
     */
    public static function find_breaking_changes(Schema $old_schema, Schema $new_schema): array
    {
        return array_merge(self::find_removed_types($old_schema, $new_schema), self::find_types_that_changed_kind($old_schema, $new_schema), self::find_fields_that_changed_type_on_object_or_interface_types($old_schema, $new_schema), self::find_fields_that_changed_type_on_input_object_types($old_schema, $new_schema)['breakingChanges'], self::find_types_removed_from_unions($old_schema, $new_schema), self::find_values_removed_from_enums($old_schema, $new_schema), self::find_arg_changes($old_schema, $new_schema)['breakingChanges'], self::find_interfaces_removed_from_object_types($old_schema, $new_schema), self::find_removed_directives($old_schema, $new_schema), self::find_removed_directive_args($old_schema, $new_schema), self::find_added_non_null_directive_args($old_schema, $new_schema), self::find_removed_directive_locations($old_schema, $new_schema));
    }
    /**
     * Given two schemas, returns an Array containing descriptions of any breaking
     * changes in the newSchema related to removing an entire type.
     *
     * @throws InvariantViolation
     *
     * @return array<int, Change>
     */
    public static function find_removed_types(Schema $old_schema, Schema $new_schema): array
    {
        $old_type_map = $old_schema->get_type_map();
        $new_type_map = $new_schema->get_type_map();
        $breaking_changes = [];
        foreach (array_keys($old_type_map) as $type_name) {
            if (!isset($new_type_map[$type_name])) {
                $breaking_changes[] = ['type' => self::BREAKING_CHANGE_TYPE_REMOVED, 'description' => "{$type_name} was removed."];
            }
        }
        return $breaking_changes;
    }
    /**
     * Given two schemas, returns an Array containing descriptions of any breaking
     * changes in the newSchema related to changing the type of a type.
     *
     * @throws \TypeError
     * @throws InvariantViolation
     *
     * @return array<int, Change>
     */
    public static function find_types_that_changed_kind(Schema $schema_a, Schema $schema_b): array
    {
        $schema_a_type_map = $schema_a->get_type_map();
        $schema_b_type_map = $schema_b->get_type_map();
        $breaking_changes = [];
        foreach ($schema_a_type_map as $type_name => $schema_a_type) {
            if (!isset($schema_b_type_map[$type_name])) {
                continue;
            }
            $schema_b_type = $schema_b_type_map[$type_name];
            if ($schema_a_type instanceof $schema_b_type) {
                continue;
            }
            if ($schema_b_type instanceof $schema_a_type) {
                continue;
            }
            $schema_a_type_kind_name = self::type_kind_name($schema_a_type);
            $schema_b_type_kind_name = self::type_kind_name($schema_b_type);
            $breaking_changes[] = ['type' => self::BREAKING_CHANGE_TYPE_CHANGED_KIND, 'description' => "{$type_name} changed from {$schema_a_type_kind_name} to {$schema_b_type_kind_name}."];
        }
        return $breaking_changes;
    }
    /**
     * @param Type&NamedType $type
     *
     * @throws \TypeError
     */
    private static function type_kind_name(Named_Type $type): string
    {
        if ($type instanceof Scalar_Type) {
            return 'a Scalar type';
        }
        if ($type instanceof Object_Type) {
            return 'an Object type';
        }
        if ($type instanceof Interface_Type) {
            return 'an Interface type';
        }
        if ($type instanceof Union_Type) {
            return 'a Union type';
        }
        if ($type instanceof Enum_Type) {
            return 'an Enum type';
        }
        if ($type instanceof Input_Object_Type) {
            return 'an Input type';
        }
        throw new \TypeError('Unknown type: ' . $type->name);
    }
    /**
     * @throws InvariantViolation
     *
     * @return array<int, Change>
     */
    public static function find_fields_that_changed_type_on_object_or_interface_types(Schema $old_schema, Schema $new_schema): array
    {
        $old_type_map = $old_schema->get_type_map();
        $new_type_map = $new_schema->get_type_map();
        $breaking_changes = [];
        foreach ($old_type_map as $type_name => $old_type) {
            $new_type = $new_type_map[$type_name] ?? null;
            if (!$old_type instanceof Object_Type && !$old_type instanceof Interface_Type) {
                continue;
            }
            if (!$new_type instanceof Object_Type && !$new_type instanceof Interface_Type) {
                continue;
            }
            if (!$new_type instanceof $old_type) {
                continue;
            }
            $old_type_fields_def = $old_type->get_fields();
            $new_type_fields_def = $new_type->get_fields();
            foreach ($old_type_fields_def as $field_name => $field_definition) {
                // Check if the field is missing on the type in the new schema.
                if (!isset($new_type_fields_def[$field_name])) {
                    $breaking_changes[] = ['type' => self::BREAKING_CHANGE_FIELD_REMOVED, 'description' => "{$type_name}.{$field_name} was removed."];
                } else {
                    $old_field_type = $old_type_fields_def[$field_name]->get_type();
                    $new_field_type = $new_type_fields_def[$field_name]->get_type();
                    $is_safe = self::is_change_safe_for_object_or_interface_field($old_field_type, $new_field_type);
                    if (!$is_safe) {
                        $breaking_changes[] = ['type' => self::BREAKING_CHANGE_FIELD_CHANGED_KIND, 'description' => "{$type_name}.{$field_name} changed type from {$old_field_type} to {$new_field_type}."];
                    }
                }
            }
        }
        return $breaking_changes;
    }
    private static function is_change_safe_for_object_or_interface_field(Type $old_type, Type $new_type): bool
    {
        if ($old_type instanceof Named_Type) {
            return $new_type instanceof Named_Type && $old_type->name === $new_type->name || $new_type instanceof Non_Null && self::is_change_safe_for_object_or_interface_field($old_type, $new_type->get_wrapped_type());
        }
        if ($old_type instanceof List_Of_Type) {
            if ($new_type instanceof List_Of_Type && self::is_change_safe_for_object_or_interface_field($old_type->get_wrapped_type(), $new_type->get_wrapped_type())) {
                return true;
            }
            return $new_type instanceof Non_Null && self::is_change_safe_for_object_or_interface_field($old_type, $new_type->get_wrapped_type());
        }
        if ($old_type instanceof Non_Null) {
            // if they're both non-null, make sure the underlying types are compatible
            return $new_type instanceof Non_Null && self::is_change_safe_for_object_or_interface_field($old_type->get_wrapped_type(), $new_type->get_wrapped_type());
        }
        return false;
    }
    /**
     * @throws InvariantViolation
     *
     * @return Changes
     */
    public static function find_fields_that_changed_type_on_input_object_types(Schema $old_schema, Schema $new_schema): array
    {
        $old_type_map = $old_schema->get_type_map();
        $new_type_map = $new_schema->get_type_map();
        $breaking_changes = [];
        $dangerous_changes = [];
        foreach ($old_type_map as $type_name => $old_type) {
            $new_type = $new_type_map[$type_name] ?? null;
            if (!$old_type instanceof Input_Object_Type) {
                continue;
            }
            if (!$new_type instanceof Input_Object_Type) {
                continue;
            }
            $old_type_fields_def = $old_type->get_fields();
            $new_type_fields_def = $new_type->get_fields();
            foreach (array_keys($old_type_fields_def) as $field_name) {
                if (!isset($new_type_fields_def[$field_name])) {
                    $breaking_changes[] = ['type' => self::BREAKING_CHANGE_FIELD_REMOVED, 'description' => "{$type_name}.{$field_name} was removed."];
                } else {
                    $old_field_type = $old_type_fields_def[$field_name]->get_type();
                    $new_field_type = $new_type_fields_def[$field_name]->get_type();
                    $is_safe = self::is_change_safe_for_input_object_field_or_field_arg($old_field_type, $new_field_type);
                    if (!$is_safe) {
                        $old_field_type_string = $old_field_type instanceof Named_Type ? $old_field_type->name : $old_field_type;
                        $new_field_type_string = $new_field_type instanceof Named_Type ? $new_field_type->name : $new_field_type;
                        $breaking_changes[] = ['type' => self::BREAKING_CHANGE_FIELD_CHANGED_KIND, 'description' => "{$type_name}.{$field_name} changed type from {$old_field_type_string} to {$new_field_type_string}."];
                    }
                }
            }
            // Check if a field was added to the input object type
            foreach ($new_type_fields_def as $field_name => $field_def) {
                if (isset($old_type_fields_def[$field_name])) {
                    continue;
                }
                $new_type_name = $new_type->name;
                if ($field_def->is_required()) {
                    $breaking_changes[] = ['type' => self::BREAKING_CHANGE_REQUIRED_INPUT_FIELD_ADDED, 'description' => "A required field {$field_name} on input type {$new_type_name} was added."];
                } else {
                    $dangerous_changes[] = ['type' => self::DANGEROUS_CHANGE_OPTIONAL_INPUT_FIELD_ADDED, 'description' => "An optional field {$field_name} on input type {$new_type_name} was added."];
                }
            }
        }
        return ['breakingChanges' => $breaking_changes, 'dangerousChanges' => $dangerous_changes];
    }
    /** @throws InvariantViolation */
    private static function is_change_safe_for_input_object_field_or_field_arg(Type $old_type, Type $new_type): bool
    {
        if ($old_type instanceof Named_Type) {
            if (!$new_type instanceof Named_Type) {
                return false;
            }
            // if they're both named types, see if their names are equivalent
            return $old_type->name === $new_type->name;
        }
        if ($old_type instanceof List_Of_Type) {
            // if they're both lists, make sure the underlying types are compatible
            return $new_type instanceof List_Of_Type && self::is_change_safe_for_input_object_field_or_field_arg($old_type->get_wrapped_type(), $new_type->get_wrapped_type());
        }
        if ($old_type instanceof Non_Null) {
            if ($new_type instanceof Non_Null && self::is_change_safe_for_input_object_field_or_field_arg($old_type->get_wrapped_type(), $new_type->get_wrapped_type())) {
                return true;
            }
            return !$new_type instanceof Non_Null && self::is_change_safe_for_input_object_field_or_field_arg($old_type->get_wrapped_type(), $new_type);
        }
        return false;
    }
    /**
     * Given two schemas, returns an Array containing descriptions of any breaking
     * changes in the newSchema related to removing types from a union type.
     *
     * @throws InvariantViolation
     *
     * @return array<int, Change>
     */
    public static function find_types_removed_from_unions(Schema $old_schema, Schema $new_schema): array
    {
        $old_type_map = $old_schema->get_type_map();
        $new_type_map = $new_schema->get_type_map();
        $types_removed_from_union = [];
        foreach ($old_type_map as $type_name => $old_type) {
            $new_type = $new_type_map[$type_name] ?? null;
            if (!$old_type instanceof Union_Type) {
                continue;
            }
            if (!$new_type instanceof Union_Type) {
                continue;
            }
            $type_names_in_new_union = [];
            foreach ($new_type->get_types() as $type) {
                $type_names_in_new_union[$type->name] = true;
            }
            foreach ($old_type->get_types() as $type) {
                if (!isset($type_names_in_new_union[$type->name])) {
                    $types_removed_from_union[] = ['type' => self::BREAKING_CHANGE_TYPE_REMOVED_FROM_UNION, 'description' => "{$type->name} was removed from union type {$type_name}."];
                }
            }
        }
        return $types_removed_from_union;
    }
    /**
     * Given two schemas, returns an Array containing descriptions of any breaking
     * changes in the newSchema related to removing values from an enum type.
     *
     * @throws InvariantViolation
     *
     * @return array<int, Change>
     */
    public static function find_values_removed_from_enums(Schema $old_schema, Schema $new_schema): array
    {
        $old_type_map = $old_schema->get_type_map();
        $new_type_map = $new_schema->get_type_map();
        $values_removed_from_enums = [];
        foreach ($old_type_map as $type_name => $old_type) {
            $new_type = $new_type_map[$type_name] ?? null;
            if (!$old_type instanceof Enum_Type) {
                continue;
            }
            if (!$new_type instanceof Enum_Type) {
                continue;
            }
            $values_in_new_enum = [];
            foreach ($new_type->get_values() as $value) {
                $values_in_new_enum[$value->name] = true;
            }
            foreach ($old_type->get_values() as $value) {
                if (!isset($values_in_new_enum[$value->name])) {
                    $values_removed_from_enums[] = ['type' => self::BREAKING_CHANGE_VALUE_REMOVED_FROM_ENUM, 'description' => "{$value->name} was removed from enum type {$type_name}."];
                }
            }
        }
        return $values_removed_from_enums;
    }
    /**
     * Given two schemas, returns an Array containing descriptions of any
     * breaking or dangerous changes in the newSchema related to arguments
     * (such as removal or change of type of an argument, or a change in an
     * argument's default value).
     *
     * @throws InvariantViolation
     *
     * @return Changes
     */
    public static function find_arg_changes(Schema $old_schema, Schema $new_schema): array
    {
        $old_type_map = $old_schema->get_type_map();
        $new_type_map = $new_schema->get_type_map();
        $breaking_changes = [];
        $dangerous_changes = [];
        foreach ($old_type_map as $type_name => $old_type) {
            $new_type = $new_type_map[$type_name] ?? null;
            if (!$old_type instanceof Object_Type && !$old_type instanceof Interface_Type) {
                continue;
            }
            if (!$new_type instanceof Object_Type && !$new_type instanceof Interface_Type) {
                continue;
            }
            if (!$new_type instanceof $old_type) {
                continue;
            }
            $old_type_fields = $old_type->get_fields();
            $new_type_fields = $new_type->get_fields();
            foreach ($old_type_fields as $field_name => $old_field) {
                if (!isset($new_type_fields[$field_name])) {
                    continue;
                }
                foreach ($old_field->args as $old_arg_def) {
                    $new_arg_def = null;
                    foreach ($new_type_fields[$field_name]->args as $new_arg) {
                        if ($new_arg->name === $old_arg_def->name) {
                            $new_arg_def = $new_arg;
                        }
                    }
                    if ($new_arg_def !== null) {
                        $is_safe = self::is_change_safe_for_input_object_field_or_field_arg($old_arg_def->get_type(), $new_arg_def->get_type());
                        $old_arg_type = $old_arg_def->get_type();
                        $old_arg_name = $old_arg_def->name;
                        if (!$is_safe) {
                            $new_arg_type = $new_arg_def->get_type();
                            $breaking_changes[] = ['type' => self::BREAKING_CHANGE_ARG_CHANGED_KIND, 'description' => "{$type_name}.{$field_name} arg {$old_arg_name} has changed type from {$old_arg_type} to {$new_arg_type}"];
                        } elseif ($old_arg_def->default_value_exists() && $old_arg_def->default_value !== $new_arg_def->default_value) {
                            $dangerous_changes[] = ['type' => self::DANGEROUS_CHANGE_ARG_DEFAULT_VALUE_CHANGED, 'description' => "{$type_name}.{$field_name} arg {$old_arg_name} has changed defaultValue"];
                        }
                    } else {
                        $breaking_changes[] = ['type' => self::BREAKING_CHANGE_ARG_REMOVED, 'description' => "{$type_name}.{$field_name} arg {$old_arg_def->name} was removed"];
                    }
                    // Check if arg was added to the field
                    foreach ($new_type_fields[$field_name]->args as $new_type_field_arg_def) {
                        $old_arg_def = null;
                        foreach ($old_type_fields[$field_name]->args as $old_arg) {
                            if ($old_arg->name === $new_type_field_arg_def->name) {
                                $old_arg_def = $old_arg;
                            }
                        }
                        if ($old_arg_def !== null) {
                            continue;
                        }
                        $new_type_name = $new_type->name;
                        $new_arg_name = $new_type_field_arg_def->name;
                        if ($new_type_field_arg_def->is_required()) {
                            $breaking_changes[] = ['type' => self::BREAKING_CHANGE_REQUIRED_ARG_ADDED, 'description' => "A required arg {$new_arg_name} on {$new_type_name}.{$field_name} was added"];
                        } else {
                            $dangerous_changes[] = ['type' => self::DANGEROUS_CHANGE_OPTIONAL_ARG_ADDED, 'description' => "An optional arg {$new_arg_name} on {$new_type_name}.{$field_name} was added"];
                        }
                    }
                }
            }
        }
        return ['breakingChanges' => $breaking_changes, 'dangerousChanges' => $dangerous_changes];
    }
    /**
     * @throws InvariantViolation
     *
     * @return array<int, Change>
     */
    public static function find_interfaces_removed_from_object_types(Schema $old_schema, Schema $new_schema): array
    {
        $old_type_map = $old_schema->get_type_map();
        $new_type_map = $new_schema->get_type_map();
        $breaking_changes = [];
        foreach ($old_type_map as $type_name => $old_type) {
            $new_type = $new_type_map[$type_name] ?? null;
            if (!$old_type instanceof Implementing_Type) {
                continue;
            }
            if (!$new_type instanceof Implementing_Type) {
                continue;
            }
            $old_interfaces = $old_type->get_interfaces();
            $new_interfaces = $new_type->get_interfaces();
            foreach ($old_interfaces as $old_interface) {
                $interface_was_removed = true;
                foreach ($new_interfaces as $new_interface) {
                    if ($old_interface->name === $new_interface->name) {
                        $interface_was_removed = false;
                    }
                }
                if ($interface_was_removed) {
                    $breaking_changes[] = ['type' => self::BREAKING_CHANGE_IMPLEMENTED_INTERFACE_REMOVED, 'description' => "{$type_name} no longer implements interface {$old_interface->name}."];
                }
            }
        }
        return $breaking_changes;
    }
    /**
     * @throws InvariantViolation
     *
     * @return array<int, Change>
     */
    public static function find_removed_directives(Schema $old_schema, Schema $new_schema): array
    {
        $removed_directives = [];
        $new_schema_directive_map = self::get_directive_map_for_schema($new_schema);
        foreach ($old_schema->get_directives() as $directive) {
            if (!isset($new_schema_directive_map[$directive->name])) {
                $removed_directives[] = ['type' => self::BREAKING_CHANGE_DIRECTIVE_REMOVED, 'description' => "{$directive->name} was removed"];
            }
        }
        return $removed_directives;
    }
    /**
     * @throws InvariantViolation
     *
     * @return array<string, Directive>
     */
    private static function get_directive_map_for_schema(Schema $schema): array
    {
        $directives = [];
        foreach ($schema->get_directives() as $directive) {
            $directives[$directive->name] = $directive;
        }
        return $directives;
    }
    /**
     * @throws InvariantViolation
     *
     * @return array<int, Change>
     */
    public static function find_removed_directive_args(Schema $old_schema, Schema $new_schema): array
    {
        $removed_directive_args = [];
        $old_schema_directive_map = self::get_directive_map_for_schema($old_schema);
        foreach ($new_schema->get_directives() as $new_directive) {
            if (!isset($old_schema_directive_map[$new_directive->name])) {
                continue;
            }
            foreach (self::find_removed_args_for_directives($old_schema_directive_map[$new_directive->name], $new_directive) as $arg) {
                $removed_directive_args[] = ['type' => self::BREAKING_CHANGE_DIRECTIVE_ARG_REMOVED, 'description' => "{$arg->name} was removed from {$new_directive->name}"];
            }
        }
        return $removed_directive_args;
    }
    /** @return array<int, Argument> */
    public static function find_removed_args_for_directives(Directive $old_directive, Directive $new_directive): array
    {
        $removed_args = [];
        $new_arg_map = self::get_argument_map_for_directive($new_directive);
        foreach ($old_directive->args as $arg) {
            if (!isset($new_arg_map[$arg->name])) {
                $removed_args[] = $arg;
            }
        }
        return $removed_args;
    }
    /** @return array<string, Argument> */
    private static function get_argument_map_for_directive(Directive $directive): array
    {
        $args = [];
        foreach ($directive->args as $arg) {
            $args[$arg->name] = $arg;
        }
        return $args;
    }
    /**
     * @throws InvariantViolation
     *
     * @return array<int, Change>
     */
    public static function find_added_non_null_directive_args(Schema $old_schema, Schema $new_schema): array
    {
        $added_non_nullable_args = [];
        $old_schema_directive_map = self::get_directive_map_for_schema($old_schema);
        foreach ($new_schema->get_directives() as $new_directive) {
            if (!isset($old_schema_directive_map[$new_directive->name])) {
                continue;
            }
            foreach (self::find_added_args_for_directive($old_schema_directive_map[$new_directive->name], $new_directive) as $arg) {
                if ($arg->is_required()) {
                    $added_non_nullable_args[] = ['type' => self::BREAKING_CHANGE_REQUIRED_DIRECTIVE_ARG_ADDED, 'description' => "A required arg {$arg->name} on directive {$new_directive->name} was added"];
                }
            }
        }
        return $added_non_nullable_args;
    }
    /** @return array<int, Argument> */
    public static function find_added_args_for_directive(Directive $old_directive, Directive $new_directive): array
    {
        $added_args = [];
        $old_arg_map = self::get_argument_map_for_directive($old_directive);
        foreach ($new_directive->args as $arg) {
            if (!isset($old_arg_map[$arg->name])) {
                $added_args[] = $arg;
            }
        }
        return $added_args;
    }
    /**
     * @throws InvariantViolation
     *
     * @return array<int, Change>
     */
    public static function find_removed_directive_locations(Schema $old_schema, Schema $new_schema): array
    {
        $removed_locations = [];
        $old_schema_directive_map = self::get_directive_map_for_schema($old_schema);
        foreach ($new_schema->get_directives() as $new_directive) {
            if (!isset($old_schema_directive_map[$new_directive->name])) {
                continue;
            }
            foreach (self::find_removed_locations_for_directive($old_schema_directive_map[$new_directive->name], $new_directive) as $location) {
                $removed_locations[] = ['type' => self::BREAKING_CHANGE_DIRECTIVE_LOCATION_REMOVED, 'description' => "{$location} was removed from {$new_directive->name}"];
            }
        }
        return $removed_locations;
    }
    /** @return array<int, string> */
    public static function find_removed_locations_for_directive(Directive $old_directive, Directive $new_directive): array
    {
        $removed_locations = [];
        $new_location_set = array_flip($new_directive->locations);
        foreach ($old_directive->locations as $old_location) {
            if (!array_key_exists($old_location, $new_location_set)) {
                $removed_locations[] = $old_location;
            }
        }
        return $removed_locations;
    }
    /**
     * Given two schemas, returns an Array containing descriptions of all the types
     * of potentially dangerous changes covered by the other functions down below.
     *
     * @throws InvariantViolation
     *
     * @return array<int, Change>
     */
    public static function find_dangerous_changes(Schema $old_schema, Schema $new_schema): array
    {
        return array_merge(self::find_arg_changes($old_schema, $new_schema)['dangerousChanges'], self::find_values_added_to_enums($old_schema, $new_schema), self::find_interfaces_added_to_object_types($old_schema, $new_schema), self::find_types_added_to_unions($old_schema, $new_schema), self::find_fields_that_changed_type_on_input_object_types($old_schema, $new_schema)['dangerousChanges']);
    }
    /**
     * Given two schemas, returns an Array containing descriptions of any dangerous
     * changes in the newSchema related to adding values to an enum type.
     *
     * @throws InvariantViolation
     *
     * @return array<int, Change>
     */
    public static function find_values_added_to_enums(Schema $old_schema, Schema $new_schema): array
    {
        $old_type_map = $old_schema->get_type_map();
        $new_type_map = $new_schema->get_type_map();
        $values_added_to_enums = [];
        foreach ($old_type_map as $type_name => $old_type) {
            $new_type = $new_type_map[$type_name] ?? null;
            if (!$old_type instanceof Enum_Type) {
                continue;
            }
            if (!$new_type instanceof Enum_Type) {
                continue;
            }
            $values_in_old_enum = [];
            foreach ($old_type->get_values() as $value) {
                $values_in_old_enum[$value->name] = true;
            }
            foreach ($new_type->get_values() as $value) {
                if (!isset($values_in_old_enum[$value->name])) {
                    $values_added_to_enums[] = ['type' => self::DANGEROUS_CHANGE_VALUE_ADDED_TO_ENUM, 'description' => "{$value->name} was added to enum type {$type_name}."];
                }
            }
        }
        return $values_added_to_enums;
    }
    /**
     * @throws InvariantViolation
     *
     * @return array<int, Change>
     */
    public static function find_interfaces_added_to_object_types(Schema $old_schema, Schema $new_schema): array
    {
        $old_type_map = $old_schema->get_type_map();
        $new_type_map = $new_schema->get_type_map();
        $interfaces_added_to_object_types = [];
        foreach ($new_type_map as $type_name => $new_type) {
            $old_type = $old_type_map[$type_name] ?? null;
            if (!$old_type instanceof Object_Type && !$old_type instanceof Interface_Type) {
                continue;
            }
            if (!$new_type instanceof Object_Type && !$new_type instanceof Interface_Type) {
                continue;
            }
            $old_interfaces = $old_type->get_interfaces();
            $new_interfaces = $new_type->get_interfaces();
            foreach ($new_interfaces as $new_interface) {
                $interface_was_added = true;
                foreach ($old_interfaces as $old_interface) {
                    if ($old_interface->name === $new_interface->name) {
                        $interface_was_added = false;
                    }
                }
                if ($interface_was_added) {
                    $interfaces_added_to_object_types[] = ['type' => self::DANGEROUS_CHANGE_IMPLEMENTED_INTERFACE_ADDED, 'description' => "{$new_interface->name} added to interfaces implemented by {$type_name}."];
                }
            }
        }
        return $interfaces_added_to_object_types;
    }
    /**
     * Given two schemas, returns an Array containing descriptions of any dangerous
     * changes in the newSchema related to adding types to a union type.
     *
     * @throws InvariantViolation
     *
     * @return array<int, Change>
     */
    public static function find_types_added_to_unions(Schema $old_schema, Schema $new_schema): array
    {
        $old_type_map = $old_schema->get_type_map();
        $new_type_map = $new_schema->get_type_map();
        $types_added_to_union = [];
        foreach ($new_type_map as $type_name => $new_type) {
            $old_type = $old_type_map[$type_name] ?? null;
            if (!$old_type instanceof Union_Type) {
                continue;
            }
            if (!$new_type instanceof Union_Type) {
                continue;
            }
            $type_names_in_old_union = [];
            foreach ($old_type->get_types() as $type) {
                $type_names_in_old_union[$type->name] = true;
            }
            foreach ($new_type->get_types() as $type) {
                if (!isset($type_names_in_old_union[$type->name])) {
                    $types_added_to_union[] = ['type' => self::DANGEROUS_CHANGE_TYPE_ADDED_TO_UNION, 'description' => "{$type->name} was added to union type {$type_name}."];
                }
            }
        }
        return $types_added_to_union;
    }
}
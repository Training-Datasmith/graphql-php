<?php

declare (strict_types=1);
namespace Graph_Ql\Utils;

use Graph_Ql\Error\Invariant_Violation;
use Graph_Ql\Language\AST\Argument_Node;
use Graph_Ql\Language\AST\Directive_Node;
use Graph_Ql\Language\AST\Enum_Value_Node;
use Graph_Ql\Language\AST\Field_Node;
use Graph_Ql\Language\AST\Fragment_Definition_Node;
use Graph_Ql\Language\AST\Inline_Fragment_Node;
use Graph_Ql\Language\AST\List_Value_Node;
use Graph_Ql\Language\AST\Node;
use Graph_Ql\Language\AST\Node_Kind;
use Graph_Ql\Language\AST\Object_Field_Node;
use Graph_Ql\Language\AST\Operation_Definition_Node;
use Graph_Ql\Language\AST\Selection_Set_Node;
use Graph_Ql\Language\AST\Variable_Definition_Node;
use Graph_Ql\Type\Definition\Argument;
use Graph_Ql\Type\Definition\Composite_Type;
use Graph_Ql\Type\Definition\Directive;
use Graph_Ql\Type\Definition\Enum_Type;
use Graph_Ql\Type\Definition\Field_Definition;
use Graph_Ql\Type\Definition\Has_Fields_Type;
use Graph_Ql\Type\Definition\Implementing_Type;
use Graph_Ql\Type\Definition\Input_Object_Type;
use Graph_Ql\Type\Definition\Input_Type;
use Graph_Ql\Type\Definition\Interface_Type;
use Graph_Ql\Type\Definition\List_Of_Type;
use Graph_Ql\Type\Definition\Named_Type;
use Graph_Ql\Type\Definition\Non_Null;
use Graph_Ql\Type\Definition\Object_Type;
use Graph_Ql\Type\Definition\Type;
use Graph_Ql\Type\Definition\Union_Type;
use Graph_Ql\Type\Definition\Wrapping_Type;
use Graph_Ql\Type\Introspection;
use Graph_Ql\Type\Schema;
class Type_Info
{
    private Schema $schema;
    /** @var array<int, Type|null> */
    private array $type_stack = [];
    /** @var array<int, (CompositeType&Type)|null> */
    private array $parent_type_stack = [];
    /** @var array<int, (InputType&Type)|null> */
    private array $input_type_stack = [];
    /** @var array<int, FieldDefinition|null> */
    private array $field_def_stack = [];
    /** @var array<int, mixed> */
    private array $default_value_stack = [];
    private ?Directive $directive = null;
    private ?Argument $argument = null;
    private ?\Graph_Ql\Type\Definition\Enum_Value_Definition $enum_value = null;
    public function __construct(Schema $schema)
    {
        $this->schema = $schema;
    }
    /** @return array<int, (CompositeType&Type)|null> */
    public function get_parent_type_stack(): array
    {
        return $this->parent_type_stack;
    }
    /** @return array<int, FieldDefinition|null> */
    public function get_field_def_stack(): array
    {
        return $this->field_def_stack;
    }
    /**
     * Given root type scans through all fields to find nested types.
     *
     * Returns array where keys are for type name
     * and value contains corresponding type instance.
     *
     * Example output:
     * [
     *     'String' => $instanceOfStringType,
     *     'MyType' => $instanceOfMyType,
     *     ...
     * ]
     *
     * @param (Type&NamedType)|(Type&WrappingType) $type
     * @param array<string, Type&NamedType> $typeMap
     *
     * @throws InvariantViolation
     */
    public static function extract_types(Type $type, array &$type_map): void
    {
        if ($type instanceof Wrapping_Type) {
            self::extract_types($type->get_innermost_type(), $type_map);
            return;
        }
        $name = $type->name;
        assert(is_string($name));
        if (isset($type_map[$name])) {
            if ($type_map[$name] !== $type) {
                throw new Invariant_Violation("Schema must contain unique named types but contains multiple types named \"{$type}\" (see https://webonyx.github.io/graphql-php/type-definitions/#type-registry).");
            }
            return;
        }
        $type_map[$name] = $type;
        if ($type instanceof Union_Type) {
            foreach ($type->get_types() as $member) {
                self::extract_types($member, $type_map);
            }
            return;
        }
        if ($type instanceof Input_Object_Type) {
            foreach ($type->get_fields() as $field) {
                $field_type = $field->get_type();
                assert($field_type instanceof Named_Type || $field_type instanceof Wrapping_Type);
                self::extract_types($field_type, $type_map);
            }
            return;
        }
        if ($type instanceof Implementing_Type) {
            foreach ($type->get_interfaces() as $interface) {
                self::extract_types($interface, $type_map);
            }
        }
        if ($type instanceof Has_Fields_Type) {
            foreach ($type->get_fields() as $field) {
                foreach ($field->args as $arg) {
                    $arg_type = $arg->get_type();
                    assert($arg_type instanceof Named_Type || $arg_type instanceof Wrapping_Type);
                    self::extract_types($arg_type, $type_map);
                }
                $field_type = $field->get_type();
                assert($field_type instanceof Named_Type || $field_type instanceof Wrapping_Type);
                self::extract_types($field_type, $type_map);
            }
        }
    }
    /**
     * @param array<string, Type&NamedType> $typeMap
     *
     * @throws InvariantViolation
     */
    public static function extract_types_from_directives(Directive $directive, array &$type_map): void
    {
        foreach ($directive->args as $arg) {
            $arg_type = $arg->get_type();
            assert($arg_type instanceof Named_Type || $arg_type instanceof Wrapping_Type);
            self::extract_types($arg_type, $type_map);
        }
    }
    /** @return (Type&InputType)|null */
    public function get_parent_input_type(): ?Input_Type
    {
        return $this->input_type_stack[count($this->input_type_stack) - 2] ?? null;
    }
    public function get_argument(): ?Argument
    {
        return $this->argument;
    }
    /** @return mixed */
    public function get_enum_value()
    {
        return $this->enum_value;
    }
    /**
     * @throws \Exception
     * @throws InvariantViolation
     */
    public function enter(Node $node): void
    {
        $schema = $this->schema;
        // Note: many of the types below are explicitly typed as "mixed" to drop
        // any assumptions of a valid schema to ensure runtime types are properly
        // checked before continuing since TypeInfo is used as part of validation
        // which occurs before guarantees of schema and document validity.
        switch (true) {
            case $node instanceof Selection_Set_Node:
                $named_type = Type::get_named_type($this->get_type());
                $this->parent_type_stack[] = Type::is_composite_type($named_type) ? $named_type : null;
                break;
            case $node instanceof Field_Node:
                $parent_type = $this->get_parent_type();
                $field_def = $parent_type === null ? null : self::get_field_definition($schema, $parent_type, $node);
                $field_type = $field_def === null ? null : $field_def->get_type();
                $this->field_def_stack[] = $field_def;
                $this->type_stack[] = $field_type;
                break;
            case $node instanceof Directive_Node:
                $this->directive = $schema->get_directive($node->name->value);
                break;
            case $node instanceof Operation_Definition_Node:
                if ($node->operation === 'query') {
                    $type = $schema->get_query_type();
                } elseif ($node->operation === 'mutation') {
                    $type = $schema->get_mutation_type();
                } else {
                    // Only other option
                    $type = $schema->get_subscription_type();
                }
                $this->type_stack[] = Type::is_output_type($type) ? $type : null;
                break;
            case $node instanceof Inline_Fragment_Node:
            case $node instanceof Fragment_Definition_Node:
                $type_condition_node = $node->type_condition;
                $output_type = $type_condition_node === null ? Type::get_named_type($this->get_type()) : AST::type_from_ast([$schema, 'getType'], $type_condition_node);
                $this->type_stack[] = Type::is_output_type($output_type) ? $output_type : null;
                break;
            case $node instanceof Variable_Definition_Node:
                $input_type = AST::type_from_ast([$schema, 'getType'], $node->type);
                $this->input_type_stack[] = Type::is_input_type($input_type) ? $input_type : null;
                // push
                break;
            case $node instanceof Argument_Node:
                $field_or_directive = $this->get_directive() ?? $this->get_field_def();
                $arg_def = null;
                $arg_type = null;
                if ($field_or_directive !== null) {
                    foreach ($field_or_directive->args as $arg) {
                        if ($arg->name === $node->name->value) {
                            $arg_def = $arg;
                            $arg_type = $arg->get_type();
                        }
                    }
                }
                $this->argument = $arg_def;
                $this->default_value_stack[] = $arg_def !== null && $arg_def->default_value_exists() ? $arg_def->default_value : Utils::undefined();
                $this->input_type_stack[] = Type::is_input_type($arg_type) ? $arg_type : null;
                break;
            case $node instanceof List_Value_Node:
                $type = $this->get_input_type();
                $list_type = $type instanceof Non_Null ? $type->get_wrapped_type() : $type;
                $item_type = $list_type instanceof List_Of_Type ? $list_type->get_wrapped_type() : $list_type;
                // List positions never have a default value.
                $this->default_value_stack[] = Utils::undefined();
                $this->input_type_stack[] = Type::is_input_type($item_type) ? $item_type : null;
                break;
            case $node instanceof Object_Field_Node:
                $object_type = Type::get_named_type($this->get_input_type());
                $input_field = null;
                $input_field_type = null;
                if ($object_type instanceof Input_Object_Type) {
                    $tmp = $object_type->get_fields();
                    $input_field = $tmp[$node->name->value] ?? null;
                    $input_field_type = $input_field === null ? null : $input_field->get_type();
                }
                $this->default_value_stack[] = $input_field !== null && $input_field->default_value_exists() ? $input_field->default_value : Utils::undefined();
                $this->input_type_stack[] = Type::is_input_type($input_field_type) ? $input_field_type : null;
                break;
            case $node instanceof Enum_Value_Node:
                $enum_type = Type::get_named_type($this->get_input_type());
                $this->enum_value = $enum_type instanceof Enum_Type ? $enum_type->get_value($node->value) : null;
                break;
        }
    }
    public function get_type(): ?Type
    {
        return $this->type_stack[count($this->type_stack) - 1] ?? null;
    }
    /** @return (CompositeType&Type)|null */
    public function get_parent_type(): ?Composite_Type
    {
        return $this->parent_type_stack[count($this->parent_type_stack) - 1] ?? null;
    }
    /**
     * Not exactly the same as the executor's definition of getFieldDef, in this
     * statically evaluated environment we do not always have an Object type,
     * and need to handle Interface and Union types.
     *
     * @throws InvariantViolation
     */
    private static function get_field_definition(Schema $schema, Type $parent_type, Field_Node $field_node): ?Field_Definition
    {
        $name = $field_node->name->value;
        $schema_meta = Introspection::schema_meta_field_def();
        if ($name === $schema_meta->name && $schema->get_query_type() === $parent_type) {
            return $schema_meta;
        }
        $type_meta = Introspection::type_meta_field_def();
        if ($name === $type_meta->name && $schema->get_query_type() === $parent_type) {
            return $type_meta;
        }
        $type_name_meta = Introspection::type_name_meta_field_def();
        if ($name === $type_name_meta->name && $parent_type instanceof Composite_Type) {
            return $type_name_meta;
        }
        if ($parent_type instanceof Object_Type || $parent_type instanceof Interface_Type) {
            return $parent_type->find_field($name);
        }
        return null;
    }
    public function get_directive(): ?Directive
    {
        return $this->directive;
    }
    public function get_field_def(): ?Field_Definition
    {
        return $this->field_def_stack[count($this->field_def_stack) - 1] ?? null;
    }
    /** @return mixed any value is possible */
    public function get_default_value()
    {
        return $this->default_value_stack[count($this->default_value_stack) - 1] ?? null;
    }
    /** @return (InputType&Type)|null */
    public function get_input_type(): ?Input_Type
    {
        return $this->input_type_stack[count($this->input_type_stack) - 1] ?? null;
    }
    public function leave(Node $node): void
    {
        switch ($node->kind) {
            case Node_Kind::SELECTION_SET:
                array_pop($this->parent_type_stack);
                break;
            case Node_Kind::FIELD:
                array_pop($this->field_def_stack);
                array_pop($this->type_stack);
                break;
            case Node_Kind::DIRECTIVE:
                $this->directive = null;
                break;
            case Node_Kind::OPERATION_DEFINITION:
            case Node_Kind::INLINE_FRAGMENT:
            case Node_Kind::FRAGMENT_DEFINITION:
                array_pop($this->type_stack);
                break;
            case Node_Kind::VARIABLE_DEFINITION:
                array_pop($this->input_type_stack);
                break;
            case Node_Kind::ARGUMENT:
                $this->argument = null;
                array_pop($this->default_value_stack);
                array_pop($this->input_type_stack);
                break;
            case Node_Kind::LST:
            case Node_Kind::OBJECT_FIELD:
                array_pop($this->default_value_stack);
                array_pop($this->input_type_stack);
                break;
            case Node_Kind::ENUM:
                $this->enum_value = null;
                break;
        }
    }
}
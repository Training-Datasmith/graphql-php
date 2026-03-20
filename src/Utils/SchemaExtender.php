<?php

declare (strict_types=1);
namespace Graph_Ql\Utils;

use Graph_Ql\Error\Error;
use Graph_Ql\Error\Invariant_Violation;
use Graph_Ql\Language\AST\Directive_Definition_Node;
use Graph_Ql\Language\AST\Document_Node;
use Graph_Ql\Language\AST\Enum_Type_Extension_Node;
use Graph_Ql\Language\AST\Input_Object_Type_Extension_Node;
use Graph_Ql\Language\AST\Interface_Type_Extension_Node;
use Graph_Ql\Language\AST\Node;
use Graph_Ql\Language\AST\Object_Type_Extension_Node;
use Graph_Ql\Language\AST\Scalar_Type_Extension_Node;
use Graph_Ql\Language\AST\Schema_Definition_Node;
use Graph_Ql\Language\AST\Schema_Extension_Node;
use Graph_Ql\Language\AST\Type_Definition_Node;
use Graph_Ql\Language\AST\Type_Extension_Node;
use Graph_Ql\Language\AST\Union_Type_Extension_Node;
use Graph_Ql\Type\Definition\Argument;
use Graph_Ql\Type\Definition\Custom_Scalar_Type;
use Graph_Ql\Type\Definition\Directive;
use Graph_Ql\Type\Definition\Enum_Type;
use Graph_Ql\Type\Definition\Implementing_Type;
use Graph_Ql\Type\Definition\Input_Object_Field;
use Graph_Ql\Type\Definition\Input_Object_Type;
use Graph_Ql\Type\Definition\Interface_Type;
use Graph_Ql\Type\Definition\List_Of_Type;
use Graph_Ql\Type\Definition\Named_Type;
use Graph_Ql\Type\Definition\Non_Null;
use Graph_Ql\Type\Definition\Object_Type;
use Graph_Ql\Type\Definition\Scalar_Type;
use Graph_Ql\Type\Definition\Type;
use Graph_Ql\Type\Definition\Union_Type;
use Graph_Ql\Type\Introspection;
use Graph_Ql\Type\Schema;
use Graph_Ql\Type\Schema_Config;
use Graph_Ql\Validator\Document_Validator;
/**
 * @phpstan-import-type TypeConfigDecorator from ASTDefinitionBuilder
 * @phpstan-import-type FieldConfigDecorator from ASTDefinitionBuilder
 * @phpstan-import-type UnnamedArgumentConfig from Argument
 * @phpstan-import-type UnnamedInputObjectFieldConfig from InputObjectField
 *
 * @see \GraphQL\Tests\Utils\SchemaExtenderTest
 */
class Schema_Extender
{
    /** @var array<string, Type> */
    protected array $extend_type_cache = [];
    /** @var array<string, array<TypeExtensionNode>> */
    protected array $type_extensions_map = [];
    protected Ast_Definition_Builder $ast_builder;
    /**
     * @param array<string, bool> $options
     *
     * @phpstan-param TypeConfigDecorator|null $typeConfigDecorator
     * @phpstan-param FieldConfigDecorator|null $fieldConfigDecorator
     *
     * @api
     *
     * @throws \Exception
     * @throws InvariantViolation
     */
    public static function extend(Schema $schema, Document_Node $document_ast, array $options = [], ?callable $type_config_decorator = null, ?callable $field_config_decorator = null): Schema
    {
        return (new static())->do_extend($schema, $document_ast, $options, $type_config_decorator, $field_config_decorator);
    }
    /**
     * @param array<string, bool> $options
     *
     * @phpstan-param TypeConfigDecorator|null $typeConfigDecorator
     * @phpstan-param FieldConfigDecorator|null $fieldConfigDecorator
     *
     * @throws \Exception
     * @throws \ReflectionException
     * @throws Error
     * @throws InvariantViolation
     */
    protected function do_extend(Schema $schema, Document_Node $document_ast, array $options = [], ?callable $type_config_decorator = null, ?callable $field_config_decorator = null): Schema
    {
        if (!($options['assumeValid'] ?? false) && !($options['assumeValidSDL'] ?? false)) {
            Document_Validator::assert_valid_sdl_extension($document_ast, $schema);
        }
        /** @var array<string, Node&TypeDefinitionNode> $typeDefinitionMap */
        $type_definition_map = [];
        /** @var array<int, DirectiveDefinitionNode> $directiveDefinitions */
        $directive_definitions = [];
        /** @var SchemaDefinitionNode|null $schemaDef */
        $schema_def = null;
        /** @var array<int, SchemaExtensionNode> $schemaExtensions */
        $schema_extensions = [];
        foreach ($document_ast->definitions as $def) {
            if ($def instanceof Schema_Definition_Node) {
                $schema_def = $def;
            } elseif ($def instanceof Schema_Extension_Node) {
                $schema_extensions[] = $def;
            } elseif ($def instanceof Type_Definition_Node) {
                $name = $def->get_name()->value;
                $type_definition_map[$name] = $def;
            } elseif ($def instanceof Type_Extension_Node) {
                $name = $def->get_name()->value;
                $this->type_extensions_map[$name][] = $def;
            } elseif ($def instanceof Directive_Definition_Node) {
                $directive_definitions[] = $def;
            }
        }
        if ($this->type_extensions_map === [] && $type_definition_map === [] && $directive_definitions === [] && $schema_extensions === [] && $schema_def === null) {
            return $schema;
        }
        $this->ast_builder = new Ast_Definition_Builder(
            $type_definition_map,
            [],
            // @phpstan-ignore-next-line no idea what is wrong here
            function (string $type_name) use ($schema): Type {
                $existing_type = $schema->get_type($type_name);
                if ($existing_type === null) {
                    throw new Invariant_Violation("Unknown type: \"{$type_name}\".");
                }
                return $this->extend_named_type($existing_type);
            },
            $type_config_decorator,
            $field_config_decorator
        );
        $this->extend_type_cache = [];
        $types = [];
        // Iterate through all types, getting the type definition for each, ensuring
        // that any type not directly referenced by a field will get created.
        foreach ($schema->get_type_map() as $type) {
            $types[] = $this->extend_named_type($type);
        }
        // Do the same with new types.
        foreach ($type_definition_map as $type) {
            $types[] = $this->ast_builder->build_type($type);
        }
        $operation_types = ['query' => $this->extend_maybe_named_type($schema->get_query_type()), 'mutation' => $this->extend_maybe_named_type($schema->get_mutation_type()), 'subscription' => $this->extend_maybe_named_type($schema->get_subscription_type())];
        if ($schema_def !== null) {
            foreach ($schema_def->operation_types as $operation_type) {
                $operation_types[$operation_type->operation] = $this->ast_builder->build_type($operation_type->type);
            }
        }
        foreach ($schema_extensions as $schema_extension) {
            foreach ($schema_extension->operation_types as $operation_type) {
                $operation_types[$operation_type->operation] = $this->ast_builder->build_type($operation_type->type);
            }
        }
        $schema_config = (new Schema_Config())->set_description($schema_def->description->value ?? $schema->description ?? null)->set_query($operation_types['query'])->set_mutation($operation_types['mutation'])->set_subscription($operation_types['subscription'])->set_types($types)->set_directives($this->get_merged_directives($schema, $directive_definitions))->set_ast_node($schema->ast_node ?? $schema_def)->set_extension_ast_nodes([...$schema->extension_ast_nodes, ...$schema_extensions]);
        return new Schema($schema_config);
    }
    /**
     * @param Type&NamedType $type
     *
     * @return array<TypeExtensionNode>|null
     */
    protected function extension_ast_nodes(Named_Type $type): ?array
    {
        return [...$type->extension_ast_nodes ?? [], ...$this->type_extensions_map[$type->name] ?? []];
    }
    /**
     * @throws \Exception
     * @throws \ReflectionException
     * @throws InvariantViolation
     */
    protected function extend_scalar_type(Scalar_Type $type): Custom_Scalar_Type
    {
        /** @var array<ScalarTypeExtensionNode> $extensionASTNodes */
        $extension_ast_nodes = $this->extension_ast_nodes($type);
        return new Custom_Scalar_Type(['name' => $type->name, 'description' => $type->description, 'serialize' => [$type, 'serialize'], 'parseValue' => [$type, 'parseValue'], 'parseLiteral' => [$type, 'parseLiteral'], 'astNode' => $type->ast_node, 'extensionASTNodes' => $extension_ast_nodes]);
    }
    /** @throws InvariantViolation */
    protected function extend_union_type(Union_Type $type): Union_Type
    {
        /** @var array<UnionTypeExtensionNode> $extensionASTNodes */
        $extension_ast_nodes = $this->extension_ast_nodes($type);
        return new Union_Type(['name' => $type->name, 'description' => $type->description, 'types' => fn(): array => $this->extend_union_possible_types($type), 'resolveType' => [$type, 'resolveType'], 'astNode' => $type->ast_node, 'extensionASTNodes' => $extension_ast_nodes]);
    }
    /**
     * @throws \Exception
     * @throws \ReflectionException
     * @throws InvariantViolation
     */
    protected function extend_enum_type(Enum_Type $type): Enum_Type
    {
        /** @var array<EnumTypeExtensionNode> $extensionASTNodes */
        $extension_ast_nodes = $this->extension_ast_nodes($type);
        return new Enum_Type(['name' => $type->name, 'description' => $type->description, 'values' => $this->extend_enum_value_map($type), 'astNode' => $type->ast_node, 'extensionASTNodes' => $extension_ast_nodes]);
    }
    /** @throws InvariantViolation */
    protected function extend_input_object_type(Input_Object_Type $type): Input_Object_Type
    {
        /** @var array<InputObjectTypeExtensionNode> $extensionASTNodes */
        $extension_ast_nodes = $this->extension_ast_nodes($type);
        return new Input_Object_Type(['name' => $type->name, 'description' => $type->description, 'fields' => fn(): array => $this->extend_input_field_map($type), 'parseValue' => [$type, 'parseValue'], 'astNode' => $type->ast_node, 'extensionASTNodes' => $extension_ast_nodes, 'isOneOf' => $type->is_one_of]);
    }
    /**
     * @throws \Exception
     * @throws InvariantViolation
     *
     * @return array<string, UnnamedInputObjectFieldConfig>
     */
    protected function extend_input_field_map(Input_Object_Type $type): array
    {
        /** @var array<string, UnnamedInputObjectFieldConfig> $newFieldMap */
        $new_field_map = [];
        $old_field_map = $type->get_fields();
        foreach ($old_field_map as $field_name => $field) {
            $extended_type = $this->extend_type($field->get_type());
            $new_field_config = ['description' => $field->description, 'type' => $extended_type, 'deprecationReason' => $field->deprecation_reason, 'astNode' => $field->ast_node];
            if ($field->default_value_exists()) {
                $new_field_config['defaultValue'] = $field->default_value;
            }
            $new_field_map[$field_name] = $new_field_config;
        }
        if (isset($this->type_extensions_map[$type->name])) {
            foreach ($this->type_extensions_map[$type->name] as $extension) {
                assert($extension instanceof Input_Object_Type_Extension_Node, 'proven by schema validation');
                foreach ($extension->fields as $field) {
                    $new_field_map[$field->name->value] = $this->ast_builder->build_input_field($field);
                }
            }
        }
        return $new_field_map;
    }
    /**
     * @throws \Exception
     * @throws InvariantViolation
     *
     * @return array<string, array<string, mixed>>
     */
    protected function extend_enum_value_map(Enum_Type $type): array
    {
        $new_value_map = [];
        foreach ($type->get_values() as $value) {
            $new_value_map[$value->name] = ['name' => $value->name, 'description' => $value->description, 'value' => $value->value, 'deprecationReason' => $value->deprecation_reason, 'astNode' => $value->ast_node];
        }
        if (isset($this->type_extensions_map[$type->name])) {
            foreach ($this->type_extensions_map[$type->name] as $extension) {
                assert($extension instanceof Enum_Type_Extension_Node, 'proven by schema validation');
                foreach ($extension->values as $value) {
                    $new_value_map[$value->name->value] = $this->ast_builder->build_enum_value($value);
                }
            }
        }
        return $new_value_map;
    }
    /**
     * @throws \Exception
     * @throws \ReflectionException
     * @throws Error
     * @throws InvariantViolation
     *
     * @return array<int, ObjectType>
     */
    protected function extend_union_possible_types(Union_Type $type): array
    {
        $possible_types = array_map([$this, 'extendNamedType'], $type->get_types());
        if (isset($this->type_extensions_map[$type->name])) {
            foreach ($this->type_extensions_map[$type->name] as $extension) {
                assert($extension instanceof Union_Type_Extension_Node, 'proven by schema validation');
                foreach ($extension->types as $named_type) {
                    $possible_types[] = $this->ast_builder->build_type($named_type);
                }
            }
        }
        // @phpstan-ignore-next-line proven by schema validation
        return $possible_types;
    }
    /**
     * @param ObjectType|InterfaceType $type
     *
     * @throws \Exception
     * @throws \ReflectionException
     * @throws Error
     * @throws InvariantViolation
     *
     * @return array<int, InterfaceType>
     */
    protected function extend_implemented_interfaces(Implementing_Type $type): array
    {
        $interfaces = array_map([$this, 'extendNamedType'], $type->get_interfaces());
        if (isset($this->type_extensions_map[$type->name])) {
            foreach ($this->type_extensions_map[$type->name] as $extension) {
                assert($extension instanceof Object_Type_Extension_Node || $extension instanceof Interface_Type_Extension_Node, 'proven by schema validation');
                foreach ($extension->interfaces as $named_type) {
                    $interface = $this->ast_builder->build_type($named_type);
                    assert($interface instanceof Interface_Type, 'we know this, but PHP templates cannot express it');
                    $interfaces[] = $interface;
                }
            }
        }
        return $interfaces;
    }
    /**
     * @template T of Type
     *
     * @param T $typeDef
     *
     * @return T
     */
    protected function extend_type(Type $type_def): Type
    {
        if ($type_def instanceof List_Of_Type) {
            // @phpstan-ignore-next-line PHPStan does not understand this is the same generic type as the input
            return Type::list_of($this->extend_type($type_def->get_wrapped_type()));
        }
        if ($type_def instanceof Non_Null) {
            // @phpstan-ignore-next-line PHPStan does not understand this is the same generic type as the input
            return Type::non_null($this->extend_type($type_def->get_wrapped_type()));
        }
        // @phpstan-ignore-next-line PHPStan does not understand this is the same generic type as the input
        return $this->extend_named_type($type_def);
    }
    /**
     * @param array<Argument> $args
     *
     * @return array<string, UnnamedArgumentConfig>
     */
    protected function extend_args(array $args): array
    {
        $extended = [];
        foreach ($args as $arg) {
            $extended_type = $this->extend_type($arg->get_type());
            $def = ['type' => $extended_type, 'description' => $arg->description, 'deprecationReason' => $arg->deprecation_reason, 'astNode' => $arg->ast_node];
            if ($arg->default_value_exists()) {
                $def['defaultValue'] = $arg->default_value;
            }
            $extended[$arg->name] = $def;
        }
        return $extended;
    }
    /**
     * @param InterfaceType|ObjectType $type
     *
     * @throws \Exception
     * @throws Error
     * @throws InvariantViolation
     *
     * @return array<string, array<string, mixed>>
     */
    protected function extend_field_map(Type $type): array
    {
        $new_field_map = [];
        $old_field_map = $type->get_fields();
        foreach (array_keys($old_field_map) as $field_name) {
            $field = $old_field_map[$field_name];
            $new_field_map[$field_name] = ['name' => $field_name, 'description' => $field->description, 'deprecationReason' => $field->deprecation_reason, 'type' => $this->extend_type($field->get_type()), 'args' => $this->extend_args($field->args), 'resolve' => $field->resolve_fn, 'argsMapper' => $field->args_mapper, 'astNode' => $field->ast_node];
        }
        if (isset($this->type_extensions_map[$type->name])) {
            foreach ($this->type_extensions_map[$type->name] as $extension) {
                assert($extension instanceof Object_Type_Extension_Node || $extension instanceof Interface_Type_Extension_Node, 'proven by schema validation');
                foreach ($extension->fields as $field) {
                    $new_field_map[$field->name->value] = $this->ast_builder->build_field($field, $extension);
                }
            }
        }
        return $new_field_map;
    }
    /** @throws InvariantViolation */
    protected function extend_object_type(Object_Type $type): Object_Type
    {
        /** @var array<ObjectTypeExtensionNode> $extensionASTNodes */
        $extension_ast_nodes = $this->extension_ast_nodes($type);
        return new Object_Type(['name' => $type->name, 'description' => $type->description, 'interfaces' => fn(): array => $this->extend_implemented_interfaces($type), 'fields' => fn(): array => $this->extend_field_map($type), 'isTypeOf' => [$type, 'isTypeOf'], 'resolveField' => $type->resolve_field_fn, 'argsMapper' => $type->args_mapper, 'astNode' => $type->ast_node, 'extensionASTNodes' => $extension_ast_nodes]);
    }
    /** @throws InvariantViolation */
    protected function extend_interface_type(Interface_Type $type): Interface_Type
    {
        /** @var array<InterfaceTypeExtensionNode> $extensionASTNodes */
        $extension_ast_nodes = $this->extension_ast_nodes($type);
        return new Interface_Type(['name' => $type->name, 'description' => $type->description, 'interfaces' => fn(): array => $this->extend_implemented_interfaces($type), 'fields' => fn(): array => $this->extend_field_map($type), 'resolveType' => [$type, 'resolveType'], 'astNode' => $type->ast_node, 'extensionASTNodes' => $extension_ast_nodes]);
    }
    protected function is_specified_scalar_type(Type $type): bool
    {
        return $type instanceof Named_Type && in_array($type->name, [Type::STRING, Type::INT, Type::FLOAT, Type::BOOLEAN, Type::ID], true);
    }
    /**
     * @template T of Type
     *
     * @param T&NamedType $type
     *
     * @throws \ReflectionException
     * @throws InvariantViolation
     *
     * @return T&NamedType
     */
    protected function extend_named_type(Type $type): Type
    {
        if (Introspection::is_introspection_type($type) || $this->is_specified_scalar_type($type)) {
            return $type;
        }
        // @phpstan-ignore-next-line the subtypes line up
        return $this->extend_type_cache[$type->name] ??= $this->extend_named_type_without_cache($type);
    }
    /** @throws \Exception */
    protected function extend_named_type_without_cache(Type $type): Type
    {
        switch (true) {
            case $type instanceof Scalar_Type:
                return $this->extend_scalar_type($type);
            case $type instanceof Object_Type:
                return $this->extend_object_type($type);
            case $type instanceof Interface_Type:
                return $this->extend_interface_type($type);
            case $type instanceof Union_Type:
                return $this->extend_union_type($type);
            case $type instanceof Enum_Type:
                return $this->extend_enum_type($type);
            case $type instanceof Input_Object_Type:
                return $this->extend_input_object_type($type);
            default:
                $unconsidered_type = get_class($type);
                throw new \Exception("Unconsidered type: {$unconsidered_type}.");
        }
    }
    /**
     * @template T of Type
     *
     * @param (T&NamedType)|null $type
     *
     * @throws \ReflectionException
     * @throws InvariantViolation
     *
     * @return (T&NamedType)|null
     */
    protected function extend_maybe_named_type(?Type $type = null): ?Type
    {
        if ($type !== null) {
            return $this->extend_named_type($type);
        }
        return null;
    }
    /**
     * @param array<DirectiveDefinitionNode> $directiveDefinitions
     *
     * @throws \Exception
     * @throws \ReflectionException
     * @throws InvariantViolation
     *
     * @return array<int, Directive>
     */
    protected function get_merged_directives(Schema $schema, array $directive_definitions): array
    {
        $directives = array_map([$this, 'extendDirective'], $schema->get_directives());
        if ($directives === []) {
            throw new Invariant_Violation('Schema must have default directives.');
        }
        foreach ($directive_definitions as $directive) {
            $directives[] = $this->ast_builder->build_directive($directive);
        }
        return $directives;
    }
    protected function extend_directive(Directive $directive): Directive
    {
        return new Directive(['name' => $directive->name, 'description' => $directive->description, 'locations' => $directive->locations, 'args' => $this->extend_args($directive->args), 'isRepeatable' => $directive->is_repeatable, 'astNode' => $directive->ast_node]);
    }
}
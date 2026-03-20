<?php

declare (strict_types=1);
namespace Graph_Ql\Utils;

use Graph_Ql\Error\Error;
use Graph_Ql\Error\Invariant_Violation;
use Graph_Ql\Executor\Values;
use Graph_Ql\Language\AST\Directive_Definition_Node;
use Graph_Ql\Language\AST\Enum_Type_Definition_Node;
use Graph_Ql\Language\AST\Enum_Type_Extension_Node;
use Graph_Ql\Language\AST\Enum_Value_Definition_Node;
use Graph_Ql\Language\AST\Field_Definition_Node;
use Graph_Ql\Language\AST\Input_Object_Type_Definition_Node;
use Graph_Ql\Language\AST\Input_Object_Type_Extension_Node;
use Graph_Ql\Language\AST\Input_Value_Definition_Node;
use Graph_Ql\Language\AST\Interface_Type_Definition_Node;
use Graph_Ql\Language\AST\Interface_Type_Extension_Node;
use Graph_Ql\Language\AST\List_Type_Node;
use Graph_Ql\Language\AST\Named_Type_Node;
use Graph_Ql\Language\AST\Node;
use Graph_Ql\Language\AST\Node_List;
use Graph_Ql\Language\AST\Non_Null_Type_Node;
use Graph_Ql\Language\AST\Object_Type_Definition_Node;
use Graph_Ql\Language\AST\Object_Type_Extension_Node;
use Graph_Ql\Language\AST\Scalar_Type_Definition_Node;
use Graph_Ql\Language\AST\Scalar_Type_Extension_Node;
use Graph_Ql\Language\AST\Type_Definition_Node;
use Graph_Ql\Language\AST\Type_Extension_Node;
use Graph_Ql\Language\AST\Type_Node;
use Graph_Ql\Language\AST\Union_Type_Definition_Node;
use Graph_Ql\Language\AST\Union_Type_Extension_Node;
use Graph_Ql\Type\Definition\Custom_Scalar_Type;
use Graph_Ql\Type\Definition\Directive;
use Graph_Ql\Type\Definition\Enum_Type;
use Graph_Ql\Type\Definition\Field_Definition;
use Graph_Ql\Type\Definition\Input_Object_Field;
use Graph_Ql\Type\Definition\Input_Object_Type;
use Graph_Ql\Type\Definition\Input_Type;
use Graph_Ql\Type\Definition\Interface_Type;
use Graph_Ql\Type\Definition\Named_Type;
use Graph_Ql\Type\Definition\Object_Type;
use Graph_Ql\Type\Definition\Output_Type;
use Graph_Ql\Type\Definition\Type;
use Graph_Ql\Type\Definition\Union_Type;
/**
 * @see FieldDefinition, InputObjectField
 *
 * @phpstan-import-type UnnamedFieldDefinitionConfig from FieldDefinition
 * @phpstan-import-type InputObjectFieldConfig from InputObjectField
 * @phpstan-import-type UnnamedInputObjectFieldConfig from InputObjectField
 *
 * @phpstan-type ResolveType callable(string, Node|null): Type&NamedType
 * @phpstan-type TypeConfigDecorator callable(array<string, mixed>, Node&TypeDefinitionNode, array<string, Node&TypeDefinitionNode>): array<string, mixed>
 * @phpstan-type FieldConfigDecorator callable(UnnamedFieldDefinitionConfig, FieldDefinitionNode, ObjectTypeDefinitionNode|ObjectTypeExtensionNode|InterfaceTypeDefinitionNode|InterfaceTypeExtensionNode): UnnamedFieldDefinitionConfig
 */
class Ast_Definition_Builder
{
    /** @var array<string, Node&TypeDefinitionNode> */
    private array $type_definitions_map;
    /**
     * @var callable
     *
     * @phpstan-var ResolveType
     */
    private $resolve_type;
    /**
     * @var callable|null
     *
     * @phpstan-var TypeConfigDecorator|null
     */
    private $type_config_decorator;
    /**
     * @var callable|null
     *
     * @phpstan-var FieldConfigDecorator|null
     */
    private $field_config_decorator;
    /** @var array<string, Type&NamedType> */
    private array $cache;
    /** @var array<string, array<int, Node&TypeExtensionNode>> */
    private array $type_extensions_map;
    /**
     * @param array<string, Node&TypeDefinitionNode> $typeDefinitionsMap
     * @param array<string, array<int, Node&TypeExtensionNode>> $typeExtensionsMap
     *
     * @phpstan-param ResolveType $resolveType
     * @phpstan-param TypeConfigDecorator|null $typeConfigDecorator
     *
     * @throws InvariantViolation
     */
    public function __construct(array $type_definitions_map, array $type_extensions_map, callable $resolve_type, ?callable $type_config_decorator = null, ?callable $field_config_decorator = null)
    {
        $this->type_definitions_map = $type_definitions_map;
        $this->type_extensions_map = $type_extensions_map;
        $this->resolve_type = $resolve_type;
        $this->type_config_decorator = $type_config_decorator;
        $this->field_config_decorator = $field_config_decorator;
        $this->cache = Type::built_in_types();
    }
    /** @throws \Exception */
    public function build_directive(Directive_Definition_Node $directive_node): Directive
    {
        $locations = [];
        foreach ($directive_node->locations as $location) {
            $locations[] = $location->value;
        }
        return new Directive(['name' => $directive_node->name->value, 'description' => $directive_node->description->value ?? null, 'args' => $this->make_input_values($directive_node->arguments), 'isRepeatable' => $directive_node->repeatable, 'locations' => $locations, 'astNode' => $directive_node]);
    }
    /**
     * @param NodeList<InputValueDefinitionNode> $values
     *
     * @throws \Exception
     *
     * @return array<string, UnnamedInputObjectFieldConfig>
     */
    private function make_input_values(Node_List $values): array
    {
        /** @var array<string, UnnamedInputObjectFieldConfig> $map */
        $map = [];
        foreach ($values as $value) {
            // Note: While this could make assertions to get the correctly typed
            // value, that would throw immediately while type system validation
            // with validateSchema() will produce more actionable results.
            /** @var Type&InputType $type */
            $type = $this->build_wrapped_type($value->type);
            $config = ['name' => $value->name->value, 'type' => $type, 'description' => $value->description->value ?? null, 'deprecationReason' => $this->get_deprecation_reason($value), 'astNode' => $value];
            if ($value->default_value !== null) {
                $config['defaultValue'] = AST::value_from_ast($value->default_value, $type);
            }
            $map[$value->name->value] = $config;
        }
        return $map;
    }
    /**
     * @param array<InputObjectTypeDefinitionNode|InputObjectTypeExtensionNode> $nodes
     *
     * @throws \Exception
     *
     * @return array<string, UnnamedInputObjectFieldConfig>
     */
    private function make_input_fields(array $nodes): array
    {
        /** @var array<int, InputValueDefinitionNode> $fields */
        $fields = [];
        foreach ($nodes as $node) {
            array_push($fields, ...$node->fields);
        }
        return $this->make_input_values(new Node_List($fields));
    }
    /**
     * @param ListTypeNode|NonNullTypeNode|NamedTypeNode $typeNode
     *
     * @throws \Exception
     * @throws \ReflectionException
     * @throws Error
     * @throws InvariantViolation
     */
    private function build_wrapped_type(Type_Node $type_node): Type
    {
        if ($type_node instanceof List_Type_Node) {
            return Type::list_of($this->build_wrapped_type($type_node->type));
        }
        if ($type_node instanceof Non_Null_Type_Node) {
            // @phpstan-ignore-next-line contained type is NullableType
            return Type::non_null($this->build_wrapped_type($type_node->type));
        }
        return $this->build_type($type_node);
    }
    /**
     * @param string|(Node&NamedTypeNode)|(Node&TypeDefinitionNode) $ref
     *
     * @throws \Exception
     * @throws \ReflectionException
     * @throws Error
     * @throws InvariantViolation
     *
     * @return Type&NamedType
     */
    public function build_type($ref): Type
    {
        if ($ref instanceof Type_Definition_Node) {
            return $this->internal_build_type($ref->get_name()->value, $ref);
        }
        if ($ref instanceof Named_Type_Node) {
            return $this->internal_build_type($ref->name->value, $ref);
        }
        return $this->internal_build_type($ref);
    }
    /**
     * Calling this method is an equivalent of `typeMap[typeName]` in `graphql-js`.
     * It is legal to access a type from the map of already-built types that doesn't exist in the map.
     * Since we build types lazily, and we don't have a such map of built types,
     * this method provides a way to build a type that may not exist in the SDL definitions and returns null instead.
     *
     * @throws \Exception
     * @throws \ReflectionException
     * @throws Error
     * @throws InvariantViolation
     *
     * @return (Type&NamedType)|null
     */
    public function maybe_build_type(string $name): ?Type
    {
        return isset($this->type_definitions_map[$name]) ? $this->build_type($name) : null;
    }
    /**
     * @param (Node&NamedTypeNode)|(Node&TypeDefinitionNode)|null $typeNode
     *
     * @throws \Exception
     * @throws \ReflectionException
     * @throws Error
     * @throws InvariantViolation
     *
     * @return Type&NamedType
     */
    private function internal_build_type(string $type_name, ?Node $type_node = null): Type
    {
        if (isset($this->cache[$type_name])) {
            return $this->cache[$type_name];
        }
        if (isset($this->type_definitions_map[$type_name])) {
            $type = $this->make_schema_def($this->type_definitions_map[$type_name]);
            if ($this->type_config_decorator !== null) {
                try {
                    $config = ($this->type_config_decorator)($type->config, $this->type_definitions_map[$type_name], $this->type_definitions_map);
                } catch (\Throwable $e) {
                    $class = static::class;
                    throw new Error("Type config decorator passed to {$class} threw an error when building {$type_name} type: {$e->get_message()}", null, null, [], null, $e);
                }
                // @phpstan-ignore-next-line should not happen, but function types are not enforced by PHP
                if (!is_array($config) || isset($config[0])) {
                    $class = static::class;
                    $not_array = Utils::print_safe($config);
                    throw new Error("Type config decorator passed to {$class} is expected to return an array, but got {$not_array}");
                }
                $type = $this->make_schema_def_from_config($this->type_definitions_map[$type_name], $config);
            }
            return $this->cache[$type_name] = $type;
        }
        return $this->cache[$type_name] = ($this->resolve_type)($type_name, $type_node);
    }
    /**
     * @param TypeDefinitionNode&Node $def
     *
     * @throws \Exception
     * @throws \ReflectionException
     * @throws InvariantViolation
     *
     * @return CustomScalarType|EnumType|InputObjectType|InterfaceType|ObjectType|UnionType
     */
    private function make_schema_def(Node $def): Type
    {
        switch (true) {
            case $def instanceof Object_Type_Definition_Node:
                return $this->make_type_def($def);
            case $def instanceof Interface_Type_Definition_Node:
                return $this->make_interface_def($def);
            case $def instanceof Enum_Type_Definition_Node:
                return $this->make_enum_def($def);
            case $def instanceof Union_Type_Definition_Node:
                return $this->make_union_def($def);
            case $def instanceof Scalar_Type_Definition_Node:
                return $this->make_scalar_def($def);
            default:
                assert($def instanceof Input_Object_Type_Definition_Node, 'all implementations are known');
                return $this->make_input_object_def($def);
        }
    }
    /** @throws InvariantViolation */
    private function make_type_def(Object_Type_Definition_Node $def): Object_Type
    {
        $name = $def->name->value;
        /** @var array<ObjectTypeExtensionNode> $extensionASTNodes (proven by schema validation) */
        $extension_ast_nodes = $this->type_extensions_map[$name] ?? [];
        $all_nodes = [$def, ...$extension_ast_nodes];
        return new Object_Type(['name' => $name, 'description' => $def->description->value ?? null, 'fields' => fn(): array => $this->make_field_def_map($all_nodes), 'interfaces' => fn(): array => $this->make_implemented_interfaces($all_nodes), 'astNode' => $def, 'extensionASTNodes' => $extension_ast_nodes]);
    }
    /**
     * @param array<ObjectTypeDefinitionNode|ObjectTypeExtensionNode|InterfaceTypeDefinitionNode|InterfaceTypeExtensionNode> $nodes
     *
     * @throws \Exception
     *
     * @phpstan-return array<string, UnnamedFieldDefinitionConfig>
     */
    private function make_field_def_map(array $nodes): array
    {
        $map = [];
        foreach ($nodes as $node) {
            foreach ($node->fields as $field) {
                $map[$field->name->value] = $this->build_field($field, $node);
            }
        }
        return $map;
    }
    /**
     * @param ObjectTypeDefinitionNode|ObjectTypeExtensionNode|InterfaceTypeDefinitionNode|InterfaceTypeExtensionNode $node
     *
     * @throws \Exception
     * @throws Error
     *
     * @return UnnamedFieldDefinitionConfig
     */
    public function build_field(Field_Definition_Node $field, object $node): array
    {
        // Note: While this could make assertions to get the correctly typed
        // value, that would throw immediately while type system validation
        // with validateSchema() will produce more actionable results.
        /** @var OutputType&Type $type */
        $type = $this->build_wrapped_type($field->type);
        $config = ['type' => $type, 'description' => $field->description->value ?? null, 'args' => $this->make_input_values($field->arguments), 'deprecationReason' => $this->get_deprecation_reason($field), 'astNode' => $field];
        if ($this->field_config_decorator !== null) {
            return ($this->field_config_decorator)($config, $field, $node);
        }
        return $config;
    }
    /**
     * Given a collection of directives, returns the string value for the
     * deprecation reason.
     *
     * @param EnumValueDefinitionNode|FieldDefinitionNode|InputValueDefinitionNode $node
     *
     * @throws \Exception
     * @throws \ReflectionException
     * @throws InvariantViolation
     */
    private function get_deprecation_reason(Node $node): ?string
    {
        $deprecated = Values::get_directive_values(Directive::deprecated_directive(), $node);
        return $deprecated['reason'] ?? null;
    }
    /**
     * @param array<ObjectTypeDefinitionNode|ObjectTypeExtensionNode|InterfaceTypeDefinitionNode|InterfaceTypeExtensionNode> $nodes
     *
     * @throws \Exception
     * @throws Error
     * @throws InvariantViolation
     *
     * @return array<int, InterfaceType>
     */
    private function make_implemented_interfaces(array $nodes): array
    {
        // Note: While this could make early assertions to get the correctly
        // typed values, that would throw immediately while type system
        // validation with validateSchema() will produce more actionable results.
        $interfaces = [];
        foreach ($nodes as $node) {
            foreach ($node->interfaces as $interface) {
                $interfaces[] = $this->build_type($interface);
            }
        }
        // @phpstan-ignore-next-line generic type will be validated during schema validation
        return $interfaces;
    }
    /** @throws InvariantViolation */
    private function make_interface_def(Interface_Type_Definition_Node $def): Interface_Type
    {
        $name = $def->name->value;
        /** @var array<InterfaceTypeExtensionNode> $extensionASTNodes (proven by schema validation) */
        $extension_ast_nodes = $this->type_extensions_map[$name] ?? [];
        $all_nodes = [$def, ...$extension_ast_nodes];
        return new Interface_Type(['name' => $name, 'description' => $def->description->value ?? null, 'fields' => fn(): array => $this->make_field_def_map($all_nodes), 'interfaces' => fn(): array => $this->make_implemented_interfaces($all_nodes), 'astNode' => $def, 'extensionASTNodes' => $extension_ast_nodes]);
    }
    /**
     * @throws \Exception
     * @throws \ReflectionException
     * @throws InvariantViolation
     */
    private function make_enum_def(Enum_Type_Definition_Node $def): Enum_Type
    {
        $name = $def->name->value;
        /** @var array<EnumTypeExtensionNode> $extensionASTNodes (proven by schema validation) */
        $extension_ast_nodes = $this->type_extensions_map[$name] ?? [];
        $values = [];
        foreach ([$def, ...$extension_ast_nodes] as $node) {
            foreach ($node->values as $value) {
                $values[$value->name->value] = ['description' => $value->description->value ?? null, 'deprecationReason' => $this->get_deprecation_reason($value), 'astNode' => $value];
            }
        }
        return new Enum_Type(['name' => $name, 'description' => $def->description->value ?? null, 'values' => $values, 'astNode' => $def, 'extensionASTNodes' => $extension_ast_nodes]);
    }
    /** @throws InvariantViolation */
    private function make_union_def(Union_Type_Definition_Node $def): Union_Type
    {
        $name = $def->name->value;
        /** @var array<UnionTypeExtensionNode> $extensionASTNodes (proven by schema validation) */
        $extension_ast_nodes = $this->type_extensions_map[$name] ?? [];
        return new Union_Type([
            'name' => $name,
            'description' => $def->description->value ?? null,
            // Note: While this could make assertions to get the correctly typed
            // values below, that would throw immediately while type system
            // validation with validateSchema() will produce more actionable results.
            'types' => function () use ($def, $extension_ast_nodes): array {
                $types = [];
                foreach ([$def, ...$extension_ast_nodes] as $node) {
                    foreach ($node->types as $type) {
                        $types[] = $this->build_type($type);
                    }
                }
                /** @var array<int, ObjectType> $types */
                return $types;
            },
            'astNode' => $def,
            'extensionASTNodes' => $extension_ast_nodes,
        ]);
    }
    /** @throws InvariantViolation */
    private function make_scalar_def(Scalar_Type_Definition_Node $def): Custom_Scalar_Type
    {
        $name = $def->name->value;
        /** @var array<ScalarTypeExtensionNode> $extensionASTNodes (proven by schema validation) */
        $extension_ast_nodes = $this->type_extensions_map[$name] ?? [];
        return new Custom_Scalar_Type(['name' => $name, 'description' => $def->description->value ?? null, 'serialize' => static fn($value) => $value, 'astNode' => $def, 'extensionASTNodes' => $extension_ast_nodes]);
    }
    /**
     * @throws \Exception
     * @throws \ReflectionException
     * @throws InvariantViolation
     */
    private function make_input_object_def(Input_Object_Type_Definition_Node $def): Input_Object_Type
    {
        $name = $def->name->value;
        /** @var array<InputObjectTypeExtensionNode> $extensionASTNodes (proven by schema validation) */
        $extension_ast_nodes = $this->type_extensions_map[$name] ?? [];
        $one_of_directive = Directive::one_of_directive();
        // Check for @oneOf directive in the definition node
        $is_one_of = Values::get_directive_values($one_of_directive, $def) !== null;
        // Check for @oneOf directive in extension nodes
        if (!$is_one_of) {
            foreach ($extension_ast_nodes as $extension_node) {
                if (Values::get_directive_values($one_of_directive, $extension_node) !== null) {
                    $is_one_of = true;
                    break;
                }
            }
        }
        return new Input_Object_Type(['name' => $name, 'description' => $def->description->value ?? null, 'isOneOf' => $is_one_of, 'fields' => fn(): array => $this->make_input_fields([$def, ...$extension_ast_nodes]), 'astNode' => $def, 'extensionASTNodes' => $extension_ast_nodes]);
    }
    /**
     * @param array<string, mixed> $config
     *
     * @throws Error
     *
     * @return CustomScalarType|EnumType|InputObjectType|InterfaceType|ObjectType|UnionType
     */
    private function make_schema_def_from_config(Node $def, array $config): Type
    {
        switch (true) {
            case $def instanceof Object_Type_Definition_Node:
                // @phpstan-ignore-next-line assume the config matches
                return new Object_Type($config);
            case $def instanceof Interface_Type_Definition_Node:
                // @phpstan-ignore-next-line assume the config matches
                return new Interface_Type($config);
            case $def instanceof Enum_Type_Definition_Node:
                // @phpstan-ignore-next-line assume the config matches
                return new Enum_Type($config);
            case $def instanceof Union_Type_Definition_Node:
                // @phpstan-ignore-next-line assume the config matches
                return new Union_Type($config);
            case $def instanceof Scalar_Type_Definition_Node:
                // @phpstan-ignore-next-line assume the config matches
                return new Custom_Scalar_Type($config);
            case $def instanceof Input_Object_Type_Definition_Node:
                // @phpstan-ignore-next-line assume the config matches
                return new Input_Object_Type($config);
            default:
                throw new Error("Type kind of {$def->kind} not supported.");
        }
    }
    /**
     * @throws \Exception
     *
     * @return InputObjectFieldConfig
     */
    public function build_input_field(Input_Value_Definition_Node $value): array
    {
        $type = $this->build_wrapped_type($value->type);
        assert($type instanceof Input_Type, 'proven by schema validation');
        $config = ['name' => $value->name->value, 'type' => $type, 'description' => $value->description->value ?? null, 'astNode' => $value];
        if ($value->default_value !== null) {
            $config['defaultValue'] = AST::value_from_ast($value->default_value, $type);
        }
        return $config;
    }
    /**
     * @throws \Exception
     *
     * @return array<string, mixed>
     */
    public function build_enum_value(Enum_Value_Definition_Node $value): array
    {
        return ['description' => $value->description->value ?? null, 'deprecationReason' => $this->get_deprecation_reason($value), 'astNode' => $value];
    }
}
<?php

declare (strict_types=1);
namespace Graph_Ql\Type;

use Graph_Ql\Error\Error;
use Graph_Ql\Error\Invariant_Violation;
use Graph_Ql\Language\AST\Directive_Definition_Node;
use Graph_Ql\Language\AST\Directive_Node;
use Graph_Ql\Language\AST\Enum_Type_Definition_Node;
use Graph_Ql\Language\AST\Enum_Type_Extension_Node;
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
use Graph_Ql\Language\AST\Schema_Definition_Node;
use Graph_Ql\Language\AST\Schema_Extension_Node;
use Graph_Ql\Language\AST\Type_Node;
use Graph_Ql\Language\AST\Union_Type_Definition_Node;
use Graph_Ql\Language\AST\Union_Type_Extension_Node;
use Graph_Ql\Language\Directive_Location;
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
use Graph_Ql\Type\Validation\Input_Object_Circular_Refs;
use Graph_Ql\Utils\Type_Comparators;
use Graph_Ql\Utils\Utils;
class Schema_Validation_Context
{
    /** @var list<Error> */
    private array $errors = [];
    private Schema $schema;
    private Input_Object_Circular_Refs $input_object_circular_refs;
    public function __construct(Schema $schema)
    {
        $this->schema = $schema;
        $this->input_object_circular_refs = new Input_Object_Circular_Refs($this);
    }
    /** @return list<Error> */
    public function get_errors(): array
    {
        return $this->errors;
    }
    public function validate_root_types(): void
    {
        if ($this->schema->get_query_type() === null) {
            $this->report_error('Query root type must be provided.', $this->schema->ast_node);
        }
        // Triggers a type error if wrong
        $this->schema->get_mutation_type();
        $this->schema->get_subscription_type();
    }
    /** @param array<Node|null>|Node|null $nodes */
    public function report_error(string $message, $nodes = null): void
    {
        $nodes = array_filter(is_array($nodes) ? $nodes : [$nodes]);
        $this->add_error(new Error($message, $nodes));
    }
    private function add_error(Error $error): void
    {
        $this->errors[] = $error;
    }
    /** @throws InvariantViolation */
    public function validate_directives(): void
    {
        $this->validate_directive_definitions();
        // Validate directives that are used on the schema
        $this->validate_directives_at_location($this->get_directives($this->schema), Directive_Location::SCHEMA);
    }
    /** @throws InvariantViolation */
    public function validate_directive_definitions(): void
    {
        $directive_definitions = [];
        $directives = $this->schema->get_directives();
        foreach ($directives as $directive) {
            // Ensure all directives are in fact GraphQL directives.
            // @phpstan-ignore-next-line The generic type says this should not happen, but a user may use it wrong nonetheless
            if (!$directive instanceof Directive) {
                $not_directive = Utils::print_safe($directive);
                // @phpstan-ignore-next-line The generic type says this should not happen, but a user may use it wrong nonetheless
                $nodes = is_object($directive) && property_exists($directive, 'astNode') ? $directive->ast_node : null;
                $this->report_error("Expected directive but got: {$not_directive}.", $nodes);
                continue;
            }
            $existing_definitions = $directive_definitions[$directive->name] ?? [];
            $existing_definitions[] = $directive;
            $directive_definitions[$directive->name] = $existing_definitions;
            // Ensure they are named correctly.
            $this->validate_name($directive);
            // TODO: Ensure proper locations.
            $arg_names = [];
            foreach ($directive->args as $arg) {
                // Ensure they are named correctly.
                $this->validate_name($arg);
                $arg_name = $arg->name;
                if (isset($arg_names[$arg_name])) {
                    $this->report_error("Argument @{$directive->name}({$arg_name}:) can only be defined once.", $this->get_all_directive_arg_nodes($directive, $arg_name));
                    continue;
                }
                $arg_names[$arg_name] = true;
                // Ensure the type is an input type.
                // @phpstan-ignore-next-line necessary until PHP supports union types
                if (!Type::is_input_type($arg->get_type())) {
                    $type = Utils::print_safe($arg->get_type());
                    $this->report_error("The type of @{$directive->name}({$arg_name}:) must be Input Type but got: {$type}.", $this->get_directive_arg_type_node($directive, $arg_name));
                }
            }
        }
        foreach ($directive_definitions as $directive_name => $directive_list) {
            if (count($directive_list) > 1) {
                $nodes = [];
                foreach ($directive_list as $dir) {
                    if (isset($dir->ast_node)) {
                        $nodes[] = $dir->ast_node;
                    }
                }
                $this->report_error("Directive @{$directive_name} defined multiple times.", $nodes);
            }
        }
    }
    /** @param (Type&NamedType)|Directive|FieldDefinition|EnumValueDefinition|InputObjectField|Argument $object */
    private function validate_name(object $object): void
    {
        // Ensure names are valid, however introspection types opt out.
        $error = Utils::is_valid_name_error($object->name, $object->ast_node);
        if ($error === null || $object instanceof Type && Introspection::is_introspection_type($object)) {
            return;
        }
        $this->add_error($error);
    }
    /** @return array<int, InputValueDefinitionNode> */
    private function get_all_directive_arg_nodes(Directive $directive, string $arg_name): array
    {
        $ast_node = $directive->ast_node;
        if ($ast_node === null) {
            return [];
        }
        $matching_subnodes = [];
        foreach ($ast_node->arguments as $sub_node) {
            if ($sub_node->name->value === $arg_name) {
                $matching_subnodes[] = $sub_node;
            }
        }
        return $matching_subnodes;
    }
    /** @return NamedTypeNode|ListTypeNode|NonNullTypeNode|null */
    private function get_directive_arg_type_node(Directive $directive, string $arg_name): ?Type_Node
    {
        $arg_node = $this->get_all_directive_arg_nodes($directive, $arg_name)[0] ?? null;
        return $arg_node === null ? null : $arg_node->type;
    }
    /** @throws InvariantViolation */
    public function validate_types(): void
    {
        $type_map = $this->schema->get_type_map();
        foreach ($type_map as $type) {
            // Ensure all provided types are in fact GraphQL type.
            // @phpstan-ignore-next-line The generic type says this should not happen, but a user may use it wrong nonetheless
            if (!$type instanceof Named_Type) {
                $not_named_type = Utils::print_safe($type);
                // @phpstan-ignore-next-line The generic type says this should not happen, but a user may use it wrong nonetheless
                $node = $type instanceof Type ? $type->ast_node : null;
                $this->report_error("Expected GraphQL named type but got: {$not_named_type}.", $node);
                continue;
            }
            $this->validate_name($type);
            if ($type instanceof Object_Type) {
                $this->validate_fields($type);
                $this->validate_interfaces($type);
                $this->validate_directives_at_location($this->get_directives($type), Directive_Location::OBJECT);
            } elseif ($type instanceof Interface_Type) {
                $this->validate_fields($type);
                $this->validate_interfaces($type);
                $this->validate_directives_at_location($this->get_directives($type), Directive_Location::IFACE);
            } elseif ($type instanceof Union_Type) {
                $this->validate_union_members($type);
                $this->validate_directives_at_location($this->get_directives($type), Directive_Location::UNION);
            } elseif ($type instanceof Enum_Type) {
                $this->validate_enum_values($type);
                $this->validate_directives_at_location($this->get_directives($type), Directive_Location::ENUM);
            } elseif ($type instanceof Input_Object_Type) {
                $this->validate_input_fields($type);
                $this->validate_directives_at_location($this->get_directives($type), Directive_Location::INPUT_OBJECT);
                $this->input_object_circular_refs->validate($type);
            } else {
                assert($type instanceof Scalar_Type, 'only remaining option');
                $this->validate_directives_at_location($this->get_directives($type), Directive_Location::SCALAR);
            }
        }
    }
    /**
     * @param NodeList<DirectiveNode> $directives
     *
     * @throws InvariantViolation
     */
    private function validate_directives_at_location(Node_List $directives, string $location): void
    {
        /** @var array<string, array<int, DirectiveNode>> $potentiallyDuplicateDirectives */
        $potentially_duplicate_directives = [];
        $schema = $this->schema;
        foreach ($directives as $directive_node) {
            $directive_name = $directive_node->name->value;
            // Ensure directive used is also defined
            $schema_directive = $schema->get_directive($directive_name);
            if ($schema_directive === null) {
                $this->report_error("No directive @{$directive_name} defined.", $directive_node);
                continue;
            }
            if (!in_array($location, $schema_directive->locations, true)) {
                $this->report_error("Directive @{$directive_name} not allowed at {$location} location.", array_filter([$directive_node, $schema_directive->ast_node]));
            }
            if (!$schema_directive->is_repeatable) {
                $potentially_duplicate_directives[$directive_name][] = $directive_node;
            }
        }
        foreach ($potentially_duplicate_directives as $directive_name => $directive_list) {
            if (count($directive_list) > 1) {
                $this->report_error("Non-repeatable directive @{$directive_name} used more than once at the same location.", $directive_list);
            }
        }
    }
    /**
     * @param ObjectType|InterfaceType $type
     *
     * @throws InvariantViolation
     */
    private function validate_fields(Type $type): void
    {
        $field_map = $type->get_fields();
        if ($field_map === []) {
            $this->report_error("Type {$type->name} must define one or more fields.", $this->get_all_nodes($type));
        }
        foreach ($field_map as $field_name => $field) {
            $this->validate_name($field);
            $field_nodes = $this->get_all_field_nodes($type, $field_name);
            if (count($field_nodes) > 1) {
                $this->report_error("Field {$type->name}.{$field_name} can only be defined once.", $field_nodes);
                continue;
            }
            $field_type = $field->get_type();
            // @phpstan-ignore-next-line not statically provable until we can use union types
            if (!Type::is_output_type($field_type)) {
                $safe_field_type = Utils::print_safe($field_type);
                $this->report_error("The type of {$type->name}.{$field_name} must be Output Type but got: {$safe_field_type}.", $this->get_field_type_node($type, $field_name));
            }
            $this->validate_type_is_singleton($field_type, "{$type->name}.{$field_name}");
            $arg_names = [];
            foreach ($field->args as $arg) {
                $arg_name = $arg->name;
                $arg_path = "{$type->name}.{$field_name}({$arg_name}:)";
                $this->validate_name($arg);
                if (isset($arg_names[$arg_name])) {
                    $this->report_error("Field argument {$arg_path} can only be defined once.", $this->get_all_field_arg_nodes($type, $field_name, $arg_name));
                }
                $arg_names[$arg_name] = true;
                $arg_type = $arg->get_type();
                // @phpstan-ignore-next-line the type of $arg->getType() says it is an input type, but it might not always be true
                if (!Type::is_input_type($arg_type)) {
                    $safe_type = Utils::print_safe($arg_type);
                    $this->report_error("The type of {$arg_path} must be Input Type but got: {$safe_type}.", $this->get_field_arg_type_node($type, $field_name, $arg_name));
                }
                $this->validate_type_is_singleton($arg_type, $arg_path);
                if (isset($arg->ast_node->directives)) {
                    $this->validate_directives_at_location($arg->ast_node->directives, Directive_Location::ARGUMENT_DEFINITION);
                }
            }
            if (isset($field->ast_node->directives)) {
                $this->validate_directives_at_location($field->ast_node->directives, Directive_Location::FIELD_DEFINITION);
            }
        }
    }
    /**
     * @param Schema|ObjectType|InterfaceType|UnionType|EnumType|InputObjectType|Directive $obj
     *
     * @return list<SchemaDefinitionNode|SchemaExtensionNode>|list<ObjectTypeDefinitionNode|ObjectTypeExtensionNode>|list<InterfaceTypeDefinitionNode|InterfaceTypeExtensionNode>|list<UnionTypeDefinitionNode|UnionTypeExtensionNode>|list< EnumTypeDefinitionNode|EnumTypeExtensionNode>|list<InputObjectTypeDefinitionNode|InputObjectTypeExtensionNode>|list<DirectiveDefinitionNode>
     */
    private function get_all_nodes(object $obj): array
    {
        $ast_node = $obj->ast_node;
        if ($obj instanceof Schema) {
            $extension_nodes = $obj->extension_ast_nodes;
        } elseif ($obj instanceof Directive) {
            $extension_nodes = [];
        } else {
            $extension_nodes = $obj->extension_ast_nodes;
        }
        $all_nodes = $ast_node === null ? [] : [$ast_node];
        foreach ($extension_nodes as $extension_node) {
            $all_nodes[] = $extension_node;
        }
        return $all_nodes;
    }
    /**
     * @param ObjectType|InterfaceType $type
     *
     * @return list<FieldDefinitionNode>
     */
    private function get_all_field_nodes(Type $type, string $field_name): array
    {
        $all_nodes = array_filter([$type->ast_node, ...$type->extension_ast_nodes]);
        $matching_field_nodes = [];
        foreach ($all_nodes as $node) {
            foreach ($node->fields as $field) {
                if ($field->name->value === $field_name) {
                    $matching_field_nodes[] = $field;
                }
            }
        }
        return $matching_field_nodes;
    }
    /**
     * @param ObjectType|InterfaceType $type
     *
     * @return NamedTypeNode|ListTypeNode|NonNullTypeNode|null
     */
    private function get_field_type_node(Type $type, string $field_name): ?Type_Node
    {
        $field_node = $this->get_field_node($type, $field_name);
        return $field_node === null ? null : $field_node->type;
    }
    /** @param ObjectType|InterfaceType $type */
    private function get_field_node(Type $type, string $field_name): ?Field_Definition_Node
    {
        $nodes = $this->get_all_field_nodes($type, $field_name);
        return $nodes[0] ?? null;
    }
    /**
     * @param ObjectType|InterfaceType $type
     *
     * @return array<int, InputValueDefinitionNode>
     */
    private function get_all_field_arg_nodes(Type $type, string $field_name, string $arg_name): array
    {
        $arg_nodes = [];
        $field_node = $this->get_field_node($type, $field_name);
        if ($field_node !== null) {
            foreach ($field_node->arguments as $node) {
                if ($node->name->value === $arg_name) {
                    $arg_nodes[] = $node;
                }
            }
        }
        return $arg_nodes;
    }
    /**
     * @param ObjectType|InterfaceType $type
     *
     * @return NamedTypeNode|ListTypeNode|NonNullTypeNode|null
     */
    private function get_field_arg_type_node(Type $type, string $field_name, string $arg_name): ?Type_Node
    {
        $field_arg_node = $this->get_field_arg_node($type, $field_name, $arg_name);
        return $field_arg_node === null ? null : $field_arg_node->type;
    }
    /** @param ObjectType|InterfaceType $type */
    private function get_field_arg_node(Type $type, string $field_name, string $arg_name): ?Input_Value_Definition_Node
    {
        $nodes = $this->get_all_field_arg_nodes($type, $field_name, $arg_name);
        return $nodes[0] ?? null;
    }
    /**
     * @param ObjectType|InterfaceType $type
     *
     * @throws InvariantViolation
     */
    private function validate_interfaces(Implementing_Type $type): void
    {
        $iface_type_names = [];
        foreach ($type->get_interfaces() as $interface) {
            // @phpstan-ignore-next-line The generic type says this should not happen, but a user may use it wrong nonetheless
            if (!$interface instanceof Interface_Type) {
                $not_interface = Utils::print_safe($interface);
                $this->report_error("Type {$type->name} must only implement Interface types, it cannot implement {$not_interface}.", $this->get_implements_interface_node($type, $interface));
                continue;
            }
            if ($type === $interface) {
                $this->report_error("Type {$type->name} cannot implement itself because it would create a circular reference.", $this->get_implements_interface_node($type, $interface));
                continue;
            }
            if (isset($iface_type_names[$interface->name])) {
                $this->report_error("Type {$type->name} can only implement {$interface->name} once.", $this->get_all_implements_interface_nodes($type, $interface));
                continue;
            }
            $iface_type_names[$interface->name] = true;
            $this->validate_type_implements_ancestors($type, $interface);
            $this->validate_type_implements_interface($type, $interface);
        }
    }
    /**
     * @param Schema|(Type&NamedType) $object
     *
     * @return NodeList<DirectiveNode>
     */
    private function get_directives(object $object): Node_List
    {
        $directives = [];
        /**
         * Excluding directiveNode, since $object is not Directive.
         *
         * @var SchemaDefinitionNode|SchemaExtensionNode|ObjectTypeDefinitionNode|ObjectTypeExtensionNode|InterfaceTypeDefinitionNode|InterfaceTypeExtensionNode|UnionTypeDefinitionNode|UnionTypeExtensionNode|EnumTypeDefinitionNode|EnumTypeExtensionNode|InputObjectTypeDefinitionNode|InputObjectTypeExtensionNode $node
         */
        // @phpstan-ignore-next-line union types are not pervasive
        foreach ($this->get_all_nodes($object) as $node) {
            foreach ($node->directives as $directive) {
                $directives[] = $directive;
            }
        }
        return new Node_List($directives);
    }
    /**
     * @param ObjectType|InterfaceType $type
     * @param Type&NamedType $shouldBeInterface
     */
    private function get_implements_interface_node(Implementing_Type $type, Named_Type $should_be_interface): ?Named_Type_Node
    {
        $nodes = $this->get_all_implements_interface_nodes($type, $should_be_interface);
        return $nodes[0] ?? null;
    }
    /**
     * @param ObjectType|InterfaceType $type
     * @param Type&NamedType $shouldBeInterface
     *
     * @return list<NamedTypeNode>
     */
    private function get_all_implements_interface_nodes(Implementing_Type $type, Named_Type $should_be_interface): array
    {
        $all_nodes = array_filter([$type->ast_node, ...$type->extension_ast_nodes]);
        $should_be_interface_name = $should_be_interface->name;
        $matching_interface_nodes = [];
        foreach ($all_nodes as $node) {
            foreach ($node->interfaces as $interface) {
                if ($interface->name->value === $should_be_interface_name) {
                    $matching_interface_nodes[] = $interface;
                }
            }
        }
        return $matching_interface_nodes;
    }
    /**
     * @param ObjectType|InterfaceType $type
     *
     * @throws InvariantViolation
     */
    private function validate_type_implements_interface(Implementing_Type $type, Interface_Type $iface): void
    {
        $type_field_map = $type->get_fields();
        $iface_field_map = $iface->get_fields();
        foreach ($iface_field_map as $field_name => $iface_field) {
            $type_field = $type_field_map[$field_name] ?? null;
            if ($type_field === null) {
                $this->report_error("Interface field {$iface->name}.{$field_name} expected but {$type->name} does not provide it.", array_merge([$this->get_field_node($iface, $field_name)], $this->get_all_nodes($type)));
                continue;
            }
            $type_field_type = $type_field->get_type();
            $iface_field_type = $iface_field->get_type();
            if (!Type_Comparators::is_type_sub_type_of($this->schema, $type_field_type, $iface_field_type)) {
                $this->report_error("Interface field {$iface->name}.{$field_name} expects type {$iface_field_type} but {$type->name}.{$field_name} is type {$type_field_type}.", [$this->get_field_type_node($iface, $field_name), $this->get_field_type_node($type, $field_name)]);
            }
            foreach ($iface_field->args as $iface_arg) {
                $arg_name = $iface_arg->name;
                $type_arg = $type_field->get_arg($arg_name);
                if ($type_arg === null) {
                    $this->report_error("Interface field argument {$iface->name}.{$field_name}({$arg_name}:) expected but {$type->name}.{$field_name} does not provide it.", [$this->get_field_arg_node($iface, $field_name, $arg_name), $this->get_field_node($type, $field_name)]);
                    continue;
                }
                $iface_arg_type = $iface_arg->get_type();
                $type_arg_type = $type_arg->get_type();
                if (!Type_Comparators::is_equal_type($iface_arg_type, $type_arg_type)) {
                    $this->report_error("Interface field argument {$iface->name}.{$field_name}({$arg_name}:) expects type {$iface_arg_type} but {$type->name}.{$field_name}({$arg_name}:) is type {$type_arg_type}.", [$this->get_field_arg_type_node($iface, $field_name, $arg_name), $this->get_field_arg_type_node($type, $field_name, $arg_name)]);
                }
                // TODO: validate default values?
            }
            foreach ($type_field->args as $type_arg) {
                $arg_name = $type_arg->name;
                $iface_arg = $iface_field->get_arg($arg_name);
                if ($type_arg->is_required() && $iface_arg === null) {
                    $this->report_error("Object field {$type->name}.{$field_name} includes required argument {$arg_name} that is missing from the Interface field {$iface->name}.{$field_name}.", [$this->get_field_arg_node($type, $field_name, $arg_name), $this->get_field_node($iface, $field_name)]);
                }
            }
        }
    }
    /** @param ObjectType|InterfaceType $type */
    private function validate_type_implements_ancestors(Implementing_Type $type, Interface_Type $iface): void
    {
        $type_interfaces = $type->get_interfaces();
        foreach ($iface->get_interfaces() as $transitive) {
            if (!in_array($transitive, $type_interfaces, true)) {
                $this->report_error($transitive === $type ? "Type {$type->name} cannot implement {$iface->name} because it would create a circular reference." : "Type {$type->name} must implement {$transitive->name} because it is implemented by {$iface->name}.", array_merge($this->get_all_implements_interface_nodes($iface, $transitive), $this->get_all_implements_interface_nodes($type, $iface)));
            }
        }
    }
    /** @throws InvariantViolation */
    private function validate_union_members(Union_Type $union): void
    {
        $member_types = $union->get_types();
        if ($member_types === []) {
            $this->report_error("Union type {$union->name} must define one or more member types.", $this->get_all_nodes($union));
        }
        $included_type_names = [];
        foreach ($member_types as $member_type) {
            // @phpstan-ignore-next-line The generic type says this should not happen, but a user may use it wrong nonetheless
            if (!$member_type instanceof Object_Type) {
                $not_object_type = Utils::print_safe($member_type);
                $this->report_error("Union type {$union->name} can only include Object types, it cannot include {$not_object_type}.", $this->get_union_member_type_nodes($union, $not_object_type));
                continue;
            }
            if (isset($included_type_names[$member_type->name])) {
                $this->report_error("Union type {$union->name} can only include type {$member_type->name} once.", $this->get_union_member_type_nodes($union, $member_type->name));
                continue;
            }
            $included_type_names[$member_type->name] = true;
        }
    }
    /** @return list<NamedTypeNode> */
    private function get_union_member_type_nodes(Union_Type $union, string $type_name): array
    {
        $all_nodes = array_filter([$union->ast_node, ...$union->extension_ast_nodes]);
        $types = [];
        foreach ($all_nodes as $node) {
            foreach ($node->types as $type) {
                if ($type->name->value === $type_name) {
                    $types[] = $type;
                }
            }
        }
        return $types;
    }
    /** @throws InvariantViolation */
    private function validate_enum_values(Enum_Type $enum_type): void
    {
        $enum_values = $enum_type->get_values();
        if ($enum_values === []) {
            $this->report_error("Enum type {$enum_type->name} must define one or more values.", $this->get_all_nodes($enum_type));
        }
        foreach ($enum_values as $enum_value) {
            $value_name = $enum_value->name;
            // Ensure valid name.
            $this->validate_name($enum_value);
            if (in_array($value_name, ['true', 'false', 'null'], true)) {
                $this->report_error("Enum type {$enum_type->name} cannot include value: {$value_name}.", $enum_value->ast_node);
            }
            // Ensure valid directives
            if (isset($enum_value->ast_node, $enum_value->ast_node->directives)) {
                $this->validate_directives_at_location($enum_value->ast_node->directives, Directive_Location::ENUM_VALUE);
            }
        }
    }
    /** @throws InvariantViolation */
    private function validate_input_fields(Input_Object_Type $input_obj): void
    {
        $field_map = $input_obj->get_fields();
        if ($field_map === []) {
            $this->report_error("Input Object type {$input_obj->name} must define one or more fields.", $this->get_all_nodes($input_obj));
        }
        // Ensure the arguments are valid
        foreach ($field_map as $field_name => $field) {
            // Ensure they are named correctly.
            $this->validate_name($field);
            // TODO: Ensure they are unique per field.
            // Ensure the type is an input type.
            $type = $field->get_type();
            // @phpstan-ignore-next-line The generic type says this should not happen, but a user may use it wrong nonetheless
            if (!Type::is_input_type($type)) {
                $not_input_type = Utils::print_safe($type);
                $this->report_error("The type of {$input_obj->name}.{$field_name} must be Input Type but got: {$not_input_type}.", $field->ast_node->type ?? null);
            }
            // Ensure valid directives
            if (isset($field->ast_node, $field->ast_node->directives)) {
                $this->validate_directives_at_location($field->ast_node->directives, Directive_Location::INPUT_FIELD_DEFINITION);
            }
        }
    }
    /** @throws InvariantViolation */
    private function validate_type_is_singleton(Type $type, string $path): void
    {
        $schema_config = $this->schema->get_config();
        if (!isset($schema_config->type_loader)) {
            return;
        }
        $named_type = Type::get_named_type($type);
        if ($named_type->is_built_in_type()) {
            return;
        }
        $name = $named_type->name;
        if ($named_type !== ($schema_config->type_loader)($name)) {
            throw new Invariant_Violation(static::duplicate_type($this->schema, $path, $name));
        }
    }
    public static function duplicate_type(Schema $schema, string $path, string $name): string
    {
        $hint = isset($schema->get_config()->type_loader) ? 'Ensure the type loader returns the same instance. ' : '';
        return "Found duplicate type in schema at {$path}: {$name}. {$hint}See https://webonyx.github.io/graphql-php/type-definitions/#type-registry.";
    }
}
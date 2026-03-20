<?php

declare (strict_types=1);
namespace Graph_Ql\Type\Definition;

use Graph_Ql\Error\Error;
use Graph_Ql\Error\Invariant_Violation;
use Graph_Ql\Language\AST\Interface_Type_Definition_Node;
use Graph_Ql\Language\AST\Interface_Type_Extension_Node;
use Graph_Ql\Utils\Utils;
/**
 * @phpstan-import-type ResolveType from AbstractType
 * @phpstan-import-type ResolveValue from AbstractType
 * @phpstan-import-type FieldsConfig from FieldDefinition
 *
 * @phpstan-type InterfaceTypeReference InterfaceType|callable(): InterfaceType
 * @phpstan-type InterfaceConfig array{
 *   name?: string|null,
 *   description?: string|null,
 *   fields: FieldsConfig,
 *   interfaces?: iterable<InterfaceTypeReference>|callable(): iterable<InterfaceTypeReference>,
 *   resolveType?: ResolveType|null,
 *   resolveValue?: ResolveValue|null,
 *   astNode?: InterfaceTypeDefinitionNode|null,
 *   extensionASTNodes?: array<InterfaceTypeExtensionNode>|null
 * }
 */
class Interface_Type extends Type implements Abstract_Type, Output_Type, Composite_Type, Nullable_Type, Has_Fields_Type, Named_Type, Implementing_Type
{
    use Has_Fields_Type_Implementation;
    use Named_Type_Implementation;
    use Implementing_Type_Implementation;
    public ?Interface_Type_Definition_Node $ast_node;
    /** @var array<InterfaceTypeExtensionNode> */
    public array $extension_ast_nodes;
    /** @phpstan-var InterfaceConfig */
    public array $config;
    /**
     * @phpstan-param InterfaceConfig $config
     *
     * @throws InvariantViolation
     */
    public function __construct(array $config)
    {
        $this->name = $config['name'] ?? $this->infer_name();
        $this->description = $config['description'] ?? null;
        $this->ast_node = $config['astNode'] ?? null;
        $this->extension_ast_nodes = $config['extensionASTNodes'] ?? [];
        $this->config = $config;
    }
    /**
     * @param mixed $type
     *
     * @throws InvariantViolation
     */
    public static function assert_interface_type($type): self
    {
        if (!$type instanceof self) {
            $not_interface_type = Utils::print_safe($type);
            throw new Invariant_Violation("Expected {$not_interface_type} to be a GraphQL Interface type.");
        }
        return $type;
    }
    public function resolve_value($object_value, $context, Resolve_Info $info)
    {
        if (isset($this->config['resolveValue'])) {
            return $this->config['resolveValue']($object_value, $context, $info);
        }
        return $object_value;
    }
    public function resolve_type($object_value, $context, Resolve_Info $info)
    {
        if (isset($this->config['resolveType'])) {
            return $this->config['resolveType']($object_value, $context, $info);
        }
        return null;
    }
    /**
     * @throws Error
     * @throws InvariantViolation
     */
    public function assert_valid(): void
    {
        Utils::assert_valid_name($this->name);
        $resolve_type = $this->config['resolveType'] ?? null;
        // @phpstan-ignore-next-line unnecessary according to types, but can happen during runtime
        if ($resolve_type !== null && !is_callable($resolve_type)) {
            $not_callable = Utils::print_safe($resolve_type);
            throw new Invariant_Violation("{$this->name} must provide \"resolveType\" as null or a callable, but got: {$not_callable}.");
        }
        $this->assert_valid_interfaces();
    }
    public function ast_node(): ?Interface_Type_Definition_Node
    {
        return $this->ast_node;
    }
    /** @return array<InterfaceTypeExtensionNode> */
    public function extension_ast_nodes(): array
    {
        return $this->extension_ast_nodes;
    }
}
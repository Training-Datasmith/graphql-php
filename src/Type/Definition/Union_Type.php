<?php

declare (strict_types=1);
namespace Graph_Ql\Type\Definition;

use Graph_Ql\Error\Invariant_Violation;
use Graph_Ql\Language\AST\Union_Type_Definition_Node;
use Graph_Ql\Language\AST\Union_Type_Extension_Node;
use Graph_Ql\Type\Schema;
use Graph_Ql\Utils\Utils;
/**
 * @phpstan-import-type ResolveType from AbstractType
 * @phpstan-import-type ResolveValue from AbstractType
 *
 * @phpstan-type ObjectTypeReference ObjectType|callable(): ObjectType
 * @phpstan-type UnionConfig array{
 *   name?: string|null,
 *   description?: string|null,
 *   types: iterable<ObjectTypeReference>|callable(): iterable<ObjectTypeReference>,
 *   resolveType?: ResolveType|null,
 *   resolveValue?: ResolveValue|null,
 *   astNode?: UnionTypeDefinitionNode|null,
 *   extensionASTNodes?: array<UnionTypeExtensionNode>|null
 * }
 */
class Union_Type extends Type implements Abstract_Type, Output_Type, Composite_Type, Nullable_Type, Named_Type
{
    use Named_Type_Implementation;
    public ?Union_Type_Definition_Node $ast_node;
    /** @var array<UnionTypeExtensionNode> */
    public array $extension_ast_nodes;
    /** @phpstan-var UnionConfig */
    public array $config;
    /**
     * Lazily initialized.
     *
     * @var array<int, ObjectType>
     */
    private array $types;
    /**
     * Lazily initialized.
     *
     * @var array<string, bool>
     */
    private array $possible_type_names;
    /**
     * @phpstan-param UnionConfig $config
     *
     * @throws InvariantViolation
     */
    public function __construct(array $config)
    {
        $this->name = $config['name'] ?? $this->infer_name();
        $this->description = $config['description'] ?? $this->description ?? null;
        $this->ast_node = $config['astNode'] ?? null;
        $this->extension_ast_nodes = $config['extensionASTNodes'] ?? [];
        $this->config = $config;
    }
    /** @throws InvariantViolation */
    public function is_possible_type(Type $type): bool
    {
        if (!$type instanceof Object_Type) {
            return false;
        }
        if (!isset($this->possible_type_names)) {
            $this->possible_type_names = [];
            foreach ($this->get_types() as $possible_type) {
                $this->possible_type_names[$possible_type->name] = true;
            }
        }
        return isset($this->possible_type_names[$type->name]);
    }
    /**
     * @throws InvariantViolation
     *
     * @return array<int, ObjectType>
     */
    public function get_types(): array
    {
        if (!isset($this->types)) {
            $this->types = [];
            $types = $this->config['types'] ?? null;
            // @phpstan-ignore nullCoalesce.initializedProperty (unnecessary according to types, but can happen during runtime)
            if (is_callable($types)) {
                $types = $types();
            }
            if (!is_iterable($types)) {
                throw new Invariant_Violation("Must provide iterable of types or a callable which returns such an iterable for Union {$this->name}.");
            }
            foreach ($types as $type) {
                $this->types[] = Schema::resolve_type($type);
                // @phpstan-ignore argument.templateType
            }
        }
        return $this->types;
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
    public function assert_valid(): void
    {
        Utils::assert_valid_name($this->name);
        $resolve_type = $this->config['resolveType'] ?? null;
        // @phpstan-ignore-next-line unnecessary according to types, but can happen during runtime
        if (isset($resolve_type) && !is_callable($resolve_type)) {
            $not_callable = Utils::print_safe($resolve_type);
            throw new Invariant_Violation("{$this->name} must provide \"resolveType\" as null or a callable, but got: {$not_callable}.");
        }
    }
    public function ast_node(): ?Union_Type_Definition_Node
    {
        return $this->ast_node;
    }
    /** @return array<UnionTypeExtensionNode> */
    public function extension_ast_nodes(): array
    {
        return $this->extension_ast_nodes;
    }
}
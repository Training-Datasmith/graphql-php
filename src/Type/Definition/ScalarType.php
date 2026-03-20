<?php

declare (strict_types=1);
namespace Graph_Ql\Type\Definition;

use Graph_Ql\Error\Invariant_Violation;
use Graph_Ql\Language\AST\Scalar_Type_Definition_Node;
use Graph_Ql\Language\AST\Scalar_Type_Extension_Node;
use Graph_Ql\Utils\Utils;
/**
 * Scalar Type Definition.
 *
 * The leaf values of any request and input values to arguments are
 * Scalars (or Enums) and are defined with a name and a series of coercion
 * functions used to ensure validity.
 *
 * Example:
 *
 * class OddType extends ScalarType
 * {
 *     public $name = 'Odd',
 *     public function serialize($value)
 *     {
 *         return $value % 2 === 1 ? $value : null;
 *     }
 * }
 *
 * @phpstan-type ScalarConfig array{
 *   name?: string|null,
 *   description?: string|null,
 *   astNode?: ScalarTypeDefinitionNode|null,
 *   extensionASTNodes?: array<ScalarTypeExtensionNode>|null
 * }
 */
abstract class Scalar_Type extends Type implements Output_Type, Input_Type, Leaf_Type, Nullable_Type, Named_Type
{
    use Named_Type_Implementation;
    public ?Scalar_Type_Definition_Node $ast_node;
    /** @var array<ScalarTypeExtensionNode> */
    public array $extension_ast_nodes;
    /** @phpstan-var ScalarConfig */
    public array $config;
    /**
     * @phpstan-param ScalarConfig $config
     *
     * @throws InvariantViolation
     */
    public function __construct(array $config = [])
    {
        $this->name = $config['name'] ?? $this->infer_name();
        $this->description = $config['description'] ?? $this->description ?? null;
        $this->ast_node = $config['astNode'] ?? null;
        $this->extension_ast_nodes = $config['extensionASTNodes'] ?? [];
        $this->config = $config;
    }
    public function assert_valid(): void
    {
        Utils::assert_valid_name($this->name);
    }
    public function ast_node(): ?Scalar_Type_Definition_Node
    {
        return $this->ast_node;
    }
    /** @return array<ScalarTypeExtensionNode> */
    public function extension_ast_nodes(): array
    {
        return $this->extension_ast_nodes;
    }
}
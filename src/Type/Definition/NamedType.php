<?php

declare (strict_types=1);
namespace Graph_Ql\Type\Definition;

use Graph_Ql\Error\Error;
use Graph_Ql\Language\AST\Node;
use Graph_Ql\Language\AST\Type_Definition_Node;
use Graph_Ql\Language\AST\Type_Extension_Node;
/**
 * export type NamedType =
 * | ScalarType
 * | ObjectType
 * | InterfaceType
 * | UnionType
 * | EnumType
 * | InputObjectType;.
 *
 * @property string $name
 * @property string|null $description
 * @property (Node&TypeDefinitionNode)|null $astNode
 * @property array<Node&TypeExtensionNode> $extensionASTNodes
 */
interface Named_Type
{
    /** @throws Error */
    public function assert_valid(): void;
    /** Is this type a built-in type? */
    public function is_built_in_type(): bool;
    public function name(): string;
    public function description(): ?string;
    /** @return (Node&TypeDefinitionNode)|null */
    public function ast_node(): ?Node;
    /** @return array<Node&TypeExtensionNode> */
    public function extension_ast_nodes(): array;
}
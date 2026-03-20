<?php

declare (strict_types=1);
namespace Graph_Ql\Language\AST;

/**
 * export type TypeDefinitionNode = ScalarTypeDefinitionNode
 * | ObjectTypeDefinitionNode
 * | InterfaceTypeDefinitionNode
 * | UnionTypeDefinitionNode
 * | EnumTypeDefinitionNode
 * | InputObjectTypeDefinitionNode.
 */
interface Type_Definition_Node extends Type_System_Definition_Node
{
    public function get_name(): Name_Node;
}
<?php

declare (strict_types=1);
namespace Graph_Ql\Language\AST;

/**
 * export type TypeExtensionNode =
 * | ScalarTypeExtensionNode
 * | ObjectTypeExtensionNode
 * | InterfaceTypeExtensionNode
 * | UnionTypeExtensionNode
 * | EnumTypeExtensionNode
 * | InputObjectTypeExtensionNode;.
 */
interface Type_Extension_Node extends Type_System_Extension_Node
{
    public function get_name(): Name_Node;
}
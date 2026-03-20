<?php

declare (strict_types=1);
namespace Graph_Ql\Language\AST;

class List_Type_Node extends Node implements Type_Node
{
    public string $kind = Node_Kind::LIST_TYPE;
    /** @var NamedTypeNode|ListTypeNode|NonNullTypeNode */
    public Type_Node $type;
}
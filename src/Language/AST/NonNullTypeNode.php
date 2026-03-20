<?php

declare (strict_types=1);
namespace Graph_Ql\Language\AST;

class Non_Null_Type_Node extends Node implements Type_Node
{
    public string $kind = Node_Kind::NON_NULL_TYPE;
    /** @var NamedTypeNode|ListTypeNode */
    public Type_Node $type;
}
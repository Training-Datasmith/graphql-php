<?php

declare (strict_types=1);
namespace Graph_Ql\Language\AST;

class Named_Type_Node extends Node implements Type_Node
{
    public string $kind = Node_Kind::NAMED_TYPE;
    public Name_Node $name;
}
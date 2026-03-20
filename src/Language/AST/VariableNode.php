<?php

declare (strict_types=1);
namespace Graph_Ql\Language\AST;

class Variable_Node extends Node implements Value_Node
{
    public string $kind = Node_Kind::VARIABLE;
    public Name_Node $name;
}
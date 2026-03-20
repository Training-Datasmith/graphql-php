<?php

declare (strict_types=1);
namespace Graph_Ql\Language\AST;

class Name_Node extends Node implements Type_Node
{
    public string $kind = Node_Kind::NAME;
    public string $value;
}
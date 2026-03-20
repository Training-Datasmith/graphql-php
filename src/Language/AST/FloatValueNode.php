<?php

declare (strict_types=1);
namespace Graph_Ql\Language\AST;

class Float_Value_Node extends Node implements Value_Node
{
    public string $kind = Node_Kind::FLOAT;
    public string $value;
}
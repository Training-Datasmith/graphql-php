<?php

declare (strict_types=1);
namespace Graph_Ql\Language\AST;

class Int_Value_Node extends Node implements Value_Node
{
    public string $kind = Node_Kind::INT;
    public string $value;
}
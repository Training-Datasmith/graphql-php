<?php

declare (strict_types=1);
namespace Graph_Ql\Language\AST;

class String_Value_Node extends Node implements Value_Node
{
    public string $kind = Node_Kind::STRING;
    public string $value;
    public bool $block = false;
}
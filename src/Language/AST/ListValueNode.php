<?php

declare (strict_types=1);
namespace Graph_Ql\Language\AST;

class List_Value_Node extends Node implements Value_Node
{
    public string $kind = Node_Kind::LST;
    /** @var NodeList<ValueNode&Node> */
    public Node_List $values;
}
<?php

declare (strict_types=1);
namespace Graph_Ql\Language\AST;

class Object_Value_Node extends Node implements Value_Node
{
    public string $kind = Node_Kind::OBJECT;
    /** @var NodeList<ObjectFieldNode> */
    public Node_List $fields;
}
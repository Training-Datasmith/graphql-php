<?php

declare (strict_types=1);
namespace Graph_Ql\Language\AST;

class Enum_Value_Definition_Node extends Node
{
    public string $kind = Node_Kind::ENUM_VALUE_DEFINITION;
    public Name_Node $name;
    /** @var NodeList<DirectiveNode> */
    public Node_List $directives;
    public ?String_Value_Node $description = null;
}
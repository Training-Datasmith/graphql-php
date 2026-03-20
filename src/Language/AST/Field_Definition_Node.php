<?php

declare (strict_types=1);
namespace Graph_Ql\Language\AST;

class Field_Definition_Node extends Node
{
    public string $kind = Node_Kind::FIELD_DEFINITION;
    public Name_Node $name;
    /** @var NodeList<InputValueDefinitionNode> */
    public Node_List $arguments;
    /** @var NamedTypeNode|ListTypeNode|NonNullTypeNode */
    public Type_Node $type;
    /** @var NodeList<DirectiveNode> */
    public Node_List $directives;
    public ?String_Value_Node $description = null;
}
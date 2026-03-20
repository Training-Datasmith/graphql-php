<?php

declare (strict_types=1);
namespace Graph_Ql\Language\AST;

class Input_Value_Definition_Node extends Node
{
    public string $kind = Node_Kind::INPUT_VALUE_DEFINITION;
    public Name_Node $name;
    /** @var NamedTypeNode|ListTypeNode|NonNullTypeNode */
    public Type_Node $type;
    /** @var VariableNode|NullValueNode|IntValueNode|FloatValueNode|StringValueNode|BooleanValueNode|EnumValueNode|ListValueNode|ObjectValueNode|null */
    public ?Value_Node $default_value = null;
    /** @var NodeList<DirectiveNode> */
    public Node_List $directives;
    public ?String_Value_Node $description = null;
}
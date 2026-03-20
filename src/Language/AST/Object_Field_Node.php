<?php

declare (strict_types=1);
namespace Graph_Ql\Language\AST;

class Object_Field_Node extends Node
{
    public string $kind = Node_Kind::OBJECT_FIELD;
    public Name_Node $name;
    /** @var VariableNode|NullValueNode|IntValueNode|FloatValueNode|StringValueNode|BooleanValueNode|EnumValueNode|ListValueNode|ObjectValueNode */
    public Value_Node $value;
}
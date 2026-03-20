<?php

declare (strict_types=1);
namespace Graph_Ql\Language\AST;

/**
 * @phpstan-type ArgumentNodeValue VariableNode|NullValueNode|IntValueNode|FloatValueNode|StringValueNode|BooleanValueNode|EnumValueNode|ListValueNode|ObjectValueNode
 */
class Argument_Node extends Node
{
    public string $kind = Node_Kind::ARGUMENT;
    /** @phpstan-var ArgumentNodeValue */
    public Value_Node $value;
    public Name_Node $name;
}
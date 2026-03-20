<?php

declare (strict_types=1);
namespace Graph_Ql\Language\AST;

class Variable_Definition_Node extends Node implements Definition_Node
{
    public string $kind = Node_Kind::VARIABLE_DEFINITION;
    public Variable_Node $variable;
    /** @var NamedTypeNode|ListTypeNode|NonNullTypeNode */
    public Type_Node $type;
    /** @var VariableNode|NullValueNode|IntValueNode|FloatValueNode|StringValueNode|BooleanValueNode|EnumValueNode|ListValueNode|ObjectValueNode|null */
    public ?Value_Node $default_value = null;
    /** @var NodeList<DirectiveNode> */
    public Node_List $directives;
    public function __construct(array $vars)
    {
        parent::__construct($vars);
        $this->directives ??= new Node_List([]);
    }
}
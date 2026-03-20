<?php

declare (strict_types=1);
namespace Graph_Ql\Language\AST;

class Scalar_Type_Definition_Node extends Node implements Type_Definition_Node
{
    public string $kind = Node_Kind::SCALAR_TYPE_DEFINITION;
    public Name_Node $name;
    /** @var NodeList<DirectiveNode> */
    public Node_List $directives;
    public ?String_Value_Node $description = null;
    public function get_name(): Name_Node
    {
        return $this->name;
    }
}
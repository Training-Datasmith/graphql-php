<?php

declare (strict_types=1);
namespace Graph_Ql\Language\AST;

class Union_Type_Extension_Node extends Node implements Type_Extension_Node
{
    public string $kind = Node_Kind::UNION_TYPE_EXTENSION;
    public Name_Node $name;
    /** @var NodeList<DirectiveNode> */
    public Node_List $directives;
    /** @var NodeList<NamedTypeNode> */
    public Node_List $types;
    public function get_name(): Name_Node
    {
        return $this->name;
    }
}
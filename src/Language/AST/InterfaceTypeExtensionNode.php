<?php

declare (strict_types=1);
namespace Graph_Ql\Language\AST;

class Interface_Type_Extension_Node extends Node implements Type_Extension_Node
{
    public string $kind = Node_Kind::INTERFACE_TYPE_EXTENSION;
    public Name_Node $name;
    /** @var NodeList<DirectiveNode> */
    public Node_List $directives;
    /** @var NodeList<NamedTypeNode> */
    public Node_List $interfaces;
    /** @var NodeList<FieldDefinitionNode> */
    public Node_List $fields;
    public function get_name(): Name_Node
    {
        return $this->name;
    }
}
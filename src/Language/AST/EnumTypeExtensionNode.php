<?php

declare (strict_types=1);
namespace Graph_Ql\Language\AST;

class Enum_Type_Extension_Node extends Node implements Type_Extension_Node
{
    public string $kind = Node_Kind::ENUM_TYPE_EXTENSION;
    public Name_Node $name;
    /** @var NodeList<DirectiveNode> */
    public Node_List $directives;
    /** @var NodeList<EnumValueDefinitionNode> */
    public Node_List $values;
    public function __construct(array $vars)
    {
        parent::__construct($vars);
        $this->directives ??= new Node_List([]);
    }
    public function get_name(): Name_Node
    {
        return $this->name;
    }
}
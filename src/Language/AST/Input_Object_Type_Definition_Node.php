<?php

declare (strict_types=1);
namespace Graph_Ql\Language\AST;

class Input_Object_Type_Definition_Node extends Node implements Type_Definition_Node
{
    public string $kind = Node_Kind::INPUT_OBJECT_TYPE_DEFINITION;
    public Name_Node $name;
    /** @var NodeList<DirectiveNode> */
    public Node_List $directives;
    /** @var NodeList<InputValueDefinitionNode> */
    public Node_List $fields;
    public ?String_Value_Node $description = null;
    public function get_name(): Name_Node
    {
        return $this->name;
    }
    public function __construct(array $vars)
    {
        parent::__construct($vars);
        $this->directives ??= new Node_List([]);
    }
}
<?php

declare (strict_types=1);
namespace Graph_Ql\Language\AST;

class Field_Node extends Node implements Selection_Node
{
    public string $kind = Node_Kind::FIELD;
    public Name_Node $name;
    public ?Name_Node $alias = null;
    /** @var NodeList<ArgumentNode> */
    public Node_List $arguments;
    /** @var NodeList<DirectiveNode> */
    public Node_List $directives;
    public ?Selection_Set_Node $selection_set = null;
    public function __construct(array $vars)
    {
        parent::__construct($vars);
        $this->directives ??= new Node_List([]);
        $this->arguments ??= new Node_List([]);
    }
}
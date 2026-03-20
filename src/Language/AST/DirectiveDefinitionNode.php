<?php

declare (strict_types=1);
namespace Graph_Ql\Language\AST;

class Directive_Definition_Node extends Node implements Type_System_Definition_Node
{
    public string $kind = Node_Kind::DIRECTIVE_DEFINITION;
    public Name_Node $name;
    public ?String_Value_Node $description = null;
    /** @var NodeList<InputValueDefinitionNode> */
    public Node_List $arguments;
    public bool $repeatable;
    /** @var NodeList<NameNode> */
    public Node_List $locations;
    public function __construct(array $vars)
    {
        parent::__construct($vars);
        $this->arguments ??= new Node_List([]);
    }
}
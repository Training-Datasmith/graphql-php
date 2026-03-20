<?php

declare (strict_types=1);
namespace Graph_Ql\Language\AST;

class Directive_Node extends Node
{
    public string $kind = Node_Kind::DIRECTIVE;
    public Name_Node $name;
    /** @var NodeList<ArgumentNode> */
    public Node_List $arguments;
    public function __construct(array $vars)
    {
        parent::__construct($vars);
        $this->arguments ??= new Node_List([]);
    }
}
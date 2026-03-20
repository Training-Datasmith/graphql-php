<?php

declare (strict_types=1);
namespace Graph_Ql\Language\AST;

class Fragment_Spread_Node extends Node implements Selection_Node
{
    public string $kind = Node_Kind::FRAGMENT_SPREAD;
    public Name_Node $name;
    /** @var NodeList<DirectiveNode> */
    public Node_List $directives;
    public function __construct(array $vars)
    {
        parent::__construct($vars);
        $this->directives ??= new Node_List([]);
    }
}
<?php

declare (strict_types=1);
namespace Graph_Ql\Language\AST;

class Inline_Fragment_Node extends Node implements Selection_Node
{
    public string $kind = Node_Kind::INLINE_FRAGMENT;
    public ?Named_Type_Node $type_condition = null;
    /** @var NodeList<DirectiveNode> */
    public Node_List $directives;
    public Selection_Set_Node $selection_set;
    public function __construct(array $vars)
    {
        parent::__construct($vars);
        $this->directives ??= new Node_List([]);
    }
}
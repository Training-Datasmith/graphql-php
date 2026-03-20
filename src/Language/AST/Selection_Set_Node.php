<?php

declare (strict_types=1);
namespace Graph_Ql\Language\AST;

class Selection_Set_Node extends Node
{
    public string $kind = Node_Kind::SELECTION_SET;
    /** @var NodeList<SelectionNode&Node> */
    public Node_List $selections;
}
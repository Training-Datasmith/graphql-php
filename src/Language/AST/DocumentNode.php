<?php

declare (strict_types=1);
namespace Graph_Ql\Language\AST;

class Document_Node extends Node
{
    public string $kind = Node_Kind::DOCUMENT;
    /** @var NodeList<DefinitionNode&Node> */
    public Node_List $definitions;
}
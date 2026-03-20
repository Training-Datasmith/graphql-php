<?php

declare (strict_types=1);
namespace Graph_Ql\Language\AST;

class Schema_Extension_Node extends Node implements Type_System_Extension_Node
{
    public string $kind = Node_Kind::SCHEMA_EXTENSION;
    /** @var NodeList<DirectiveNode> */
    public Node_List $directives;
    /** @var NodeList<OperationTypeDefinitionNode> */
    public Node_List $operation_types;
}
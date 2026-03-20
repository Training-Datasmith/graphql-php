<?php

declare (strict_types=1);
namespace Graph_Ql\Language\AST;

class Schema_Definition_Node extends Node implements Type_System_Definition_Node
{
    public string $kind = Node_Kind::SCHEMA_DEFINITION;
    /** @var NodeList<DirectiveNode> */
    public Node_List $directives;
    /** @var NodeList<OperationTypeDefinitionNode> */
    public Node_List $operation_types;
    public ?String_Value_Node $description = null;
}
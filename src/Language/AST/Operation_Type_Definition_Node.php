<?php

declare (strict_types=1);
namespace Graph_Ql\Language\AST;

/**
 * @phpstan-import-type OperationType from OperationDefinitionNode
 */
class Operation_Type_Definition_Node extends Node
{
    public string $kind = Node_Kind::OPERATION_TYPE_DEFINITION;
    /** @var OperationType */
    public string $operation;
    public Named_Type_Node $type;
}
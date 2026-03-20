<?php

declare (strict_types=1);
namespace Graph_Ql\Language\AST;

/**
 * @phpstan-type OperationType 'query'|'mutation'|'subscription'
 */
class Operation_Definition_Node extends Node implements Executable_Definition_Node, Has_Selection_Set
{
    public string $kind = Node_Kind::OPERATION_DEFINITION;
    public ?Name_Node $name = null;
    /** @var OperationType */
    public string $operation;
    /** @var NodeList<VariableDefinitionNode> */
    public Node_List $variable_definitions;
    /** @var NodeList<DirectiveNode> */
    public Node_List $directives;
    public Selection_Set_Node $selection_set;
    public function __construct(array $vars)
    {
        parent::__construct($vars);
        $this->directives ??= new Node_List([]);
        $this->variable_definitions ??= new Node_List([]);
    }
    public function get_selection_set(): Selection_Set_Node
    {
        return $this->selection_set;
    }
}
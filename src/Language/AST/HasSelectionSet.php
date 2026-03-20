<?php

declare (strict_types=1);
namespace Graph_Ql\Language\AST;

/**
 * export type DefinitionNode = OperationDefinitionNode
 *                        | FragmentDefinitionNode.
 */
interface Has_Selection_Set
{
    public function get_selection_set(): Selection_Set_Node;
}
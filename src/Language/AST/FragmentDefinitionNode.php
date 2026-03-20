<?php

declare (strict_types=1);
namespace Graph_Ql\Language\AST;

class Fragment_Definition_Node extends Node implements Executable_Definition_Node, Has_Selection_Set
{
    public string $kind = Node_Kind::FRAGMENT_DEFINITION;
    public Name_Node $name;
    /**
     * Note: fragment variable definitions are experimental and may be changed
     * or removed in the future.
     *
     * Thus, this property is the single exception where this is not always a NodeList but may be null.
     *
     * @var NodeList<VariableDefinitionNode>|null
     */
    public ?Node_List $variable_definitions = null;
    public Named_Type_Node $type_condition;
    /** @var NodeList<DirectiveNode> */
    public Node_List $directives;
    public Selection_Set_Node $selection_set;
    public function __construct(array $vars)
    {
        parent::__construct($vars);
        $this->directives ??= new Node_List([]);
    }
    public function get_selection_set(): Selection_Set_Node
    {
        return $this->selection_set;
    }
}
<?php

declare (strict_types=1);
namespace Graph_Ql\Validator;

use Graph_Ql\Error\Error;
use Graph_Ql\Language\AST\Document_Node;
use Graph_Ql\Language\AST\Field_Node;
use Graph_Ql\Language\AST\Fragment_Definition_Node;
use Graph_Ql\Language\AST\Fragment_Spread_Node;
use Graph_Ql\Language\AST\Has_Selection_Set;
use Graph_Ql\Language\AST\Inline_Fragment_Node;
use Graph_Ql\Language\AST\Node;
use Graph_Ql\Language\AST\Node_Kind;
use Graph_Ql\Language\AST\Operation_Definition_Node;
use Graph_Ql\Language\AST\Variable_Node;
use Graph_Ql\Language\Visitor;
use Graph_Ql\Type\Definition\Argument;
use Graph_Ql\Type\Definition\Composite_Type;
use Graph_Ql\Type\Definition\Directive;
use Graph_Ql\Type\Definition\Field_Definition;
use Graph_Ql\Type\Definition\Input_Type;
use Graph_Ql\Type\Definition\Type;
use Graph_Ql\Type\Schema;
use Graph_Ql\Utils\Type_Info;
/**
 * An instance of this class is passed as the "this" context to all validators,
 * allowing access to commonly useful contextual information from within a
 * validation rule.
 *
 * @phpstan-type VariableUsage array{node: VariableNode, type: (Type&InputType)|null, defaultValue: mixed}
 */
class Query_Validation_Context implements Validation_Context
{
    protected Schema $schema;
    protected Document_Node $ast;
    /** @var list<Error> */
    protected array $errors = [];
    private Type_Info $type_info;
    /** @var array<string, FragmentDefinitionNode> */
    private array $fragments;
    /** @var \SplObjectStorage<HasSelectionSet, array<int, FragmentSpreadNode>> */
    private \Spl_Object_Storage $fragment_spreads;
    /** @var \SplObjectStorage<OperationDefinitionNode, array<int, FragmentDefinitionNode>> */
    private \Spl_Object_Storage $recursively_referenced_fragments;
    /** @var \SplObjectStorage<HasSelectionSet, array<int, VariableUsage>> */
    private \Spl_Object_Storage $variable_usages;
    /** @var \SplObjectStorage<HasSelectionSet, array<int, VariableUsage>> */
    private \Spl_Object_Storage $recursive_variable_usages;
    public function __construct(Schema $schema, Document_Node $ast, Type_Info $type_info)
    {
        $this->schema = $schema;
        $this->ast = $ast;
        $this->type_info = $type_info;
        $this->fragment_spreads = new \Spl_Object_Storage();
        $this->recursively_referenced_fragments = new \Spl_Object_Storage();
        $this->variable_usages = new \Spl_Object_Storage();
        $this->recursive_variable_usages = new \Spl_Object_Storage();
    }
    public function report_error(Error $error): void
    {
        $this->errors[] = $error;
    }
    /** @return list<Error> */
    public function get_errors(): array
    {
        return $this->errors;
    }
    public function get_document(): Document_Node
    {
        return $this->ast;
    }
    public function get_schema(): Schema
    {
        return $this->schema;
    }
    /**
     * @throws \Exception
     *
     * @phpstan-return array<int, VariableUsage>
     */
    public function get_recursive_variable_usages(Operation_Definition_Node $operation): array
    {
        $usages = $this->recursive_variable_usages[$operation] ?? null;
        if ($usages === null) {
            $usages = $this->get_variable_usages($operation);
            $fragments = $this->get_recursively_referenced_fragments($operation);
            $all_usages = [$usages];
            foreach ($fragments as $fragment) {
                $all_usages[] = $this->get_variable_usages($fragment);
            }
            $usages = array_merge(...$all_usages);
            $this->recursive_variable_usages[$operation] = $usages;
        }
        return $usages;
    }
    /**
     * @param HasSelectionSet&Node $node
     *
     * @throws \Exception
     *
     * @phpstan-return array<int, VariableUsage>
     */
    private function get_variable_usages(Has_Selection_Set $node): array
    {
        if (!isset($this->variable_usages[$node])) {
            $usages = [];
            $type_info = new Type_Info($this->schema);
            Visitor::visit($node, Visitor::visit_with_type_info($type_info, [Node_Kind::VARIABLE_DEFINITION => static fn(): \Graph_Ql\Language\Visitor_Skip_Node => Visitor::skip_node(), Node_Kind::VARIABLE => static function (Variable_Node $variable) use (&$usages, $type_info): void {
                $usages[] = ['node' => $variable, 'type' => $type_info->get_input_type(), 'defaultValue' => $type_info->get_default_value()];
            }]));
            return $this->variable_usages[$node] = $usages;
        }
        return $this->variable_usages[$node];
    }
    /** @return array<int, FragmentDefinitionNode> */
    public function get_recursively_referenced_fragments(Operation_Definition_Node $operation): array
    {
        $fragments = $this->recursively_referenced_fragments[$operation] ?? null;
        if ($fragments === null) {
            $fragments = [];
            $collected_names = [];
            $nodes_to_visit = [$operation];
            while ($nodes_to_visit !== []) {
                $node = array_pop($nodes_to_visit);
                $spreads = $this->get_fragment_spreads($node);
                foreach ($spreads as $spread) {
                    $frag_name = $spread->name->value;
                    if ($collected_names[$frag_name] ?? false) {
                        continue;
                    }
                    $collected_names[$frag_name] = true;
                    $fragment = $this->get_fragment($frag_name);
                    if ($fragment === null) {
                        continue;
                    }
                    $fragments[] = $fragment;
                    $nodes_to_visit[] = $fragment;
                }
            }
            $this->recursively_referenced_fragments[$operation] = $fragments;
        }
        return $fragments;
    }
    /**
     * @param OperationDefinitionNode|FragmentDefinitionNode $node
     *
     * @return array<int, FragmentSpreadNode>
     */
    public function get_fragment_spreads(Has_Selection_Set $node): array
    {
        $spreads = $this->fragment_spreads[$node] ?? null;
        if ($spreads === null) {
            $spreads = [];
            $sets_to_visit = [$node->get_selection_set()];
            while ($sets_to_visit !== []) {
                $set = array_pop($sets_to_visit);
                foreach ($set->selections as $selection) {
                    if ($selection instanceof Fragment_Spread_Node) {
                        $spreads[] = $selection;
                    } else {
                        assert($selection instanceof Field_Node || $selection instanceof Inline_Fragment_Node);
                        $selection_set = $selection->selection_set;
                        if ($selection_set !== null) {
                            $sets_to_visit[] = $selection_set;
                        }
                    }
                }
            }
            $this->fragment_spreads[$node] = $spreads;
        }
        return $spreads;
    }
    public function get_fragment(string $name): ?Fragment_Definition_Node
    {
        if (!isset($this->fragments)) {
            $fragments = [];
            foreach ($this->get_document()->definitions as $statement) {
                if ($statement instanceof Fragment_Definition_Node) {
                    $fragments[$statement->name->value] = $statement;
                }
            }
            $this->fragments = $fragments;
        }
        return $this->fragments[$name] ?? null;
    }
    public function get_type(): ?Type
    {
        return $this->type_info->get_type();
    }
    /** @return (CompositeType&Type)|null */
    public function get_parent_type(): ?Composite_Type
    {
        return $this->type_info->get_parent_type();
    }
    /** @return (Type&InputType)|null */
    public function get_input_type(): ?Input_Type
    {
        return $this->type_info->get_input_type();
    }
    /** @return (Type&InputType)|null */
    public function get_parent_input_type(): ?Input_Type
    {
        return $this->type_info->get_parent_input_type();
    }
    public function get_field_def(): ?Field_Definition
    {
        return $this->type_info->get_field_def();
    }
    public function get_directive(): ?Directive
    {
        return $this->type_info->get_directive();
    }
    public function get_argument(): ?Argument
    {
        return $this->type_info->get_argument();
    }
}
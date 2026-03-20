<?php

declare (strict_types=1);
namespace Graph_Ql\Validator\Rules;

use Graph_Ql\Error\Error;
use Graph_Ql\Language\AST\Field_Node;
use Graph_Ql\Language\AST\Fragment_Definition_Node;
use Graph_Ql\Language\AST\Fragment_Spread_Node;
use Graph_Ql\Language\AST\Inline_Fragment_Node;
use Graph_Ql\Language\AST\Node;
use Graph_Ql\Language\AST\Node_Kind;
use Graph_Ql\Language\AST\Operation_Definition_Node;
use Graph_Ql\Language\AST\Selection_Set_Node;
use Graph_Ql\Validator\Query_Validation_Context;
class Query_Depth extends Query_Security_Rule
{
    /** @var array<string, bool> Fragment names which are already calculated in recursion */
    protected array $calculated_fragments = [];
    protected int $max_query_depth;
    /** @throws \InvalidArgumentException */
    public function __construct(int $max_query_depth)
    {
        $this->set_max_query_depth($max_query_depth);
    }
    public function get_visitor(Query_Validation_Context $context): array
    {
        return $this->invoke_if_needed($context, [Node_Kind::OPERATION_DEFINITION => ['leave' => function (Operation_Definition_Node $operation_definition) use ($context): void {
            $max_depth = $this->field_depth($operation_definition);
            if ($max_depth <= $this->max_query_depth) {
                return;
            }
            $context->report_error(new Error(static::max_query_depth_error_message($this->max_query_depth, $max_depth)));
        }]]);
    }
    /** @param OperationDefinitionNode|FieldNode|InlineFragmentNode|FragmentDefinitionNode $node */
    protected function field_depth(Node $node, int $depth = 0, int $max_depth = 0): int
    {
        if ($node->selection_set instanceof Selection_Set_Node) {
            foreach ($node->selection_set->selections as $child_node) {
                $max_depth = $this->node_depth($child_node, $depth, $max_depth);
            }
        }
        return $max_depth;
    }
    protected function node_depth(Node $node, int $depth = 0, int $max_depth = 0): int
    {
        switch (true) {
            case $node instanceof Field_Node:
                // node has children?
                if ($node->selection_set !== null) {
                    // update maxDepth if needed
                    if ($depth > $max_depth) {
                        $max_depth = $depth;
                    }
                    $max_depth = $this->field_depth($node, $depth + 1, $max_depth);
                }
                break;
            case $node instanceof Inline_Fragment_Node:
                $max_depth = $this->field_depth($node, $depth, $max_depth);
                break;
            case $node instanceof Fragment_Spread_Node:
                $fragment = $this->get_fragment($node);
                if ($fragment !== null) {
                    $name = $fragment->name->value;
                    if (isset($this->calculated_fragments[$name])) {
                        return $this->max_query_depth + 1;
                    }
                    $this->calculated_fragments[$name] = true;
                    $max_depth = $this->field_depth($fragment, $depth, $max_depth);
                    unset($this->calculated_fragments[$name]);
                }
                break;
        }
        return $max_depth;
    }
    public function get_max_query_depth(): int
    {
        return $this->max_query_depth;
    }
    /**
     * Set max query depth. If equal to 0 no check is done. Must be greater or equal to 0.
     *
     * @throws \InvalidArgumentException
     */
    public function set_max_query_depth(int $max_query_depth): void
    {
        $this->check_if_greater_or_equal_to_zero('maxQueryDepth', $max_query_depth);
        $this->max_query_depth = $max_query_depth;
    }
    public static function max_query_depth_error_message(int $max, int $count): string
    {
        return "Max query depth should be {$max} but got {$count}.";
    }
    protected function is_enabled(): bool
    {
        return $this->max_query_depth !== self::DISABLED;
    }
}
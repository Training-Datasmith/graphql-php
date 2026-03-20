<?php

declare (strict_types=1);
namespace Graph_Ql\Validator\Rules;

use Graph_Ql\Error\Error;
use Graph_Ql\Language\AST\Fragment_Definition_Node;
use Graph_Ql\Language\AST\Fragment_Spread_Node;
use Graph_Ql\Language\AST\Node_Kind;
use Graph_Ql\Language\Visitor;
use Graph_Ql\Language\Visitor_Operation;
use Graph_Ql\Validator\Query_Validation_Context;
class No_Fragment_Cycles extends Validation_Rule
{
    /** @var array<string, bool> */
    protected array $visited_frags;
    /** @var array<int, FragmentSpreadNode> */
    protected array $spread_path;
    /** @var array<string, int|null> */
    protected array $spread_path_index_by_name;
    public function get_visitor(Query_Validation_Context $context): array
    {
        // Tracks already visited fragments to maintain O(N) and to ensure that cycles
        // are not redundantly reported.
        $this->visited_frags = [];
        // Array of AST nodes used to produce meaningful errors
        $this->spread_path = [];
        // Position in the spread path
        $this->spread_path_index_by_name = [];
        return [Node_Kind::OPERATION_DEFINITION => static fn(): Visitor_Operation => Visitor::skip_node(), Node_Kind::FRAGMENT_DEFINITION => function (Fragment_Definition_Node $node) use ($context): Visitor_Operation {
            $this->detect_cycle_recursive($node, $context);
            return Visitor::skip_node();
        }];
    }
    protected function detect_cycle_recursive(Fragment_Definition_Node $fragment, Query_Validation_Context $context): void
    {
        if (isset($this->visited_frags[$fragment->name->value])) {
            return;
        }
        $fragment_name = $fragment->name->value;
        $this->visited_frags[$fragment_name] = true;
        $spread_nodes = $context->get_fragment_spreads($fragment);
        if ($spread_nodes === []) {
            return;
        }
        $this->spread_path_index_by_name[$fragment_name] = count($this->spread_path);
        foreach ($spread_nodes as $spread_node) {
            $spread_name = $spread_node->name->value;
            $cycle_index = $this->spread_path_index_by_name[$spread_name] ?? null;
            $this->spread_path[] = $spread_node;
            if ($cycle_index === null) {
                $spread_fragment = $context->get_fragment($spread_name);
                if ($spread_fragment !== null) {
                    $this->detect_cycle_recursive($spread_fragment, $context);
                }
            } else {
                $cycle_path = array_slice($this->spread_path, $cycle_index);
                $fragment_names = [];
                foreach (array_slice($cycle_path, 0, -1) as $frag) {
                    $fragment_names[] = $frag->name->value;
                }
                $context->report_error(new Error(static::cycle_error_message($spread_name, $fragment_names), $cycle_path));
            }
            array_pop($this->spread_path);
        }
        $this->spread_path_index_by_name[$fragment_name] = null;
    }
    /** @param array<string> $spreadNames */
    public static function cycle_error_message(string $frag_name, array $spread_names = []): string
    {
        $via = $spread_names === [] ? '' : ' via ' . implode(', ', $spread_names);
        return "Cannot spread fragment \"{$frag_name}\" within itself{$via}.";
    }
}
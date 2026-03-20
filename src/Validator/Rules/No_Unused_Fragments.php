<?php

declare (strict_types=1);
namespace Graph_Ql\Validator\Rules;

use Graph_Ql\Error\Error;
use Graph_Ql\Language\AST\Fragment_Definition_Node;
use Graph_Ql\Language\AST\Node_Kind;
use Graph_Ql\Language\AST\Operation_Definition_Node;
use Graph_Ql\Language\Visitor;
use Graph_Ql\Language\Visitor_Operation;
use Graph_Ql\Validator\Query_Validation_Context;
class No_Unused_Fragments extends Validation_Rule
{
    /** @var array<int, OperationDefinitionNode> */
    protected array $operation_defs;
    /** @var array<int, FragmentDefinitionNode> */
    protected array $fragment_defs;
    public function get_visitor(Query_Validation_Context $context): array
    {
        $this->operation_defs = [];
        $this->fragment_defs = [];
        return [Node_Kind::OPERATION_DEFINITION => function ($node): Visitor_Operation {
            $this->operation_defs[] = $node;
            return Visitor::skip_node();
        }, Node_Kind::FRAGMENT_DEFINITION => function (Fragment_Definition_Node $def): Visitor_Operation {
            $this->fragment_defs[] = $def;
            return Visitor::skip_node();
        }, Node_Kind::DOCUMENT => ['leave' => function () use ($context): void {
            $fragment_name_used = [];
            foreach ($this->operation_defs as $operation) {
                foreach ($context->get_recursively_referenced_fragments($operation) as $fragment) {
                    $fragment_name_used[$fragment->name->value] = true;
                }
            }
            foreach ($this->fragment_defs as $fragment_def) {
                $frag_name = $fragment_def->name->value;
                if (!isset($fragment_name_used[$frag_name])) {
                    $context->report_error(new Error(static::unused_frag_message($frag_name), [$fragment_def]));
                }
            }
        }]];
    }
    public static function unused_frag_message(string $frag_name): string
    {
        return "Fragment \"{$frag_name}\" is never used.";
    }
}
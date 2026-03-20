<?php

declare (strict_types=1);
namespace Graph_Ql\Validator\Rules;

use Graph_Ql\Error\Error;
use Graph_Ql\Language\AST\Node_Kind;
use Graph_Ql\Language\AST\Operation_Definition_Node;
use Graph_Ql\Language\Visitor;
use Graph_Ql\Language\Visitor_Operation;
use Graph_Ql\Validator\Query_Validation_Context;
class Single_Field_Subscription extends Validation_Rule
{
    public function get_visitor(Query_Validation_Context $context): array
    {
        return [Node_Kind::OPERATION_DEFINITION => static function (Operation_Definition_Node $node) use ($context): Visitor_Operation {
            if ($node->operation === 'subscription') {
                $selections = $node->selection_set->selections;
                if (count($selections) > 1) {
                    $offending_selections = $selections->splice(1, count($selections));
                    $context->report_error(new Error(static::multiple_fields_in_operation($node->name->value ?? null), $offending_selections));
                }
            }
            return Visitor::skip_node();
        }];
    }
    public static function multiple_fields_in_operation(?string $operation_name): string
    {
        if ($operation_name === null) {
            return 'Anonymous Subscription must select only one top level field.';
        }
        return "Subscription \"{$operation_name}\" must select only one top level field.";
    }
}
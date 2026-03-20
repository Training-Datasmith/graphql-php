<?php

declare (strict_types=1);
namespace Graph_Ql\Validator\Rules;

use Graph_Ql\Error\Error;
use Graph_Ql\Language\AST\Fragment_Spread_Node;
use Graph_Ql\Language\AST\Node_Kind;
use Graph_Ql\Validator\Query_Validation_Context;
class Known_Fragment_Names extends Validation_Rule
{
    public function get_visitor(Query_Validation_Context $context): array
    {
        return [Node_Kind::FRAGMENT_SPREAD => static function (Fragment_Spread_Node $node) use ($context): void {
            $fragment_name = $node->name->value;
            $fragment = $context->get_fragment($fragment_name);
            if ($fragment !== null) {
                return;
            }
            $context->report_error(new Error(static::unknown_fragment_message($fragment_name), [$node->name]));
        }];
    }
    public static function unknown_fragment_message(string $frag_name): string
    {
        return "Unknown fragment \"{$frag_name}\".";
    }
}
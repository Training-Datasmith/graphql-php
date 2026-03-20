<?php

declare (strict_types=1);
namespace Graph_Ql\Validator\Rules;

use Graph_Ql\Error\Error;
use Graph_Ql\Language\AST\Fragment_Definition_Node;
use Graph_Ql\Language\AST\Inline_Fragment_Node;
use Graph_Ql\Language\AST\Node_Kind;
use Graph_Ql\Language\Printer;
use Graph_Ql\Type\Definition\Type;
use Graph_Ql\Utils\AST;
use Graph_Ql\Validator\Query_Validation_Context;
class Fragments_On_Composite_Types extends Validation_Rule
{
    public function get_visitor(Query_Validation_Context $context): array
    {
        return [Node_Kind::INLINE_FRAGMENT => static function (Inline_Fragment_Node $node) use ($context): void {
            if ($node->type_condition === null) {
                return;
            }
            $type = AST::type_from_ast([$context->get_schema(), 'getType'], $node->type_condition);
            if ($type === null || Type::is_composite_type($type)) {
                return;
            }
            $context->report_error(new Error(static::inline_fragment_on_non_composite_error_message($type->to_string()), [$node->type_condition]));
        }, Node_Kind::FRAGMENT_DEFINITION => static function (Fragment_Definition_Node $node) use ($context): void {
            $type = AST::type_from_ast([$context->get_schema(), 'getType'], $node->type_condition);
            if ($type === null || Type::is_composite_type($type)) {
                return;
            }
            $context->report_error(new Error(static::fragment_on_non_composite_error_message($node->name->value, Printer::do_print($node->type_condition)), [$node->type_condition]));
        }];
    }
    public static function inline_fragment_on_non_composite_error_message(string $type): string
    {
        return "Fragment cannot condition on non composite type \"{$type}\".";
    }
    public static function fragment_on_non_composite_error_message(string $frag_name, string $type): string
    {
        return "Fragment \"{$frag_name}\" cannot condition on non composite type \"{$type}\".";
    }
}
<?php

declare (strict_types=1);
namespace Graph_Ql\Validator\Rules;

use Graph_Ql\Error\Error;
use Graph_Ql\Language\AST\Field_Node;
use Graph_Ql\Language\AST\Node_Kind;
use Graph_Ql\Type\Definition\Type;
use Graph_Ql\Validator\Query_Validation_Context;
class Scalar_Leafs extends Validation_Rule
{
    public function get_visitor(Query_Validation_Context $context): array
    {
        return [Node_Kind::FIELD => static function (Field_Node $node) use ($context): void {
            $type = $context->get_type();
            if ($type === null) {
                return;
            }
            if (Type::is_leaf_type(Type::get_named_type($type))) {
                if ($node->selection_set !== null) {
                    $context->report_error(new Error(static::no_subselection_allowed_message($node->name->value, $type->to_string()), [$node->selection_set]));
                }
            } elseif ($node->selection_set === null) {
                $context->report_error(new Error(static::required_subselection_message($node->name->value, $type->to_string()), [$node]));
            }
        }];
    }
    public static function no_subselection_allowed_message(string $field, string $type): string
    {
        return "Field \"{$field}\" of type \"{$type}\" must not have a sub selection.";
    }
    public static function required_subselection_message(string $field, string $type): string
    {
        return "Field \"{$field}\" of type \"{$type}\" must have a sub selection.";
    }
}
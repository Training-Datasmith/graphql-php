<?php

declare (strict_types=1);
namespace Graph_Ql\Validator\Rules;

use Graph_Ql\Error\Error;
use Graph_Ql\Error\Invariant_Violation;
use Graph_Ql\Language\AST\Argument_Node;
use Graph_Ql\Language\AST\Node_Kind;
use Graph_Ql\Type\Definition\Argument;
use Graph_Ql\Type\Definition\Named_Type;
use Graph_Ql\Utils\Utils;
use Graph_Ql\Validator\Query_Validation_Context;
/**
 * Known argument names.
 *
 * A GraphQL field is only valid if all supplied arguments are defined by
 * that field.
 */
class Known_Argument_Names extends Validation_Rule
{
    /** @throws InvariantViolation */
    public function get_visitor(Query_Validation_Context $context): array
    {
        $known_argument_names_on_directives = new Known_Argument_Names_On_Directives();
        return $known_argument_names_on_directives->get_visitor($context) + [Node_Kind::ARGUMENT => static function (Argument_Node $node) use ($context): void {
            $arg_def = $context->get_argument();
            if ($arg_def !== null) {
                return;
            }
            $field_def = $context->get_field_def();
            if ($field_def === null) {
                return;
            }
            $parent_type = $context->get_parent_type();
            if (!$parent_type instanceof Named_Type) {
                return;
            }
            $context->report_error(new Error(static::unknown_arg_message($node->name->value, $field_def->name, $parent_type->name, Utils::suggestion_list($node->name->value, array_map(static fn(Argument $arg): string => $arg->name, $field_def->args))), [$node]));
        }];
    }
    /** @param array<string> $suggestedArgs */
    public static function unknown_arg_message(string $arg_name, string $field_name, string $type_name, array $suggested_args): string
    {
        $message = "Unknown argument \"{$arg_name}\" on field \"{$field_name}\" of type \"{$type_name}\".";
        if ($suggested_args !== []) {
            $suggestions = Utils::quoted_or_list($suggested_args);
            $message .= " Did you mean {$suggestions}?";
        }
        return $message;
    }
}
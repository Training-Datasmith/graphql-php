<?php

declare (strict_types=1);
namespace Graph_Ql\Validator\Rules;

use Graph_Ql\Error\Error;
use Graph_Ql\Language\AST\Directive_Definition_Node;
use Graph_Ql\Language\AST\Input_Value_Definition_Node;
use Graph_Ql\Language\AST\Interface_Type_Definition_Node;
use Graph_Ql\Language\AST\Interface_Type_Extension_Node;
use Graph_Ql\Language\AST\Node_Kind;
use Graph_Ql\Language\AST\Node_List;
use Graph_Ql\Language\AST\Object_Type_Definition_Node;
use Graph_Ql\Language\AST\Object_Type_Extension_Node;
use Graph_Ql\Language\Visitor;
use Graph_Ql\Language\Visitor_Operation;
use Graph_Ql\Validator\Sdl_Validation_Context;
/**
 * Unique argument definition names.
 *
 * A GraphQL Object or Interface type is only valid if all its fields have uniquely named arguments.
 * A GraphQL Directive is only valid if all its arguments are uniquely named.
 */
class Unique_Argument_Definition_Names extends Validation_Rule
{
    public function get_sdl_visitor(Sdl_Validation_Context $context): array
    {
        $check_arg_uniqueness_per_field = static function ($node) use ($context): Visitor_Operation {
            assert($node instanceof Interface_Type_Definition_Node || $node instanceof Interface_Type_Extension_Node || $node instanceof Object_Type_Definition_Node || $node instanceof Object_Type_Extension_Node);
            foreach ($node->fields as $field_def) {
                self::check_arg_uniqueness("{$node->name->value}.{$field_def->name->value}", $field_def->arguments, $context);
            }
            return Visitor::skip_node();
        };
        return [Node_Kind::DIRECTIVE_DEFINITION => static fn(Directive_Definition_Node $node): Visitor_Operation => self::check_arg_uniqueness("@{$node->name->value}", $node->arguments, $context), Node_Kind::INTERFACE_TYPE_DEFINITION => $check_arg_uniqueness_per_field, Node_Kind::INTERFACE_TYPE_EXTENSION => $check_arg_uniqueness_per_field, Node_Kind::OBJECT_TYPE_DEFINITION => $check_arg_uniqueness_per_field, Node_Kind::OBJECT_TYPE_EXTENSION => $check_arg_uniqueness_per_field];
    }
    /** @param NodeList<InputValueDefinitionNode> $arguments */
    private static function check_arg_uniqueness(string $parent_name, Node_List $arguments, Sdl_Validation_Context $context): Visitor_Operation
    {
        $seen_args = [];
        foreach ($arguments as $argument) {
            $seen_args[$argument->name->value][] = $argument;
        }
        foreach ($seen_args as $arg_name => $arg_nodes) {
            if (count($arg_nodes) > 1) {
                $context->report_error(new Error("Argument \"{$parent_name}({$arg_name}:)\" can only be defined once.", $arg_nodes));
            }
        }
        return Visitor::skip_node();
    }
}
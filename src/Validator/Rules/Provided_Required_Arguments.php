<?php

declare (strict_types=1);
namespace Graph_Ql\Validator\Rules;

use Graph_Ql\Error\Error;
use Graph_Ql\Language\AST\Field_Node;
use Graph_Ql\Language\AST\Node_Kind;
use Graph_Ql\Language\Visitor;
use Graph_Ql\Language\Visitor_Operation;
use Graph_Ql\Validator\Query_Validation_Context;
class Provided_Required_Arguments extends Validation_Rule
{
    /** @throws \Exception */
    public function get_visitor(Query_Validation_Context $context): array
    {
        $provided_required_arguments_on_directives = new Provided_Required_Arguments_On_Directives();
        return $provided_required_arguments_on_directives->get_visitor($context) + [Node_Kind::FIELD => ['leave' => static function (Field_Node $field_node) use ($context): ?Visitor_Operation {
            $field_def = $context->get_field_def();
            if ($field_def === null) {
                return Visitor::skip_node();
            }
            $arg_nodes = $field_node->arguments;
            $arg_node_map = [];
            foreach ($arg_nodes as $arg_node) {
                $arg_node_map[$arg_node->name->value] = $arg_node;
            }
            foreach ($field_def->args as $arg_def) {
                $arg_node = $arg_node_map[$arg_def->name] ?? null;
                if ($arg_node === null && $arg_def->is_required()) {
                    $context->report_error(new Error(static::missing_field_arg_message($field_node->name->value, $arg_def->name, $arg_def->get_type()->to_string()), [$field_node]));
                }
            }
            return null;
        }]];
    }
    public static function missing_field_arg_message(string $field_name, string $arg_name, string $type): string
    {
        return "Field \"{$field_name}\" argument \"{$arg_name}\" of type \"{$type}\" is required but not provided.";
    }
}
<?php

declare (strict_types=1);
namespace Graph_Ql\Validator\Rules;

use Graph_Ql\Error\Error;
use Graph_Ql\Language\AST\Boolean_Value_Node;
use Graph_Ql\Language\AST\Enum_Value_Node;
use Graph_Ql\Language\AST\Float_Value_Node;
use Graph_Ql\Language\AST\Int_Value_Node;
use Graph_Ql\Language\AST\List_Value_Node;
use Graph_Ql\Language\AST\Node_Kind;
use Graph_Ql\Language\AST\Null_Value_Node;
use Graph_Ql\Language\AST\Object_Field_Node;
use Graph_Ql\Language\AST\Object_Value_Node;
use Graph_Ql\Language\AST\String_Value_Node;
use Graph_Ql\Language\AST\Value_Node;
use Graph_Ql\Language\AST\Variable_Node;
use Graph_Ql\Language\Printer;
use Graph_Ql\Language\Visitor;
use Graph_Ql\Language\Visitor_Operation;
use Graph_Ql\Type\Definition\Input_Object_Type;
use Graph_Ql\Type\Definition\Leaf_Type;
use Graph_Ql\Type\Definition\List_Of_Type;
use Graph_Ql\Type\Definition\Non_Null;
use Graph_Ql\Type\Definition\Type;
use Graph_Ql\Utils\Utils;
use Graph_Ql\Validator\Query_Validation_Context;
/**
 * Value literals of correct type.
 *
 * A GraphQL document is only valid if all value literals are of the type
 * expected at their position.
 */
class Values_Of_Correct_Type extends Validation_Rule
{
    public function get_visitor(Query_Validation_Context $context): array
    {
        return [Node_Kind::NULL => static function (Null_Value_Node $node) use ($context): void {
            $type = $context->get_input_type();
            if ($type instanceof Non_Null) {
                $type_str = Utils::print_safe($type);
                $node_str = Printer::do_print($node);
                $context->report_error(new Error("Expected value of type \"{$type_str}\", found {$node_str}.", $node));
            }
        }, Node_Kind::LST => function (List_Value_Node $node) use ($context): ?Visitor_Operation {
            // Note: TypeInfo will traverse into a list's item type, so look to the
            // parent input type to check if it is a list.
            $parent_type = $context->get_parent_input_type();
            $type = $parent_type === null ? null : Type::get_nullable_type($parent_type);
            if (!$type instanceof List_Of_Type) {
                $this->is_valid_value_node($context, $node);
                return Visitor::skip_node();
            }
            return null;
        }, Node_Kind::OBJECT => function (Object_Value_Node $node) use ($context): ?Visitor_Operation {
            $type = Type::get_named_type($context->get_input_type());
            if (!$type instanceof Input_Object_Type) {
                $this->is_valid_value_node($context, $node);
                return Visitor::skip_node();
            }
            // Ensure every required field exists.
            $input_fields = $type->get_fields();
            $field_node_map = [];
            foreach ($node->fields as $field) {
                $field_node_map[$field->name->value] = $field;
            }
            foreach ($input_fields as $input_field_name => $field_def) {
                if (!isset($field_node_map[$input_field_name]) && $field_def->is_required()) {
                    $field_type = Utils::print_safe($field_def->get_type());
                    $context->report_error(new Error("Field {$type->name}.{$input_field_name} of required type {$field_type} was not provided.", $node));
                }
            }
            return null;
        }, Node_Kind::OBJECT_FIELD => static function (Object_Field_Node $node) use ($context): void {
            $parent_type = Type::get_named_type($context->get_parent_input_type());
            if (!$parent_type instanceof Input_Object_Type) {
                return;
            }
            if ($context->get_input_type() !== null) {
                return;
            }
            $suggestions = Utils::suggestion_list($node->name->value, array_keys($parent_type->get_fields()));
            $did_you_mean = $suggestions === [] ? null : ' Did you mean ' . Utils::quoted_or_list($suggestions) . '?';
            $context->report_error(new Error("Field \"{$node->name->value}\" is not defined by type \"{$parent_type->name}\".{$did_you_mean}", $node));
        }, Node_Kind::ENUM => function (Enum_Value_Node $node) use ($context): void {
            $this->is_valid_value_node($context, $node);
        }, Node_Kind::INT => function (Int_Value_Node $node) use ($context): void {
            $this->is_valid_value_node($context, $node);
        }, Node_Kind::FLOAT => function (Float_Value_Node $node) use ($context): void {
            $this->is_valid_value_node($context, $node);
        }, Node_Kind::STRING => function (String_Value_Node $node) use ($context): void {
            $this->is_valid_value_node($context, $node);
        }, Node_Kind::BOOLEAN => function (Boolean_Value_Node $node) use ($context): void {
            $this->is_valid_value_node($context, $node);
        }];
    }
    /**
     * @param VariableNode|NullValueNode|IntValueNode|FloatValueNode|StringValueNode|BooleanValueNode|EnumValueNode|ListValueNode|ObjectValueNode $node
     *
     * @throws \JsonException
     */
    protected function is_valid_value_node(Query_Validation_Context $context, Value_Node $node): void
    {
        // Report any error at the full type expected by the location.
        $location_type = $context->get_input_type();
        if ($location_type === null) {
            return;
        }
        $type = Type::get_named_type($location_type);
        if (!$type instanceof Leaf_Type) {
            $type_str = Utils::print_safe($type);
            $node_str = Printer::do_print($node);
            $context->report_error(new Error("Expected value of type \"{$type_str}\", found {$node_str}.", $node));
            return;
        }
        // Scalars determine if a literal value is valid via parseLiteral() which
        // may throw to indicate failure.
        try {
            $type->parse_literal($node);
        } catch (\Throwable $error) {
            if ($error instanceof Error) {
                $context->report_error($error);
            } else {
                $type_str = Utils::print_safe($type);
                $node_str = Printer::do_print($node);
                $context->report_error(new Error("Expected value of type \"{$type_str}\", found {$node_str}; {$error->get_message()}", $node, null, [], null, $error));
            }
        }
    }
}
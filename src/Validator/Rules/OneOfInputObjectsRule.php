<?php

declare (strict_types=1);
namespace Graph_Ql\Validator\Rules;

use Graph_Ql\Error\Error;
use Graph_Ql\Language\AST\Node_Kind;
use Graph_Ql\Language\AST\Object_Value_Node;
use Graph_Ql\Type\Definition\Input_Object_Type;
use Graph_Ql\Type\Definition\Type;
use Graph_Ql\Validator\Query_Validation_Context;
/**
 * OneOf Input Objects validation rule.
 *
 * Validates that OneOf Input Objects have exactly one non-null field provided.
 */
class One_Of_Input_Objects_Rule extends Validation_Rule
{
    public function get_visitor(Query_Validation_Context $context): array
    {
        return [Node_Kind::OBJECT => static function (Object_Value_Node $node) use ($context): void {
            $type = $context->get_input_type();
            if ($type === null) {
                return;
            }
            $named_type = Type::get_named_type($type);
            if (!$named_type instanceof Input_Object_Type || !$named_type->is_one_of()) {
                return;
            }
            $provided_fields = [];
            $null_fields = [];
            foreach ($node->fields as $field_node) {
                $field_name = $field_node->name->value;
                $provided_fields[] = $field_name;
                // Check if the field value is explicitly null
                if ($field_node->value->kind === Node_Kind::NULL) {
                    $null_fields[] = $field_name;
                }
            }
            $field_count = count($provided_fields);
            if ($field_count === 0) {
                $context->report_error(new Error(static::one_of_input_object_expected_exactly_one_field_message($named_type->name), [$node]));
                return;
            }
            if ($field_count > 1) {
                $context->report_error(new Error(static::one_of_input_object_expected_exactly_one_field_message($named_type->name, $field_count), [$node]));
                return;
            }
            // At this point, $fieldCount === 1
            if (count($null_fields) > 0) {
                // Exactly one field provided, but it's null
                $context->report_error(new Error(static::one_of_input_object_field_value_must_not_be_null_message($named_type->name, $null_fields[0]), [$node]));
            }
        }];
    }
    public static function one_of_input_object_expected_exactly_one_field_message(string $type_name, ?int $provided_count = null): string
    {
        if ($provided_count === null) {
            return "OneOf input object '{$type_name}' must specify exactly one field.";
        }
        return "OneOf input object '{$type_name}' must specify exactly one field, but {$provided_count} fields were provided.";
    }
    public static function one_of_input_object_field_value_must_not_be_null_message(string $type_name, string $field_name): string
    {
        return "OneOf input object '{$type_name}' field '{$field_name}' must be non-null.";
    }
}
<?php

declare (strict_types=1);
namespace Graph_Ql\Validator\Rules;

use Graph_Ql\Error\Error;
use Graph_Ql\Error\Invariant_Violation;
use Graph_Ql\Language\AST\Input_Object_Type_Definition_Node;
use Graph_Ql\Language\AST\Input_Object_Type_Extension_Node;
use Graph_Ql\Language\AST\Interface_Type_Definition_Node;
use Graph_Ql\Language\AST\Interface_Type_Extension_Node;
use Graph_Ql\Language\AST\Name_Node;
use Graph_Ql\Language\AST\Node_Kind;
use Graph_Ql\Language\AST\Object_Type_Definition_Node;
use Graph_Ql\Language\AST\Object_Type_Extension_Node;
use Graph_Ql\Language\Visitor;
use Graph_Ql\Language\Visitor_Operation;
use Graph_Ql\Type\Definition\Input_Object_Type;
use Graph_Ql\Type\Definition\Interface_Type;
use Graph_Ql\Type\Definition\Named_Type;
use Graph_Ql\Type\Definition\Object_Type;
use Graph_Ql\Validator\Sdl_Validation_Context;
/**
 * Unique field definition names.
 *
 * A GraphQL complex type is only valid if all its fields are uniquely named.
 */
class Unique_Field_Definition_Names extends Validation_Rule
{
    public function get_sdl_visitor(Sdl_Validation_Context $context): array
    {
        $schema = $context->get_schema();
        /** @var array<string, array<int, NameNode>> $knownFieldNames */
        $known_field_names = [];
        $check_field_uniqueness = static function ($node) use ($context, $schema, &$known_field_names): Visitor_Operation {
            assert($node instanceof Input_Object_Type_Definition_Node || $node instanceof Input_Object_Type_Extension_Node || $node instanceof Interface_Type_Definition_Node || $node instanceof Interface_Type_Extension_Node || $node instanceof Object_Type_Definition_Node || $node instanceof Object_Type_Extension_Node);
            $type_name = $node->name->value;
            $known_field_names[$type_name] ??= [];
            $field_names =& $known_field_names[$type_name];
            foreach ($node->fields as $field_def) {
                $field_name = $field_def->name->value;
                $existing_type = $schema !== null ? $schema->get_type($type_name) : null;
                if (self::has_field($existing_type, $field_name)) {
                    $context->report_error(new Error("Field \"{$type_name}.{$field_name}\" already exists in the schema. It cannot also be defined in this type extension.", $field_def->name));
                } elseif (isset($field_names[$field_name])) {
                    $context->report_error(new Error("Field \"{$type_name}.{$field_name}\" can only be defined once.", [$field_names[$field_name], $field_def->name]));
                } else {
                    $field_names[$field_name] = $field_def->name;
                }
            }
            return Visitor::skip_node();
        };
        return [Node_Kind::INPUT_OBJECT_TYPE_DEFINITION => $check_field_uniqueness, Node_Kind::INPUT_OBJECT_TYPE_EXTENSION => $check_field_uniqueness, Node_Kind::INTERFACE_TYPE_DEFINITION => $check_field_uniqueness, Node_Kind::INTERFACE_TYPE_EXTENSION => $check_field_uniqueness, Node_Kind::OBJECT_TYPE_DEFINITION => $check_field_uniqueness, Node_Kind::OBJECT_TYPE_EXTENSION => $check_field_uniqueness];
    }
    /** @throws InvariantViolation */
    private static function has_field(?Named_Type $type, string $field_name): bool
    {
        if ($type instanceof Object_Type || $type instanceof Interface_Type || $type instanceof Input_Object_Type) {
            return $type->has_field($field_name);
        }
        return false;
    }
}
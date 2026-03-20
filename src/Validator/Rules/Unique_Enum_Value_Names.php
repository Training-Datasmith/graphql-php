<?php

declare (strict_types=1);
namespace Graph_Ql\Validator\Rules;

use Graph_Ql\Error\Error;
use Graph_Ql\Language\AST\Enum_Type_Definition_Node;
use Graph_Ql\Language\AST\Enum_Type_Extension_Node;
use Graph_Ql\Language\AST\Enum_Value_Node;
use Graph_Ql\Language\AST\Node_Kind;
use Graph_Ql\Language\Visitor;
use Graph_Ql\Language\Visitor_Operation;
use Graph_Ql\Type\Definition\Enum_Type;
use Graph_Ql\Validator\Sdl_Validation_Context;
class Unique_Enum_Value_Names extends Validation_Rule
{
    public function get_sdl_visitor(Sdl_Validation_Context $context): array
    {
        /** @var array<string, array<string, EnumValueNode>> $knownValueNames */
        $known_value_names = [];
        /**
         * @param EnumTypeDefinitionNode|EnumTypeExtensionNode $enum
         */
        $check_value_uniqueness = static function ($enum) use ($context, &$known_value_names): Visitor_Operation {
            $type_name = $enum->name->value;
            $schema = $context->get_schema();
            $existing_type = $schema !== null ? $schema->get_type($type_name) : null;
            $value_nodes = $enum->values;
            if (!isset($known_value_names[$type_name])) {
                $known_value_names[$type_name] = [];
            }
            $value_names =& $known_value_names[$type_name];
            foreach ($value_nodes as $value_def) {
                $value_name_node = $value_def->name;
                $value_name = $value_name_node->value;
                if ($existing_type instanceof Enum_Type && $existing_type->get_value($value_name) !== null) {
                    $context->report_error(new Error("Enum value \"{$type_name}.{$value_name}\" already exists in the schema. It cannot also be defined in this type extension.", $value_name_node));
                } elseif (isset($value_names[$value_name])) {
                    $context->report_error(new Error("Enum value \"{$type_name}.{$value_name}\" can only be defined once.", [$value_names[$value_name], $value_name_node]));
                } else {
                    $value_names[$value_name] = $value_name_node;
                }
            }
            return Visitor::skip_node();
        };
        return [Node_Kind::ENUM_TYPE_DEFINITION => $check_value_uniqueness, Node_Kind::ENUM_TYPE_EXTENSION => $check_value_uniqueness];
    }
}
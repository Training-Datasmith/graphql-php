<?php

declare (strict_types=1);
namespace Graph_Ql\Validator\Rules;

use Graph_Ql\Error\Error;
use Graph_Ql\Language\AST\Name_Node;
use Graph_Ql\Language\AST\Node_Kind;
use Graph_Ql\Language\Visitor;
use Graph_Ql\Language\Visitor_Operation;
use Graph_Ql\Validator\Sdl_Validation_Context;
/**
 * Unique type names.
 *
 * A GraphQL document is only valid if all defined types have unique names.
 */
class Unique_Type_Names extends Validation_Rule
{
    public function get_sdl_visitor(Sdl_Validation_Context $context): array
    {
        $schema = $context->get_schema();
        /** @var array<string, NameNode> $knownTypeNames */
        $known_type_names = [];
        $check_type_name = static function ($node) use ($context, $schema, &$known_type_names): ?Visitor_Operation {
            $type_name = $node->name->value;
            if ($schema !== null && $schema->get_type($type_name) !== null) {
                $context->report_error(new Error("Type \"{$type_name}\" already exists in the schema. It cannot also be defined in this type definition.", $node->name));
                return null;
            }
            if (array_key_exists($type_name, $known_type_names)) {
                $context->report_error(new Error("There can be only one type named \"{$type_name}\".", [$known_type_names[$type_name], $node->name]));
            } else {
                $known_type_names[$type_name] = $node->name;
            }
            return Visitor::skip_node();
        };
        return [Node_Kind::SCALAR_TYPE_DEFINITION => $check_type_name, Node_Kind::OBJECT_TYPE_DEFINITION => $check_type_name, Node_Kind::INTERFACE_TYPE_DEFINITION => $check_type_name, Node_Kind::UNION_TYPE_DEFINITION => $check_type_name, Node_Kind::ENUM_TYPE_DEFINITION => $check_type_name, Node_Kind::INPUT_OBJECT_TYPE_DEFINITION => $check_type_name];
    }
}
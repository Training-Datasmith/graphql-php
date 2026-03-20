<?php

declare (strict_types=1);
namespace Graph_Ql\Validator\Rules;

use Graph_Ql\Error\Error;
use Graph_Ql\Error\Invariant_Violation;
use Graph_Ql\Language\AST\Node;
use Graph_Ql\Language\AST\Node_Kind;
use Graph_Ql\Language\AST\Type_Definition_Node;
use Graph_Ql\Language\Visitor_Operation;
use Graph_Ql\Type\Definition\Enum_Type;
use Graph_Ql\Type\Definition\Input_Object_Type;
use Graph_Ql\Type\Definition\Interface_Type;
use Graph_Ql\Type\Definition\Named_Type;
use Graph_Ql\Type\Definition\Object_Type;
use Graph_Ql\Type\Definition\Scalar_Type;
use Graph_Ql\Type\Definition\Union_Type;
use Graph_Ql\Utils\Utils;
use Graph_Ql\Validator\Sdl_Validation_Context;
/**
 * Possible type extensions.
 *
 * A type extension is only valid if the type is defined and has the same kind.
 */
class Possible_Type_Extensions extends Validation_Rule
{
    public function get_sdl_visitor(Sdl_Validation_Context $context): array
    {
        $schema = $context->get_schema();
        /** @var array<string, TypeDefinitionNode&Node> $definedTypes */
        $defined_types = [];
        foreach ($context->get_document()->definitions as $def) {
            if ($def instanceof Type_Definition_Node) {
                $name = $def->get_name()->value;
                $defined_types[$name] = $def;
            }
        }
        $check_type_extension = static function ($node) use ($context, $schema, &$defined_types): ?Visitor_Operation {
            $type_name = $node->name->value;
            $def_node = $defined_types[$type_name] ?? null;
            $existing_type = $schema !== null ? $schema->get_type($type_name) : null;
            $expected_kind = null;
            if ($def_node !== null) {
                $expected_kind = self::def_kind_to_ext_kind($def_node->kind);
            } elseif ($existing_type !== null) {
                $expected_kind = self::type_to_ext_kind($existing_type);
            }
            if ($expected_kind !== null) {
                if ($expected_kind !== $node->kind) {
                    $kind_str = self::extension_kind_to_type_name($node->kind);
                    $context->report_error(new Error("Cannot extend non-{$kind_str} type \"{$type_name}\".", $def_node !== null ? [$def_node, $node] : $node));
                }
            } else {
                $existing_types_map = $schema !== null ? $schema->get_type_map() : [];
                $all_type_names = [...array_keys($defined_types), ...array_keys($existing_types_map)];
                $suggested_types = Utils::suggestion_list($type_name, $all_type_names);
                $did_you_mean = $suggested_types === [] ? '' : ' Did you mean ' . Utils::quoted_or_list($suggested_types) . '?';
                $context->report_error(new Error("Cannot extend type \"{$type_name}\" because it is not defined.{$did_you_mean}", $node->name));
            }
            return null;
        };
        return [Node_Kind::SCALAR_TYPE_EXTENSION => $check_type_extension, Node_Kind::OBJECT_TYPE_EXTENSION => $check_type_extension, Node_Kind::INTERFACE_TYPE_EXTENSION => $check_type_extension, Node_Kind::UNION_TYPE_EXTENSION => $check_type_extension, Node_Kind::ENUM_TYPE_EXTENSION => $check_type_extension, Node_Kind::INPUT_OBJECT_TYPE_EXTENSION => $check_type_extension];
    }
    /** @throws InvariantViolation */
    private static function def_kind_to_ext_kind(string $kind): string
    {
        switch ($kind) {
            case Node_Kind::SCALAR_TYPE_DEFINITION:
                return Node_Kind::SCALAR_TYPE_EXTENSION;
            case Node_Kind::OBJECT_TYPE_DEFINITION:
                return Node_Kind::OBJECT_TYPE_EXTENSION;
            case Node_Kind::INTERFACE_TYPE_DEFINITION:
                return Node_Kind::INTERFACE_TYPE_EXTENSION;
            case Node_Kind::UNION_TYPE_DEFINITION:
                return Node_Kind::UNION_TYPE_EXTENSION;
            case Node_Kind::ENUM_TYPE_DEFINITION:
                return Node_Kind::ENUM_TYPE_EXTENSION;
            case Node_Kind::INPUT_OBJECT_TYPE_DEFINITION:
                return Node_Kind::INPUT_OBJECT_TYPE_EXTENSION;
            default:
                throw new Invariant_Violation("Unexpected definition kind: {$kind}.");
        }
    }
    /** @throws InvariantViolation */
    private static function type_to_ext_kind(Named_Type $type): string
    {
        switch (true) {
            case $type instanceof Scalar_Type:
                return Node_Kind::SCALAR_TYPE_EXTENSION;
            case $type instanceof Object_Type:
                return Node_Kind::OBJECT_TYPE_EXTENSION;
            case $type instanceof Interface_Type:
                return Node_Kind::INTERFACE_TYPE_EXTENSION;
            case $type instanceof Union_Type:
                return Node_Kind::UNION_TYPE_EXTENSION;
            case $type instanceof Enum_Type:
                return Node_Kind::ENUM_TYPE_EXTENSION;
            case $type instanceof Input_Object_Type:
                return Node_Kind::INPUT_OBJECT_TYPE_EXTENSION;
            default:
                $unexpected_type = Utils::print_safe($type);
                throw new Invariant_Violation("Unexpected type: {$unexpected_type}.");
        }
    }
    /** @throws InvariantViolation */
    private static function extension_kind_to_type_name(string $kind): string
    {
        switch ($kind) {
            case Node_Kind::SCALAR_TYPE_EXTENSION:
                return 'scalar';
            case Node_Kind::OBJECT_TYPE_EXTENSION:
                return 'object';
            case Node_Kind::INTERFACE_TYPE_EXTENSION:
                return 'interface';
            case Node_Kind::UNION_TYPE_EXTENSION:
                return 'union';
            case Node_Kind::ENUM_TYPE_EXTENSION:
                return 'enum';
            case Node_Kind::INPUT_OBJECT_TYPE_EXTENSION:
                return 'input object';
            default:
                throw new Invariant_Violation("Unexpected extension kind: {$kind}.");
        }
    }
}
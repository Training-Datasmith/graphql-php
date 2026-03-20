<?php

declare (strict_types=1);
namespace Graph_Ql\Validator\Rules;

use Graph_Ql\Error\Error;
use Graph_Ql\Language\AST\Named_Type_Node;
use Graph_Ql\Language\AST\Node_Kind;
use Graph_Ql\Language\AST\Type_Definition_Node;
use Graph_Ql\Language\AST\Type_System_Definition_Node;
use Graph_Ql\Language\AST\Type_System_Extension_Node;
use Graph_Ql\Type\Definition\Type;
use Graph_Ql\Utils\Utils;
use Graph_Ql\Validator\Query_Validation_Context;
use Graph_Ql\Validator\Sdl_Validation_Context;
use Graph_Ql\Validator\Validation_Context;
/**
 * Known type names.
 *
 * A GraphQL document is only valid if referenced types (specifically
 * variable definitions and fragment conditions) are defined by the type schema.
 *
 * @phpstan-import-type VisitorArray from \GraphQL\Language\Visitor
 */
class Known_Type_Names extends Validation_Rule
{
    public function get_visitor(Query_Validation_Context $context): array
    {
        return $this->get_ast_visitor($context);
    }
    public function get_sdl_visitor(Sdl_Validation_Context $context): array
    {
        return $this->get_ast_visitor($context);
    }
    /** @phpstan-return VisitorArray */
    public function get_ast_visitor(Validation_Context $context): array
    {
        /** @var array<int, string> $definedTypes */
        $defined_types = [];
        foreach ($context->get_document()->definitions as $def) {
            if ($def instanceof Type_Definition_Node) {
                $defined_types[] = $def->get_name()->value;
            }
        }
        return [Node_Kind::NAMED_TYPE => static function (Named_Type_Node $node, $_1, $parent, $_2, $ancestors) use ($context, $defined_types): void {
            $type_name = $node->name->value;
            $schema = $context->get_schema();
            if (in_array($type_name, $defined_types, true)) {
                return;
            }
            if ($schema !== null && $schema->has_type($type_name)) {
                return;
            }
            $definition_node = $ancestors[2] ?? $parent;
            $is_sdl = $definition_node instanceof Type_System_Definition_Node || $definition_node instanceof Type_System_Extension_Node;
            if ($is_sdl && in_array($type_name, Type::BUILT_IN_TYPE_NAMES, true)) {
                return;
            }
            $existing_types_map = $schema !== null ? $schema->get_type_map() : [];
            $type_names = [...array_keys($existing_types_map), ...$defined_types];
            $context->report_error(new Error(static::unknown_type_message($type_name, Utils::suggestion_list($type_name, $is_sdl ? [...Type::BUILT_IN_TYPE_NAMES, ...$type_names] : $type_names)), [$node]));
        }];
    }
    /** @param array<string> $suggestedTypes */
    public static function unknown_type_message(string $type, array $suggested_types): string
    {
        $message = "Unknown type \"{$type}\".";
        if ($suggested_types !== []) {
            $suggestion_list = Utils::quoted_or_list($suggested_types);
            $message .= " Did you mean {$suggestion_list}?";
        }
        return $message;
    }
}
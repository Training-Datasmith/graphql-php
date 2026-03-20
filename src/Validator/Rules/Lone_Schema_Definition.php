<?php

declare (strict_types=1);
namespace Graph_Ql\Validator\Rules;

use Graph_Ql\Error\Error;
use Graph_Ql\Language\AST\Node_Kind;
use Graph_Ql\Language\AST\Schema_Definition_Node;
use Graph_Ql\Validator\Sdl_Validation_Context;
/**
 * Lone schema definition.
 *
 * A GraphQL document is only valid if it contains only one schema definition.
 */
class Lone_Schema_Definition extends Validation_Rule
{
    public static function schema_definition_not_alone_message(): string
    {
        return 'Must provide only one schema definition.';
    }
    public static function can_not_define_schema_within_extension_message(): string
    {
        return 'Cannot define a new schema within a schema extension.';
    }
    public function get_sdl_visitor(Sdl_Validation_Context $context): array
    {
        $old_schema = $context->get_schema();
        $already_defined = $old_schema === null ? false : $old_schema->ast_node !== null || $old_schema->get_query_type() !== null || $old_schema->get_mutation_type() !== null || $old_schema->get_subscription_type() !== null;
        $schema_definitions_count = 0;
        return [Node_Kind::SCHEMA_DEFINITION => static function (Schema_Definition_Node $node) use ($already_defined, $context, &$schema_definitions_count): void {
            if ($already_defined) {
                $context->report_error(new Error(static::can_not_define_schema_within_extension_message(), $node));
                return;
            }
            if ($schema_definitions_count > 0) {
                $context->report_error(new Error(static::schema_definition_not_alone_message(), $node));
            }
            ++$schema_definitions_count;
        }];
    }
}
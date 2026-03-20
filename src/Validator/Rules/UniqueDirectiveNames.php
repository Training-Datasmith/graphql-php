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
 * Unique directive names.
 *
 * A GraphQL document is only valid if all defined directives have unique names.
 */
class Unique_Directive_Names extends Validation_Rule
{
    public function get_sdl_visitor(Sdl_Validation_Context $context): array
    {
        $schema = $context->get_schema();
        /** @var array<string, NameNode> $knownDirectiveNames */
        $known_directive_names = [];
        return [Node_Kind::DIRECTIVE_DEFINITION => static function ($node) use ($context, $schema, &$known_directive_names): ?Visitor_Operation {
            $directive_name = $node->name->value;
            if ($schema !== null && $schema->get_directive($directive_name) !== null) {
                $context->report_error(new Error('Directive "@' . $directive_name . '" already exists in the schema. It cannot be redefined.', $node->name));
                return null;
            }
            if (isset($known_directive_names[$directive_name])) {
                $context->report_error(new Error('There can be only one directive named "@' . $directive_name . '".', [$known_directive_names[$directive_name], $node->name]));
            } else {
                $known_directive_names[$directive_name] = $node->name;
            }
            return Visitor::skip_node();
        }];
    }
}
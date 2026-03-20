<?php

declare (strict_types=1);
namespace Graph_Ql\Validator\Rules;

use Graph_Ql\Error\Error;
use Graph_Ql\Error\Invariant_Violation;
use Graph_Ql\Language\AST\Directive_Definition_Node;
use Graph_Ql\Language\AST\Node;
use Graph_Ql\Language\Visitor;
use Graph_Ql\Type\Definition\Directive;
use Graph_Ql\Validator\Query_Validation_Context;
use Graph_Ql\Validator\Sdl_Validation_Context;
use Graph_Ql\Validator\Validation_Context;
/**
 * Unique directive names per location.
 *
 * A GraphQL document is only valid if all non-repeatable directives at
 * a given location are uniquely named.
 *
 * @phpstan-import-type VisitorArray from Visitor
 */
class Unique_Directives_Per_Location extends Validation_Rule
{
    /** @throws InvariantViolation */
    public function get_visitor(Query_Validation_Context $context): array
    {
        return $this->get_ast_visitor($context);
    }
    /** @throws InvariantViolation */
    public function get_sdl_visitor(Sdl_Validation_Context $context): array
    {
        return $this->get_ast_visitor($context);
    }
    /**
     * @throws InvariantViolation
     *
     * @phpstan-return VisitorArray
     */
    public function get_ast_visitor(Validation_Context $context): array
    {
        /** @var array<string, true> $uniqueDirectiveMap */
        $unique_directive_map = [];
        $schema = $context->get_schema();
        $defined_directives = $schema !== null ? $schema->get_directives() : Directive::get_internal_directives();
        foreach ($defined_directives as $directive) {
            if (!$directive->is_repeatable) {
                $unique_directive_map[$directive->name] = true;
            }
        }
        $ast_definitions = $context->get_document()->definitions;
        foreach ($ast_definitions as $definition) {
            if ($definition instanceof Directive_Definition_Node && !$definition->repeatable) {
                $unique_directive_map[$definition->name->value] = true;
            }
        }
        return ['enter' => static function (Node $node) use ($unique_directive_map, $context): void {
            if (!property_exists($node, 'directives')) {
                return;
            }
            $known_directives = [];
            foreach ($node->directives as $directive) {
                $directive_name = $directive->name->value;
                if (isset($unique_directive_map[$directive_name])) {
                    if (isset($known_directives[$directive_name])) {
                        $context->report_error(new Error(static::duplicate_directive_message($directive_name), [$known_directives[$directive_name], $directive]));
                    } else {
                        $known_directives[$directive_name] = $directive;
                    }
                }
            }
        }];
    }
    public static function duplicate_directive_message(string $directive_name): string
    {
        return "The directive \"{$directive_name}\" can only be used once at this location.";
    }
}
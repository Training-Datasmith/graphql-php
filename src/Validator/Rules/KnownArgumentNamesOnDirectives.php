<?php

declare (strict_types=1);
namespace Graph_Ql\Validator\Rules;

use Graph_Ql\Error\Error;
use Graph_Ql\Error\Invariant_Violation;
use Graph_Ql\Language\AST\Directive_Definition_Node;
use Graph_Ql\Language\AST\Directive_Node;
use Graph_Ql\Language\AST\Node_Kind;
use Graph_Ql\Language\Visitor;
use Graph_Ql\Language\Visitor_Operation;
use Graph_Ql\Type\Definition\Argument;
use Graph_Ql\Type\Definition\Directive;
use Graph_Ql\Utils\Utils;
use Graph_Ql\Validator\Query_Validation_Context;
use Graph_Ql\Validator\Sdl_Validation_Context;
use Graph_Ql\Validator\Validation_Context;
/**
 * Known argument names on directives.
 *
 * A GraphQL directive is only valid if all supplied arguments are defined by
 * that field.
 *
 * @phpstan-import-type VisitorArray from Visitor
 */
class Known_Argument_Names_On_Directives extends Validation_Rule
{
    /** @param array<string> $suggestedArgs */
    public static function unknown_directive_arg_message(string $arg_name, string $directive_name, array $suggested_args): string
    {
        $message = "Unknown argument \"{$arg_name}\" on directive \"@{$directive_name}\".";
        if (isset($suggested_args[0])) {
            $suggestions = Utils::quoted_or_list($suggested_args);
            $message .= " Did you mean {$suggestions}?";
        }
        return $message;
    }
    /** @throws InvariantViolation */
    public function get_sdl_visitor(Sdl_Validation_Context $context): array
    {
        return $this->get_ast_visitor($context);
    }
    /** @throws InvariantViolation */
    public function get_visitor(Query_Validation_Context $context): array
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
        $directive_args = [];
        $schema = $context->get_schema();
        $defined_directives = $schema !== null ? $schema->get_directives() : Directive::get_internal_directives();
        foreach ($defined_directives as $directive) {
            $directive_args[$directive->name] = array_map(static fn(Argument $arg): string => $arg->name, $directive->args);
        }
        $ast_definitions = $context->get_document()->definitions;
        foreach ($ast_definitions as $def) {
            if ($def instanceof Directive_Definition_Node) {
                $arg_names = [];
                foreach ($def->arguments as $arg) {
                    $arg_names[] = $arg->name->value;
                }
                $directive_args[$def->name->value] = $arg_names;
            }
        }
        return [Node_Kind::DIRECTIVE => static function (Directive_Node $directive_node) use ($directive_args, $context): Visitor_Operation {
            $directive_name = $directive_node->name->value;
            if (!isset($directive_args[$directive_name])) {
                return Visitor::skip_node();
            }
            $known_args = $directive_args[$directive_name];
            foreach ($directive_node->arguments as $arg_node) {
                $arg_name = $arg_node->name->value;
                if (!in_array($arg_name, $known_args, true)) {
                    $suggestions = Utils::suggestion_list($arg_name, $known_args);
                    $context->report_error(new Error(static::unknown_directive_arg_message($arg_name, $directive_name, $suggestions), [$arg_node]));
                }
            }
            return Visitor::skip_node();
        }];
    }
}
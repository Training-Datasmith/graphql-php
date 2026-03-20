<?php

declare (strict_types=1);
namespace Graph_Ql\Validator\Rules;

use Graph_Ql\Error\Error;
use Graph_Ql\Error\Invariant_Violation;
use Graph_Ql\Language\AST\Directive_Definition_Node;
use Graph_Ql\Language\AST\Directive_Node;
use Graph_Ql\Language\AST\Node_Kind;
use Graph_Ql\Language\AST\Non_Null_Type_Node;
use Graph_Ql\Language\Printer;
use Graph_Ql\Language\Visitor;
use Graph_Ql\Type\Definition\Argument;
use Graph_Ql\Type\Definition\Directive;
use Graph_Ql\Validator\Query_Validation_Context;
use Graph_Ql\Validator\Sdl_Validation_Context;
use Graph_Ql\Validator\Validation_Context;
/**
 * Provided required arguments on directives.
 *
 * A directive is only valid if all required (non-null without a
 * default value) field arguments have been provided.
 *
 * @phpstan-import-type VisitorArray from Visitor
 */
class Provided_Required_Arguments_On_Directives extends Validation_Rule
{
    public static function missing_directive_arg_message(string $directive_name, string $arg_name, string $type): string
    {
        return "Directive \"@{$directive_name}\" argument \"{$arg_name}\" of type \"{$type}\" is required but not provided.";
    }
    /** @throws \Exception */
    public function get_sdl_visitor(Sdl_Validation_Context $context): array
    {
        return $this->get_ast_visitor($context);
    }
    /** @throws \Exception */
    public function get_visitor(Query_Validation_Context $context): array
    {
        return $this->get_ast_visitor($context);
    }
    /**
     * @throws \Exception
     * @throws \InvalidArgumentException
     * @throws \ReflectionException
     * @throws Error
     * @throws InvariantViolation
     *
     * @phpstan-return VisitorArray
     */
    public function get_ast_visitor(Validation_Context $context): array
    {
        $required_args_map = [];
        $schema = $context->get_schema();
        $defined_directives = $schema === null ? Directive::get_internal_directives() : $schema->get_directives();
        foreach ($defined_directives as $directive) {
            $directive_args = [];
            foreach ($directive->args as $arg) {
                if ($arg->is_required()) {
                    $directive_args[$arg->name] = $arg;
                }
            }
            $required_args_map[$directive->name] = $directive_args;
        }
        $ast_definition = $context->get_document()->definitions;
        foreach ($ast_definition as $def) {
            if ($def instanceof Directive_Definition_Node) {
                $arguments = $def->arguments;
                $required_args = [];
                foreach ($arguments as $argument) {
                    if ($argument->type instanceof Non_Null_Type_Node && !isset($argument->default_value)) {
                        $required_args[$argument->name->value] = $argument;
                    }
                }
                $required_args_map[$def->name->value] = $required_args;
            }
        }
        return [Node_Kind::DIRECTIVE => [
            // Validate on leave to allow for deeper errors to appear first.
            'leave' => static function (Directive_Node $directive_node) use ($required_args_map, $context): ?string {
                $directive_name = $directive_node->name->value;
                $required_args = $required_args_map[$directive_name] ?? null;
                if ($required_args === null || $required_args === []) {
                    return null;
                }
                $arg_node_map = [];
                foreach ($directive_node->arguments as $arg) {
                    $arg_node_map[$arg->name->value] = $arg;
                }
                foreach ($required_args as $arg_name => $arg) {
                    if (!isset($arg_node_map[$arg_name])) {
                        $arg_type = $arg instanceof Argument ? $arg->get_type()->to_string() : Printer::do_print($arg->type);
                        $context->report_error(new Error(static::missing_directive_arg_message($directive_name, $arg_name, $arg_type), [$directive_node]));
                    }
                }
                return null;
            },
        ]];
    }
}
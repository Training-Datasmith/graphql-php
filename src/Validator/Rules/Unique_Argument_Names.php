<?php

declare (strict_types=1);
namespace Graph_Ql\Validator\Rules;

use Graph_Ql\Error\Error;
use Graph_Ql\Language\AST\Argument_Node;
use Graph_Ql\Language\AST\Name_Node;
use Graph_Ql\Language\AST\Node_Kind;
use Graph_Ql\Language\Visitor;
use Graph_Ql\Language\Visitor_Operation;
use Graph_Ql\Validator\Query_Validation_Context;
use Graph_Ql\Validator\Sdl_Validation_Context;
use Graph_Ql\Validator\Validation_Context;
/**
 * @phpstan-import-type VisitorArray from Visitor
 */
class Unique_Argument_Names extends Validation_Rule
{
    /** @var array<string, NameNode> */
    protected array $known_arg_names;
    public function get_sdl_visitor(Sdl_Validation_Context $context): array
    {
        return $this->get_ast_visitor($context);
    }
    public function get_visitor(Query_Validation_Context $context): array
    {
        return $this->get_ast_visitor($context);
    }
    /** @phpstan-return VisitorArray */
    public function get_ast_visitor(Validation_Context $context): array
    {
        $this->known_arg_names = [];
        return [Node_Kind::FIELD => function (): void {
            $this->known_arg_names = [];
        }, Node_Kind::DIRECTIVE => function (): void {
            $this->known_arg_names = [];
        }, Node_Kind::ARGUMENT => function (Argument_Node $node) use ($context): Visitor_Operation {
            $arg_name = $node->name->value;
            if (isset($this->known_arg_names[$arg_name])) {
                $context->report_error(new Error(static::duplicate_arg_message($arg_name), [$this->known_arg_names[$arg_name], $node->name]));
            } else {
                $this->known_arg_names[$arg_name] = $node->name;
            }
            return Visitor::skip_node();
        }];
    }
    public static function duplicate_arg_message(string $arg_name): string
    {
        return "There can be only one argument named \"{$arg_name}\".";
    }
}
<?php

declare (strict_types=1);
namespace Graph_Ql\Validator\Rules;

use Graph_Ql\Error\Error;
use Graph_Ql\Language\AST\Name_Node;
use Graph_Ql\Language\AST\Node_Kind;
use Graph_Ql\Language\AST\Object_Field_Node;
use Graph_Ql\Language\Visitor;
use Graph_Ql\Language\Visitor_Operation;
use Graph_Ql\Validator\Query_Validation_Context;
use Graph_Ql\Validator\Sdl_Validation_Context;
use Graph_Ql\Validator\Validation_Context;
/**
 * @phpstan-import-type VisitorArray from Visitor
 */
class Unique_Input_Field_Names extends Validation_Rule
{
    /** @var array<string, NameNode> */
    protected array $known_names;
    /** @var array<array<string, NameNode>> */
    protected array $known_name_stack;
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
        $this->known_names = [];
        $this->known_name_stack = [];
        return [Node_Kind::OBJECT => ['enter' => function (): void {
            $this->known_name_stack[] = $this->known_names;
            $this->known_names = [];
        }, 'leave' => function (): void {
            $known_names = array_pop($this->known_name_stack);
            assert(is_array($known_names), 'should not happen if the visitor works correctly');
            $this->known_names = $known_names;
        }], Node_Kind::OBJECT_FIELD => function (Object_Field_Node $node) use ($context): Visitor_Operation {
            $field_name = $node->name->value;
            if (isset($this->known_names[$field_name])) {
                $context->report_error(new Error(static::duplicate_input_field_message($field_name), [$this->known_names[$field_name], $node->name]));
            } else {
                $this->known_names[$field_name] = $node->name;
            }
            return Visitor::skip_node();
        }];
    }
    public static function duplicate_input_field_message(string $field_name): string
    {
        return "There can be only one input field named \"{$field_name}\".";
    }
}
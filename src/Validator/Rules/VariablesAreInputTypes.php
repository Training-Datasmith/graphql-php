<?php

declare (strict_types=1);
namespace Graph_Ql\Validator\Rules;

use Graph_Ql\Error\Error;
use Graph_Ql\Language\AST\Node_Kind;
use Graph_Ql\Language\AST\Variable_Definition_Node;
use Graph_Ql\Language\Printer;
use Graph_Ql\Type\Definition\Type;
use Graph_Ql\Utils\AST;
use Graph_Ql\Validator\Query_Validation_Context;
class Variables_Are_Input_Types extends Validation_Rule
{
    public function get_visitor(Query_Validation_Context $context): array
    {
        return [Node_Kind::VARIABLE_DEFINITION => static function (Variable_Definition_Node $node) use ($context): void {
            $type = AST::type_from_ast([$context->get_schema(), 'getType'], $node->type);
            // If the variable type is not an input type, return an error.
            if ($type === null || Type::is_input_type($type)) {
                return;
            }
            $variable_name = $node->variable->name->value;
            $context->report_error(new Error(static::non_input_type_on_var_message($variable_name, Printer::do_print($node->type)), [$node->type]));
        }];
    }
    public static function non_input_type_on_var_message(string $variable_name, string $type_name): string
    {
        return "Variable \"\${$variable_name}\" cannot be non-input type \"{$type_name}\".";
    }
}
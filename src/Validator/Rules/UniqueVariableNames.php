<?php

declare (strict_types=1);
namespace Graph_Ql\Validator\Rules;

use Graph_Ql\Error\Error;
use Graph_Ql\Language\AST\Name_Node;
use Graph_Ql\Language\AST\Node_Kind;
use Graph_Ql\Language\AST\Variable_Definition_Node;
use Graph_Ql\Validator\Query_Validation_Context;
class Unique_Variable_Names extends Validation_Rule
{
    /** @var array<string, NameNode> */
    protected array $known_variable_names;
    public function get_visitor(Query_Validation_Context $context): array
    {
        $this->known_variable_names = [];
        return [Node_Kind::OPERATION_DEFINITION => function (): void {
            $this->known_variable_names = [];
        }, Node_Kind::VARIABLE_DEFINITION => function (Variable_Definition_Node $node) use ($context): void {
            $variable_name = $node->variable->name->value;
            if (!isset($this->known_variable_names[$variable_name])) {
                $this->known_variable_names[$variable_name] = $node->variable->name;
            } else {
                $context->report_error(new Error(static::duplicate_variable_message($variable_name), [$this->known_variable_names[$variable_name], $node->variable->name]));
            }
        }];
    }
    public static function duplicate_variable_message(string $variable_name): string
    {
        return "There can be only one variable named \"{$variable_name}\".";
    }
}
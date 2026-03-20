<?php

declare (strict_types=1);
namespace Graph_Ql\Validator\Rules;

use Graph_Ql\Error\Error;
use Graph_Ql\Language\AST\Node_Kind;
use Graph_Ql\Language\AST\Operation_Definition_Node;
use Graph_Ql\Language\AST\Variable_Definition_Node;
use Graph_Ql\Validator\Query_Validation_Context;
class No_Unused_Variables extends Validation_Rule
{
    /** @var array<int, VariableDefinitionNode> */
    protected array $variable_defs;
    public function get_visitor(Query_Validation_Context $context): array
    {
        $this->variable_defs = [];
        return [Node_Kind::OPERATION_DEFINITION => ['enter' => function (): void {
            $this->variable_defs = [];
        }, 'leave' => function (Operation_Definition_Node $operation) use ($context): void {
            $variable_name_used = [];
            $usages = $context->get_recursive_variable_usages($operation);
            $op_name = $operation->name !== null ? $operation->name->value : null;
            foreach ($usages as $usage) {
                $node = $usage['node'];
                $variable_name_used[$node->name->value] = true;
            }
            foreach ($this->variable_defs as $variable_def) {
                $variable_name = $variable_def->variable->name->value;
                if (!isset($variable_name_used[$variable_name])) {
                    $context->report_error(new Error(static::unused_variable_message($variable_name, $op_name), [$variable_def]));
                }
            }
        }], Node_Kind::VARIABLE_DEFINITION => function ($def): void {
            $this->variable_defs[] = $def;
        }];
    }
    public static function unused_variable_message(string $var_name, ?string $op_name = null): string
    {
        return $op_name !== null ? "Variable \"\${$var_name}\" is never used in operation \"{$op_name}\"." : "Variable \"\${$var_name}\" is never used.";
    }
}
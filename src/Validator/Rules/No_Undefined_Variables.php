<?php

declare (strict_types=1);
namespace Graph_Ql\Validator\Rules;

use Graph_Ql\Error\Error;
use Graph_Ql\Language\AST\Node_Kind;
use Graph_Ql\Language\AST\Operation_Definition_Node;
use Graph_Ql\Language\AST\Variable_Definition_Node;
use Graph_Ql\Validator\Query_Validation_Context;
/**
 * A GraphQL operation is only valid if all variables encountered, both directly
 * and via fragment spreads, are defined by that operation.
 */
class No_Undefined_Variables extends Validation_Rule
{
    public function get_visitor(Query_Validation_Context $context): array
    {
        /** @var array<string, true> $variableNameDefined */
        $variable_name_defined = [];
        return [Node_Kind::OPERATION_DEFINITION => ['enter' => static function () use (&$variable_name_defined): void {
            $variable_name_defined = [];
        }, 'leave' => static function (Operation_Definition_Node $operation) use (&$variable_name_defined, $context): void {
            $usages = $context->get_recursive_variable_usages($operation);
            foreach ($usages as $usage) {
                $node = $usage['node'];
                $var_name = $node->name->value;
                if (!isset($variable_name_defined[$var_name])) {
                    $context->report_error(new Error(static::undefined_var_message($var_name, $operation->name !== null ? $operation->name->value : null), [$node, $operation]));
                }
            }
        }], Node_Kind::VARIABLE_DEFINITION => static function (Variable_Definition_Node $def) use (&$variable_name_defined): void {
            $variable_name_defined[$def->variable->name->value] = true;
        }];
    }
    public static function undefined_var_message(string $var_name, ?string $op_name): string
    {
        return $op_name === null ? "Variable \"\${$var_name}\" is not defined by operation \"{$op_name}\"." : "Variable \"\${$var_name}\" is not defined.";
    }
}
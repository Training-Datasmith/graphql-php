<?php

declare (strict_types=1);
namespace Graph_Ql\Validator\Rules;

use Graph_Ql\Error\Error;
use Graph_Ql\Error\Invariant_Violation;
use Graph_Ql\Language\AST\Node_Kind;
use Graph_Ql\Language\AST\Null_Value_Node;
use Graph_Ql\Language\AST\Operation_Definition_Node;
use Graph_Ql\Language\AST\Value_Node;
use Graph_Ql\Language\AST\Variable_Definition_Node;
use Graph_Ql\Type\Definition\Non_Null;
use Graph_Ql\Type\Definition\Type;
use Graph_Ql\Type\Schema;
use Graph_Ql\Utils\AST;
use Graph_Ql\Utils\Type_Comparators;
use Graph_Ql\Utils\Utils;
use Graph_Ql\Validator\Query_Validation_Context;
class Variables_In_Allowed_Position extends Validation_Rule
{
    /**
     * A map from variable names to their definition nodes.
     *
     * @var array<string, VariableDefinitionNode>
     */
    protected array $var_def_map;
    public function get_visitor(Query_Validation_Context $context): array
    {
        return [Node_Kind::OPERATION_DEFINITION => ['enter' => function (): void {
            $this->var_def_map = [];
        }, 'leave' => function (Operation_Definition_Node $operation) use ($context): void {
            $usages = $context->get_recursive_variable_usages($operation);
            foreach ($usages as $usage) {
                $node = $usage['node'];
                $type = $usage['type'];
                $default_value = $usage['defaultValue'];
                $var_name = $node->name->value;
                $var_def = $this->var_def_map[$var_name] ?? null;
                if ($var_def === null) {
                    continue;
                }
                if ($type === null) {
                    continue;
                }
                // A var type is allowed if it is the same or more strict (e.g. is
                // a subtype of) than the expected type. It can be more strict if
                // the variable type is non-null when the expected type is nullable.
                // If both are list types, the variable item type can be more strict
                // than the expected item type (contravariant).
                $schema = $context->get_schema();
                $var_type = AST::type_from_ast([$schema, 'getType'], $var_def->type);
                if ($var_type !== null && !$this->allowed_variable_usage($schema, $var_type, $var_def->default_value, $type, $default_value)) {
                    $context->report_error(new Error(static::bad_var_pos_message($var_name, $var_type->to_string(), $type->to_string()), [$var_def, $node]));
                }
            }
        }], Node_Kind::VARIABLE_DEFINITION => function (Variable_Definition_Node $var_def_node): void {
            $this->var_def_map[$var_def_node->variable->name->value] = $var_def_node;
        }];
    }
    /**
     * A var type is allowed if it is the same or more strict than the expected
     * type. It can be more strict if the variable type is non-null when the
     * expected type is nullable. If both are list types, the variable item type can
     * be more strict than the expected item type.
     */
    public static function bad_var_pos_message(string $var_name, string $var_type, string $expected_type): string
    {
        return "Variable \"\${$var_name}\" of type \"{$var_type}\" used in position expecting type \"{$expected_type}\".";
    }
    /**
     * Returns true if the variable is allowed in the location it was found,
     * which includes considering if default values exist for either the variable
     * or the location at which it is located.
     *
     * @param ValueNode|null $varDefaultValue
     * @param mixed $locationDefaultValue
     *
     * @throws InvariantViolation
     */
    protected function allowed_variable_usage(Schema $schema, Type $var_type, $var_default_value, Type $location_type, $location_default_value): bool
    {
        if ($location_type instanceof Non_Null && !$var_type instanceof Non_Null) {
            $has_non_null_variable_default_value = $var_default_value !== null && !$var_default_value instanceof Null_Value_Node;
            $has_location_default_value = Utils::undefined() !== $location_default_value;
            if (!$has_non_null_variable_default_value && !$has_location_default_value) {
                return false;
            }
            $nullable_location_type = $location_type->get_wrapped_type();
            return Type_Comparators::is_type_sub_type_of($schema, $var_type, $nullable_location_type);
        }
        return Type_Comparators::is_type_sub_type_of($schema, $var_type, $location_type);
    }
}
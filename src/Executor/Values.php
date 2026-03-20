<?php

declare (strict_types=1);
namespace Graph_Ql\Executor;

use Graph_Ql\Error\Error;
use Graph_Ql\Language\AST\Argument_Node;
use Graph_Ql\Language\AST\Directive_Node;
use Graph_Ql\Language\AST\Enum_Type_Definition_Node;
use Graph_Ql\Language\AST\Enum_Type_Extension_Node;
use Graph_Ql\Language\AST\Enum_Value_Definition_Node;
use Graph_Ql\Language\AST\Field_Definition_Node;
use Graph_Ql\Language\AST\Field_Node;
use Graph_Ql\Language\AST\Fragment_Definition_Node;
use Graph_Ql\Language\AST\Fragment_Spread_Node;
use Graph_Ql\Language\AST\Inline_Fragment_Node;
use Graph_Ql\Language\AST\Input_Object_Type_Definition_Node;
use Graph_Ql\Language\AST\Input_Object_Type_Extension_Node;
use Graph_Ql\Language\AST\Input_Value_Definition_Node;
use Graph_Ql\Language\AST\Interface_Type_Definition_Node;
use Graph_Ql\Language\AST\Interface_Type_Extension_Node;
use Graph_Ql\Language\AST\Node;
use Graph_Ql\Language\AST\Node_List;
use Graph_Ql\Language\AST\Null_Value_Node;
use Graph_Ql\Language\AST\Object_Type_Definition_Node;
use Graph_Ql\Language\AST\Object_Type_Extension_Node;
use Graph_Ql\Language\AST\Operation_Definition_Node;
use Graph_Ql\Language\AST\Scalar_Type_Definition_Node;
use Graph_Ql\Language\AST\Scalar_Type_Extension_Node;
use Graph_Ql\Language\AST\Schema_Extension_Node;
use Graph_Ql\Language\AST\Union_Type_Definition_Node;
use Graph_Ql\Language\AST\Union_Type_Extension_Node;
use Graph_Ql\Language\AST\Variable_Definition_Node;
use Graph_Ql\Language\AST\Variable_Node;
use Graph_Ql\Language\Printer;
use Graph_Ql\Type\Definition\Directive;
use Graph_Ql\Type\Definition\Field_Definition;
use Graph_Ql\Type\Definition\Non_Null;
use Graph_Ql\Type\Definition\Type;
use Graph_Ql\Type\Schema;
use Graph_Ql\Utils\AST;
use Graph_Ql\Utils\Utils;
use Graph_Ql\Utils\Value;
/**
 * @see ArgumentNode - force IDE import
 *
 * @phpstan-import-type ArgumentNodeValue from ArgumentNode
 *
 * @see \GraphQL\Tests\Executor\ValuesTest
 */
class Values
{
    /**
     * Prepares an object map of variables of the correct type based on the provided
     * variable definitions and arbitrary input. If the input cannot be coerced
     * to match the variable definitions, an Error will be thrown.
     *
     * @param NodeList<VariableDefinitionNode> $varDefNodes
     * @param array<string, mixed> $rawVariableValues
     *
     * @throws \Exception
     *
     * @return array{array<int, Error>, null}|array{null, array<string, mixed>}
     */
    public static function get_variable_values(Schema $schema, Node_List $var_def_nodes, array $raw_variable_values): array
    {
        $errors = [];
        $coerced_values = [];
        foreach ($var_def_nodes as $var_def_node) {
            $var_name = $var_def_node->variable->name->value;
            $var_type = AST::type_from_ast([$schema, 'getType'], $var_def_node->type);
            if (!Type::is_input_type($var_type)) {
                // Must use input types for variables. This should be caught during
                // validation, however is checked again here for safety.
                $type_str = Printer::do_print($var_def_node->type);
                $errors[] = new Error("Variable \"\${$var_name}\" expected value of type \"{$type_str}\" which cannot be used as an input type.", [$var_def_node->type]);
            } else {
                $has_value = array_key_exists($var_name, $raw_variable_values);
                $value = $has_value ? $raw_variable_values[$var_name] : Utils::undefined();
                if (!$has_value && $var_def_node->default_value !== null) {
                    // If no value was provided to a variable with a default value,
                    // use the default value.
                    $coerced_values[$var_name] = AST::value_from_ast($var_def_node->default_value, $var_type);
                } elseif ((!$has_value || $value === null) && $var_type instanceof Non_Null) {
                    // If no value or a nullish value was provided to a variable with a
                    // non-null type (required), produce an error.
                    $safe_var_type = Utils::print_safe($var_type);
                    $message = $has_value ? "Variable \"\${$var_name}\" of non-null type \"{$safe_var_type}\" must not be null." : "Variable \"\${$var_name}\" of required type \"{$safe_var_type}\" was not provided.";
                    $errors[] = new Error($message, [$var_def_node]);
                } elseif ($has_value) {
                    if ($value === null) {
                        // If the explicit value `null` was provided, an entry in the coerced
                        // values must exist as the value `null`.
                        $coerced_values[$var_name] = null;
                    } else {
                        // Otherwise, a non-null value was provided, coerce it to the expected
                        // type or report an error if coercion fails.
                        $coerced = Value::coerce_input_value($value, $var_type, null, $schema);
                        $coercion_errors = $coerced['errors'];
                        if ($coercion_errors !== null) {
                            foreach ($coercion_errors as $coercion_error) {
                                $invalid_value = $coercion_error->print_invalid_value();
                                $input_path = $coercion_error->print_input_path();
                                $path_message = $input_path !== null ? " at \"{$var_name}{$input_path}\"" : '';
                                $errors[] = new Error("Variable \"\${$var_name}\" got invalid value {$invalid_value}{$path_message}; {$coercion_error->get_message()}", $var_def_node, $coercion_error->get_source(), $coercion_error->get_positions(), $coercion_error->get_path(), $coercion_error, $coercion_error->get_extensions());
                            }
                        } else {
                            $coerced_values[$var_name] = $coerced['value'];
                        }
                    }
                }
            }
        }
        return $errors === [] ? [null, $coerced_values] : [$errors, null];
    }
    /**
     * Prepares an object map of argument values given a directive definition
     * and an AST node which may contain directives. Optionally also accepts a map
     * of variable values.
     *
     * If the directive does not exist on the node, returns undefined.
     *
     * @param EnumTypeDefinitionNode|EnumTypeExtensionNode|EnumValueDefinitionNode|FieldDefinitionNode|FieldNode|FragmentDefinitionNode|FragmentSpreadNode|InlineFragmentNode|InputObjectTypeDefinitionNode|InputObjectTypeExtensionNode|InputValueDefinitionNode|InterfaceTypeDefinitionNode|InterfaceTypeExtensionNode|ObjectTypeDefinitionNode|ObjectTypeExtensionNode|OperationDefinitionNode|ScalarTypeDefinitionNode|ScalarTypeExtensionNode|SchemaExtensionNode|UnionTypeDefinitionNode|UnionTypeExtensionNode|VariableDefinitionNode $node
     * @param array<string, mixed>|null $variableValues
     *
     * @throws \Exception
     * @throws Error
     *
     * @return array<string, mixed>|null
     */
    public static function get_directive_values(Directive $directive_def, Node $node, ?array $variable_values = null): ?array
    {
        $directive_def_name = $directive_def->name;
        foreach ($node->directives as $directive) {
            if ($directive->name->value === $directive_def_name) {
                return self::get_argument_values($directive_def, $directive, $variable_values);
            }
        }
        return null;
    }
    /**
     * Prepares an object map of argument values given a list of argument
     * definitions and list of argument AST nodes.
     *
     * @param FieldDefinition|Directive $def
     * @param FieldNode|DirectiveNode $node
     * @param array<string, mixed>|null $variableValues
     *
     * @throws \Exception
     * @throws Error
     *
     * @return array<string, mixed>
     */
    public static function get_argument_values($def, Node $node, ?array $variable_values = null): array
    {
        if ($def->args === []) {
            return [];
        }
        /** @var array<string, ArgumentNodeValue> $argumentValueMap */
        $argument_value_map = [];
        // Might not be defined when an AST from JS is used
        if (isset($node->arguments)) {
            foreach ($node->arguments as $argument_node) {
                $argument_value_map[$argument_node->name->value] = $argument_node->value;
            }
        }
        return static::get_argument_values_for_map($def, $argument_value_map, $variable_values, $node);
    }
    /**
     * @param FieldDefinition|Directive $def
     * @param array<string, ArgumentNodeValue> $argumentValueMap
     * @param array<string, mixed>|null $variableValues
     *
     * @throws \Exception
     * @throws Error
     *
     * @return array<string, mixed>
     */
    public static function get_argument_values_for_map($def, array $argument_value_map, ?array $variable_values = null, ?Node $reference_node = null): array
    {
        /** @var array<string, mixed> $coercedValues */
        $coerced_values = [];
        foreach ($def->args as $argument_definition) {
            $name = $argument_definition->name;
            $arg_type = $argument_definition->get_type();
            $argument_value_node = $argument_value_map[$name] ?? null;
            if ($argument_value_node instanceof Variable_Node) {
                $variable_name = $argument_value_node->name->value;
                $has_value = $variable_values !== null && array_key_exists($variable_name, $variable_values);
                $is_null = $has_value && $variable_values[$variable_name] === null;
            } else {
                $has_value = $argument_value_node !== null;
                $is_null = $argument_value_node instanceof Null_Value_Node;
            }
            if (!$has_value && $argument_definition->default_value_exists()) {
                // If no argument was provided where the definition has a default value,
                // use the default value.
                $coerced_values[$name] = $argument_definition->default_value;
            } elseif ((!$has_value || $is_null) && $arg_type instanceof Non_Null) {
                // If no argument or a null value was provided to an argument with a
                // non-null type (required), produce a field error.
                $safe_arg_type = Utils::print_safe($arg_type);
                if ($is_null) {
                    throw new Error("Argument \"{$name}\" of non-null type \"{$safe_arg_type}\" must not be null.", $reference_node);
                }
                if ($argument_value_node instanceof Variable_Node) {
                    throw new Error("Argument \"{$name}\" of required type \"{$safe_arg_type}\" was provided the variable \"\${$argument_value_node->name->value}\" which was not provided a runtime value.", [$argument_value_node]);
                }
                throw new Error("Argument \"{$name}\" of required type \"{$safe_arg_type}\" was not provided.", $reference_node);
            } elseif ($has_value) {
                assert($argument_value_node instanceof Node);
                if ($argument_value_node instanceof Null_Value_Node) {
                    // If the explicit value `null` was provided, an entry in the coerced
                    // values must exist as the value `null`.
                    $coerced_values[$name] = null;
                } elseif ($argument_value_node instanceof Variable_Node) {
                    $variable_name = $argument_value_node->name->value;
                    // Note: This does no further checking that this variable is correct.
                    // This assumes that this query has been validated and the variable
                    // usage here is of the correct type.
                    $coerced_values[$name] = $variable_values[$variable_name] ?? null;
                } else {
                    $coerced_value = AST::value_from_ast($argument_value_node, $arg_type, $variable_values);
                    if (Utils::undefined() === $coerced_value) {
                        // Note: ValuesOfCorrectType validation should catch this before
                        // execution. This is a runtime check to ensure execution does not
                        // continue with an invalid argument value.
                        $invalid_value = Printer::do_print($argument_value_node);
                        throw new Error("Argument \"{$name}\" has invalid value {$invalid_value}.", [$argument_value_node]);
                    }
                    $coerced_values[$name] = $coerced_value;
                }
            }
        }
        return $coerced_values;
    }
}
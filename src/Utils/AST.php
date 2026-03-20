<?php

declare (strict_types=1);
namespace Graph_Ql\Utils;

use Graph_Ql\Error\Error;
use Graph_Ql\Error\Invariant_Violation;
use Graph_Ql\Error\Serialization_Error;
use Graph_Ql\Language\AST\Boolean_Value_Node;
use Graph_Ql\Language\AST\Definition_Node;
use Graph_Ql\Language\AST\Document_Node;
use Graph_Ql\Language\AST\Enum_Value_Node;
use Graph_Ql\Language\AST\Float_Value_Node;
use Graph_Ql\Language\AST\Int_Value_Node;
use Graph_Ql\Language\AST\List_Type_Node;
use Graph_Ql\Language\AST\List_Value_Node;
use Graph_Ql\Language\AST\Location;
use Graph_Ql\Language\AST\Named_Type_Node;
use Graph_Ql\Language\AST\Name_Node;
use Graph_Ql\Language\AST\Node;
use Graph_Ql\Language\AST\Node_Kind;
use Graph_Ql\Language\AST\Node_List;
use Graph_Ql\Language\AST\Non_Null_Type_Node;
use Graph_Ql\Language\AST\Null_Value_Node;
use Graph_Ql\Language\AST\Object_Field_Node;
use Graph_Ql\Language\AST\Object_Value_Node;
use Graph_Ql\Language\AST\Operation_Definition_Node;
use Graph_Ql\Language\AST\String_Value_Node;
use Graph_Ql\Language\AST\Value_Node;
use Graph_Ql\Language\AST\Variable_Node;
use Graph_Ql\Type\Definition\Enum_Type;
use Graph_Ql\Type\Definition\Id_Type;
use Graph_Ql\Type\Definition\Input_Object_Type;
use Graph_Ql\Type\Definition\Input_Type;
use Graph_Ql\Type\Definition\Leaf_Type;
use Graph_Ql\Type\Definition\List_Of_Type;
use Graph_Ql\Type\Definition\Non_Null;
use Graph_Ql\Type\Definition\Nullable_Type;
use Graph_Ql\Type\Definition\Scalar_Type;
use Graph_Ql\Type\Definition\Type;
/**
 * Various utilities dealing with AST.
 */
class AST
{
    /**
     * Convert representation of AST as an associative array to instance of GraphQL\Language\AST\Node.
     *
     * For example:
     *
     * ```php
     * AST::fromArray([
     *     'kind' => 'ListValue',
     *     'values' => [
     *         ['kind' => 'StringValue', 'value' => 'my str'],
     *         ['kind' => 'StringValue', 'value' => 'my other str']
     *     ],
     *     'loc' => ['start' => 21, 'end' => 25]
     * ]);
     * ```
     *
     * Will produce instance of `ListValueNode` where `values` prop is a lazily-evaluated `NodeList`
     * returning instances of `StringValueNode` on access.
     *
     * This is a reverse operation for AST::toArray($node)
     *
     * @param array<string, mixed> $node
     *
     * @api
     *
     * @throws \JsonException
     * @throws InvariantViolation
     */
    public static function from_array(array $node): Node
    {
        $kind = $node['kind'] ?? null;
        if ($kind === null) {
            $safe_node = Utils::print_safe_json($node);
            throw new Invariant_Violation("Node is missing kind: {$safe_node}");
        }
        $class = Node_Kind::CLASS_MAP[$kind] ?? null;
        if ($class === null) {
            $safe_node = Utils::print_safe_json($node);
            throw new Invariant_Violation("Node has unexpected kind: {$safe_node}");
        }
        $instance = new $class([]);
        if (isset($node['loc']['start'], $node['loc']['end'])) {
            $instance->loc = Location::create($node['loc']['start'], $node['loc']['end']);
        }
        foreach ($node as $key => $value) {
            if ($key === 'loc') {
                continue;
            }
            if ($key === 'kind') {
                continue;
            }
            if (is_array($value)) {
                $value = isset($value[0]) || $value === [] ? new Node_List($value) : self::from_array($value);
            }
            $instance->{$key} = $value;
        }
        return $instance;
    }
    /**
     * Convert AST node to serializable array.
     *
     * @return array<string, mixed>
     *
     * @api
     */
    public static function to_array(Node $node): array
    {
        return $node->to_array();
    }
    /**
     * Produces a GraphQL Value AST given a PHP value.
     *
     * Optionally, a GraphQL type may be provided, which will be used to
     * disambiguate between value primitives.
     *
     * | PHP Value     | GraphQL Value        |
     * | ------------- | -------------------- |
     * | Object        | Input Object         |
     * | Assoc Array   | Input Object         |
     * | Array         | List                 |
     * | Boolean       | Boolean              |
     * | String        | String / Enum Value  |
     * | Int           | Int                  |
     * | Float         | Int / Float          |
     * | Mixed         | Enum Value           |
     * | null          | NullValue            |
     *
     * @param mixed $value
     * @param InputType&Type $type
     *
     * @throws \JsonException
     * @throws InvariantViolation
     * @throws SerializationError
     *
     * @return (ValueNode&Node)|null
     *
     * @api
     */
    public static function ast_from_value($value, Input_Type $type): ?Value_Node
    {
        if ($type instanceof Non_Null) {
            $wrapped_type = $type->get_wrapped_type();
            assert($wrapped_type instanceof Input_Type);
            $ast_value = self::ast_from_value($value, $wrapped_type);
            return $ast_value instanceof Null_Value_Node ? null : $ast_value;
        }
        if ($value === null) {
            return new Null_Value_Node([]);
        }
        // Convert PHP iterables to GraphQL list. If the GraphQLType is a list, but
        // the value is not an array, convert the value using the list's item type.
        if ($type instanceof List_Of_Type) {
            $item_type = $type->get_wrapped_type();
            assert($item_type instanceof Input_Type, 'proven by schema validation');
            if (is_iterable($value)) {
                $values_nodes = [];
                foreach ($value as $item) {
                    $item_node = self::ast_from_value($item, $item_type);
                    if ($item_node !== null) {
                        $values_nodes[] = $item_node;
                    }
                }
                return new List_Value_Node(['values' => new Node_List($values_nodes)]);
            }
            return self::ast_from_value($value, $item_type);
        }
        // Populate the fields of the input object by creating ASTs from each value
        // in the PHP object according to the fields in the input type.
        if ($type instanceof Input_Object_Type) {
            $is_array = is_array($value);
            $is_array_like = $is_array || $value instanceof \ArrayAccess;
            if (!$is_array_like && !is_object($value)) {
                return null;
            }
            $fields = $type->get_fields();
            $field_nodes = [];
            foreach ($fields as $field_name => $field) {
                $field_value = $is_array_like ? $value[$field_name] ?? null : $value->{$field_name} ?? null;
                // Have to check additionally if key exists, since we differentiate between
                // "no key" and "value is null":
                if ($field_value !== null) {
                    $field_exists = true;
                } elseif ($is_array) {
                    $field_exists = array_key_exists($field_name, $value);
                } elseif ($is_array_like) {
                    $field_exists = $value->offsetExists($field_name);
                } else {
                    $field_exists = property_exists($value, $field_name);
                }
                if (!$field_exists) {
                    continue;
                }
                $field_node = self::ast_from_value($field_value, $field->get_type());
                if ($field_node === null) {
                    continue;
                }
                $field_nodes[] = new Object_Field_Node(['name' => new Name_Node(['value' => $field_name]), 'value' => $field_node]);
            }
            return new Object_Value_Node(['fields' => new Node_List($field_nodes)]);
        }
        assert($type instanceof Leaf_Type, 'other options were exhausted');
        // Since value is an internally represented value, it must be serialized
        // to an externally represented value before converting into an AST.
        $serialized = $type->serialize($value);
        // Others serialize based on their corresponding PHP scalar types.
        if (is_bool($serialized)) {
            return new Boolean_Value_Node(['value' => $serialized]);
        }
        if (is_int($serialized)) {
            return new Int_Value_Node(['value' => (string) $serialized]);
        }
        if (is_float($serialized)) {
            /** @phpstan-ignore equal.notAllowed (int cast with == used for performance reasons) */
            if ((int) $serialized == $serialized) {
                return new Int_Value_Node(['value' => (string) $serialized]);
            }
            return new Float_Value_Node(['value' => (string) $serialized]);
        }
        if (is_string($serialized)) {
            // Enum types use Enum literals.
            if ($type instanceof Enum_Type) {
                return new Enum_Value_Node(['value' => $serialized]);
            }
            // ID types can use Int literals.
            $as_int = (int) $serialized;
            if ($type instanceof Id_Type && (string) $as_int === $serialized) {
                return new Int_Value_Node(['value' => $serialized]);
            }
            // Use json_encode, which uses the same string encoding as GraphQL,
            // then remove the quotes.
            return new String_Value_Node(['value' => $serialized]);
        }
        $not_convertible = Utils::print_safe($serialized);
        throw new Invariant_Violation("Cannot convert value to AST: {$not_convertible}");
    }
    /**
     * Produces a PHP value given a GraphQL Value AST.
     *
     * A GraphQL type must be provided, which will be used to interpret different
     * GraphQL Value literals.
     *
     * Returns `null` when the value could not be validly coerced according to
     * the provided type.
     *
     * | GraphQL Value        | PHP Value     |
     * | -------------------- | ------------- |
     * | Input Object         | Assoc Array   |
     * | List                 | Array         |
     * | Boolean              | Boolean       |
     * | String               | String        |
     * | Int / Float          | Int / Float   |
     * | Enum Value           | Mixed         |
     * | Null Value           | null          |
     *
     * @param (ValueNode&Node)|null $valueNode
     * @param array<string, mixed>|null $variables
     *
     * @throws \Exception
     *
     * @return mixed
     *
     * @api
     */
    public static function value_from_ast(?Value_Node $value_node, Type $type, ?array $variables = null)
    {
        $undefined = Utils::undefined();
        if ($value_node === null) {
            // When there is no AST, then there is also no value.
            // Importantly, this is different from returning the GraphQL null value.
            return $undefined;
        }
        if ($type instanceof Non_Null) {
            if ($value_node instanceof Null_Value_Node) {
                // Invalid: intentionally return no value.
                return $undefined;
            }
            return self::value_from_ast($value_node, $type->get_wrapped_type(), $variables);
        }
        if ($value_node instanceof Null_Value_Node) {
            // This is explicitly returning the value null.
            return null;
        }
        if ($value_node instanceof Variable_Node) {
            $variable_name = $value_node->name->value;
            if ($variables === null || !array_key_exists($variable_name, $variables)) {
                // No valid return value.
                return $undefined;
            }
            // Note: This does no further checking that this variable is correct.
            // This assumes that this query has been validated and the variable
            // usage here is of the correct type.
            return $variables[$variable_name];
        }
        if ($type instanceof List_Of_Type) {
            $item_type = $type->get_wrapped_type();
            if ($value_node instanceof List_Value_Node) {
                $coerced_values = [];
                $item_nodes = $value_node->values;
                foreach ($item_nodes as $item_node) {
                    if (self::is_missing_variable($item_node, $variables)) {
                        // If an array contains a missing variable, it is either coerced to
                        // null or if the item type is non-null, it considered invalid.
                        if ($item_type instanceof Non_Null) {
                            // Invalid: intentionally return no value.
                            return $undefined;
                        }
                        $coerced_values[] = null;
                    } else {
                        $item_value = self::value_from_ast($item_node, $item_type, $variables);
                        if ($undefined === $item_value) {
                            // Invalid: intentionally return no value.
                            return $undefined;
                        }
                        $coerced_values[] = $item_value;
                    }
                }
                return $coerced_values;
            }
            $coerced_value = self::value_from_ast($value_node, $item_type, $variables);
            if ($undefined === $coerced_value) {
                // Invalid: intentionally return no value.
                return $undefined;
            }
            return [$coerced_value];
        }
        if ($type instanceof Input_Object_Type) {
            if (!$value_node instanceof Object_Value_Node) {
                // Invalid: intentionally return no value.
                return $undefined;
            }
            $coerced_obj = [];
            $fields = $type->get_fields();
            $field_nodes = [];
            foreach ($value_node->fields as $field) {
                $field_nodes[$field->name->value] = $field;
            }
            foreach ($fields as $field) {
                $field_name = $field->name;
                $field_node = $field_nodes[$field_name] ?? null;
                if ($field_node === null || self::is_missing_variable($field_node->value, $variables)) {
                    if ($field->default_value_exists()) {
                        $coerced_obj[$field_name] = $field->default_value;
                    } elseif ($field->get_type() instanceof Non_Null) {
                        // Invalid: intentionally return no value.
                        return $undefined;
                    }
                    continue;
                }
                $field_value = self::value_from_ast($field_node->value, $field->get_type(), $variables);
                if ($undefined === $field_value) {
                    // Invalid: intentionally return no value.
                    return $undefined;
                }
                $coerced_obj[$field_name] = $field_value;
            }
            return $type->parse_value($coerced_obj);
        }
        if ($type instanceof Enum_Type) {
            try {
                return $type->parse_literal($value_node, $variables);
            } catch (\Throwable $error) {
                return $undefined;
            }
        }
        assert($type instanceof Scalar_Type, 'only remaining option');
        // Scalars fulfill parsing a literal value via parseLiteral().
        // Invalid values represent a failure to parse correctly, in which case
        // no value is returned.
        try {
            return $type->parse_literal($value_node, $variables);
        } catch (\Throwable $error) {
            return $undefined;
        }
    }
    /**
     * Returns true if the provided valueNode is a variable which is not defined
     * in the set of variables.
     *
     * @param ValueNode&Node $valueNode
     * @param array<string, mixed>|null $variables
     */
    private static function is_missing_variable(Value_Node $value_node, ?array $variables): bool
    {
        return $value_node instanceof Variable_Node && ($variables === null || !array_key_exists($value_node->name->value, $variables));
    }
    /**
     * Produces a PHP value given a GraphQL Value AST.
     *
     * Unlike `valueFromAST()`, no type is provided. The resulting PHP value
     * will reflect the provided GraphQL value AST.
     *
     * | GraphQL Value        | PHP Value     |
     * | -------------------- | ------------- |
     * | Input Object         | Assoc Array   |
     * | List                 | Array         |
     * | Boolean              | Boolean       |
     * | String               | String        |
     * | Int / Float          | Int / Float   |
     * | Enum                 | Mixed         |
     * | Null                 | null          |
     *
     * @param array<string, mixed>|null $variables
     *
     * @throws \Exception
     *
     * @return mixed
     *
     * @api
     */
    public static function value_from_ast_untyped(Node $value_node, ?array $variables = null)
    {
        switch (true) {
            case $value_node instanceof Null_Value_Node:
                return null;
            case $value_node instanceof Int_Value_Node:
                return (int) $value_node->value;
            case $value_node instanceof Float_Value_Node:
                return (float) $value_node->value;
            case $value_node instanceof String_Value_Node:
            case $value_node instanceof Enum_Value_Node:
            case $value_node instanceof Boolean_Value_Node:
                return $value_node->value;
            case $value_node instanceof List_Value_Node:
                $values = [];
                foreach ($value_node->values as $node) {
                    $values[] = self::value_from_ast_untyped($node, $variables);
                }
                return $values;
            case $value_node instanceof Object_Value_Node:
                $values = [];
                foreach ($value_node->fields as $field) {
                    $values[$field->name->value] = self::value_from_ast_untyped($field->value, $variables);
                }
                return $values;
            case $value_node instanceof Variable_Node:
                $variable_name = $value_node->name->value;
                return ($variables ?? []) !== [] && isset($variables[$variable_name]) ? $variables[$variable_name] : null;
        }
        throw new Error("Unexpected value kind: {$value_node->kind}");
    }
    /**
     * Returns type definition for given AST Type node.
     *
     * @param callable(string): ?Type $typeLoader
     * @param NamedTypeNode|ListTypeNode|NonNullTypeNode $inputTypeNode
     *
     * @throws \Exception
     *
     * @api
     */
    public static function type_from_ast(callable $type_loader, Node $input_type_node): ?Type
    {
        if ($input_type_node instanceof List_Type_Node) {
            $inner_type = self::type_from_ast($type_loader, $input_type_node->type);
            return $inner_type === null ? null : new List_Of_Type($inner_type);
        }
        if ($input_type_node instanceof Non_Null_Type_Node) {
            $inner_type = self::type_from_ast($type_loader, $input_type_node->type);
            if ($inner_type === null) {
                return null;
            }
            assert($inner_type instanceof Nullable_Type, 'proven by schema validation');
            return new Non_Null($inner_type);
        }
        return $type_loader($input_type_node->name->value);
    }
    /**
     * Returns the operation within a document by name.
     *
     * If a name is not provided, an operation is only returned if the document has exactly one.
     *
     * @api
     */
    public static function get_operation_ast(Document_Node $document, ?string $operation_name = null): ?Operation_Definition_Node
    {
        $operation = null;
        foreach ($document->definitions->getIterator() as $node) {
            if (!$node instanceof Operation_Definition_Node) {
                continue;
            }
            if ($operation_name === null) {
                // We found a second operation, so we bail instead of returning an ambiguous result.
                if ($operation !== null) {
                    return null;
                }
                $operation = $node;
            } elseif ($node->name instanceof Name_Node && $node->name->value === $operation_name) {
                return $node;
            }
        }
        return $operation;
    }
    /**
     * Provided a collection of ASTs, presumably each from different files,
     * concatenate the ASTs together into batched AST, useful for validating many
     * GraphQL source files which together represent one conceptual application.
     *
     * @param array<DocumentNode> $documents
     *
     * @api
     */
    public static function concat_ast(array $documents): Document_Node
    {
        /** @var array<int, Node&DefinitionNode> $definitions */
        $definitions = [];
        foreach ($documents as $document) {
            foreach ($document->definitions as $definition) {
                $definitions[] = $definition;
            }
        }
        return new Document_Node(['definitions' => new Node_List($definitions)]);
    }
}
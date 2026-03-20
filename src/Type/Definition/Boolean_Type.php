<?php

declare (strict_types=1);
namespace Graph_Ql\Type\Definition;

use Graph_Ql\Error\Error;
use Graph_Ql\Language\AST\Boolean_Value_Node;
use Graph_Ql\Language\AST\Node;
use Graph_Ql\Language\Printer;
use Graph_Ql\Utils\Utils;
class Boolean_Type extends Scalar_Type
{
    public string $name = Type::BOOLEAN;
    public ?string $description = 'The `Boolean` scalar type represents `true` or `false`.';
    /**
     * Serialize the given value to a Boolean.
     *
     * The GraphQL spec leaves this up to the implementations, so we just do what
     * PHP does natively to make this intuitive for developers.
     */
    public function serialize($value): bool
    {
        return (bool) $value;
    }
    /** @throws Error */
    public function parse_value($value): bool
    {
        if (is_bool($value)) {
            return $value;
        }
        $not_boolean = Utils::print_safe_json($value);
        throw new Error("Boolean cannot represent a non boolean value: {$not_boolean}");
    }
    /**
     * @throws \JsonException
     * @throws Error
     */
    public function parse_literal(Node $value_node, ?array $variables = null): bool
    {
        if ($value_node instanceof Boolean_Value_Node) {
            return $value_node->value;
        }
        $not_boolean = Printer::do_print($value_node);
        throw new Error("Boolean cannot represent a non boolean value: {$not_boolean}", $value_node);
    }
}
<?php

declare (strict_types=1);
namespace Graph_Ql\Type\Definition;

use Graph_Ql\Error\Error;
use Graph_Ql\Error\Serialization_Error;
use Graph_Ql\Language\AST\Float_Value_Node;
use Graph_Ql\Language\AST\Int_Value_Node;
use Graph_Ql\Language\AST\Node;
use Graph_Ql\Language\Printer;
use Graph_Ql\Utils\Utils;
class Float_Type extends Scalar_Type
{
    public string $name = Type::FLOAT;
    public ?string $description = 'The `Float` scalar type represents signed double-precision fractional
values as specified by
[IEEE 754](http://en.wikipedia.org/wiki/IEEE_floating_point). ';
    /** @throws SerializationError */
    public function serialize($value): float
    {
        $float = is_numeric($value) || is_bool($value) ? (float) $value : null;
        if ($float === null || !is_finite($float)) {
            $not_float = Utils::print_safe($value);
            throw new Serialization_Error("Float cannot represent non numeric value: {$not_float}");
        }
        return $float;
    }
    /** @throws Error */
    public function parse_value($value): float
    {
        $float = is_float($value) || is_int($value) ? (float) $value : null;
        if ($float === null || !is_finite($float)) {
            $not_float = Utils::print_safe_json($value);
            throw new Error("Float cannot represent non numeric value: {$not_float}");
        }
        return $float;
    }
    /**
     * @throws \JsonException
     * @throws Error
     */
    public function parse_literal(Node $value_node, ?array $variables = null): float
    {
        if ($value_node instanceof Float_Value_Node || $value_node instanceof Int_Value_Node) {
            return (float) $value_node->value;
        }
        $not_float = Printer::do_print($value_node);
        throw new Error("Float cannot represent non numeric value: {$not_float}", $value_node);
    }
}
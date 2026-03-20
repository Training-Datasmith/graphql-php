<?php

declare (strict_types=1);
namespace Graph_Ql\Type\Definition;

use Graph_Ql\Error\Error;
use Graph_Ql\Error\Serialization_Error;
use Graph_Ql\Language\AST\Int_Value_Node;
use Graph_Ql\Language\AST\Node;
use Graph_Ql\Language\Printer;
use Graph_Ql\Utils\Utils;
class Int_Type extends Scalar_Type
{
    // As per the GraphQL Spec, Integers are only treated as valid when a valid
    // 32-bit signed integer, providing the broadest support across platforms.
    //
    // n.b. JavaScript's integers are safe between -(2^53 - 1) and 2^53 - 1 because
    // they are internally represented as IEEE 754 doubles.
    public const MAX_INT = 2147483647;
    public const MIN_INT = -2147483648;
    public string $name = Type::INT;
    public ?string $description = 'The `Int` scalar type represents non-fractional signed whole numeric
values. Int can represent values between -(2^31) and 2^31 - 1. ';
    /** @throws SerializationError */
    public function serialize($value): int
    {
        // Fast path for 90+% of cases:
        if (is_int($value) && $value <= self::MAX_INT && $value >= self::MIN_INT) {
            return $value;
        }
        $float = is_numeric($value) || is_bool($value) ? (float) $value : null;
        if ($float === null || floor($float) !== $float) {
            $not_int = Utils::print_safe($value);
            throw new Serialization_Error("Int cannot represent non-integer value: {$not_int}");
        }
        if ($float > self::MAX_INT || $float < self::MIN_INT) {
            $out_of_range_int = Utils::print_safe($value);
            throw new Serialization_Error("Int cannot represent non 32-bit signed integer value: {$out_of_range_int}");
        }
        return (int) $float;
    }
    /** @throws Error */
    public function parse_value($value): int
    {
        $is_int = is_int($value) || is_float($value) && floor($value) === $value;
        if (!$is_int) {
            $not_int = Utils::print_safe_json($value);
            throw new Error("Int cannot represent non-integer value: {$not_int}");
        }
        if ($value > self::MAX_INT || $value < self::MIN_INT) {
            $out_of_range_int = Utils::print_safe_json($value);
            throw new Error("Int cannot represent non 32-bit signed integer value: {$out_of_range_int}");
        }
        return (int) $value;
    }
    /**
     * @throws \JsonException
     * @throws Error
     */
    public function parse_literal(Node $value_node, ?array $variables = null): int
    {
        if ($value_node instanceof Int_Value_Node) {
            $val = (int) $value_node->value;
            if ($value_node->value === (string) $val && $val >= self::MIN_INT && $val <= self::MAX_INT) {
                return $val;
            }
        }
        $not_int = Printer::do_print($value_node);
        throw new Error("Int cannot represent non-integer value: {$not_int}", $value_node);
    }
}
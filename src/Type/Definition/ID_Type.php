<?php

declare (strict_types=1);
namespace Graph_Ql\Type\Definition;

use Graph_Ql\Error\Error;
use Graph_Ql\Error\Serialization_Error;
use Graph_Ql\Language\AST\Int_Value_Node;
use Graph_Ql\Language\AST\Node;
use Graph_Ql\Language\AST\String_Value_Node;
use Graph_Ql\Language\Printer;
use Graph_Ql\Utils\Utils;
class Id_Type extends Scalar_Type
{
    public string $name = 'ID';
    public ?string $description = 'The `ID` scalar type represents a unique identifier, often used to
refetch an object or as key for a cache. The ID type appears in a JSON
response as a String; however, it is not intended to be human-readable.
When expected as an input type, any string (such as `"4"`) or integer
(such as `4`) input value will be accepted as an ID.';
    /** @throws SerializationError */
    public function serialize($value): string
    {
        $can_cast = is_string($value) || is_int($value) || is_object($value) && method_exists($value, '__toString');
        if (!$can_cast) {
            $not_id = Utils::print_safe($value);
            throw new Serialization_Error("ID cannot represent a non-string and non-integer value: {$not_id}");
        }
        return (string) $value;
    }
    /** @throws Error */
    public function parse_value($value): string
    {
        if (is_string($value) || is_int($value)) {
            return (string) $value;
        }
        $not_id = Utils::print_safe_json($value);
        throw new Error("ID cannot represent a non-string and non-integer value: {$not_id}");
    }
    /**
     * @throws \JsonException
     * @throws Error
     */
    public function parse_literal(Node $value_node, ?array $variables = null): string
    {
        if ($value_node instanceof String_Value_Node || $value_node instanceof Int_Value_Node) {
            return $value_node->value;
        }
        $not_id = Printer::do_print($value_node);
        throw new Error("ID cannot represent a non-string and non-integer value: {$not_id}", $value_node);
    }
}
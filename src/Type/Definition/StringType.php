<?php

declare (strict_types=1);
namespace Graph_Ql\Type\Definition;

use Graph_Ql\Error\Error;
use Graph_Ql\Error\Serialization_Error;
use Graph_Ql\Language\AST\Node;
use Graph_Ql\Language\AST\String_Value_Node;
use Graph_Ql\Language\Printer;
use Graph_Ql\Utils\Utils;
class String_Type extends Scalar_Type
{
    public string $name = Type::STRING;
    public ?string $description = 'The `String` scalar type represents textual data, represented as UTF-8
character sequences. The String type is most often used by GraphQL to
represent free-form human-readable text.';
    /** @throws SerializationError */
    public function serialize($value): string
    {
        $can_cast = is_scalar($value) || is_object($value) && method_exists($value, '__toString') || $value === null;
        if (!$can_cast) {
            $not_stringable = Utils::print_safe($value);
            throw new Serialization_Error("String cannot represent value: {$not_stringable}");
        }
        return (string) $value;
    }
    /** @throws Error */
    public function parse_value($value): string
    {
        if (!is_string($value)) {
            $not_string = Utils::print_safe_json($value);
            throw new Error("String cannot represent a non string value: {$not_string}");
        }
        return $value;
    }
    /**
     * @throws \JsonException
     * @throws Error
     */
    public function parse_literal(Node $value_node, ?array $variables = null): string
    {
        if ($value_node instanceof String_Value_Node) {
            return $value_node->value;
        }
        $not_string = Printer::do_print($value_node);
        throw new Error("String cannot represent a non string value: {$not_string}", $value_node);
    }
}
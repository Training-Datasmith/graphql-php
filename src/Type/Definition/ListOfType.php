<?php

declare (strict_types=1);
namespace Graph_Ql\Type\Definition;

use Graph_Ql\Type\Schema;
/**
 * @template-covariant OfType of Type
 */
class List_Of_Type extends Type implements Wrapping_Type, Output_Type, Nullable_Type, Input_Type
{
    /**
     * @var Type|callable
     *
     * @phpstan-var OfType|callable(): OfType
     */
    private $wrapped_type;
    /**
     * @param Type|callable $type
     *
     * @phpstan-param OfType|callable(): OfType $type
     */
    public function __construct($type)
    {
        $this->wrapped_type = $type;
    }
    public function to_string(): string
    {
        return '[' . $this->get_wrapped_type()->to_string() . ']';
    }
    /** @phpstan-return OfType */
    public function get_wrapped_type(): Type
    {
        return Schema::resolve_type($this->wrapped_type);
    }
    public function get_innermost_type(): Named_Type
    {
        $type = $this->get_wrapped_type();
        while ($type instanceof Wrapping_Type) {
            $type = $type->get_wrapped_type();
        }
        assert($type instanceof Named_Type, 'known because we unwrapped all the way down');
        return $type;
    }
}
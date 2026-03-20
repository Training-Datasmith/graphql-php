<?php

declare (strict_types=1);
namespace Graph_Ql\Type\Definition;

use Graph_Ql\Type\Schema;
/**
 * @phpstan-type WrappedType (NullableType&Type)|callable():(NullableType&Type)
 */
class Non_Null extends Type implements Wrapping_Type, Output_Type, Input_Type
{
    /**
     * @var Type|callable
     *
     * @phpstan-var WrappedType
     */
    private $wrapped_type;
    /**
     * @param Type|callable $type
     *
     * @phpstan-param WrappedType $type
     */
    public function __construct($type)
    {
        $this->wrapped_type = $type;
    }
    public function to_string(): string
    {
        return $this->get_wrapped_type()->to_string() . '!';
    }
    /** @return NullableType&Type */
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
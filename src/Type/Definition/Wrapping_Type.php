<?php

declare (strict_types=1);
namespace Graph_Ql\Type\Definition;

interface Wrapping_Type
{
    /** Return the wrapped type, which may itself be a wrapping type. */
    public function get_wrapped_type(): Type;
    /**
     * Return the innermost wrapped type, which is guaranteed to be a named type.
     *
     * @return Type&NamedType
     */
    public function get_innermost_type(): Named_Type;
}
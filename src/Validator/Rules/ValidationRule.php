<?php

declare (strict_types=1);
namespace Graph_Ql\Validator\Rules;

use Graph_Ql\Language\Visitor;
use Graph_Ql\Validator\Query_Validation_Context;
use Graph_Ql\Validator\Sdl_Validation_Context;
/**
 * @phpstan-import-type VisitorArray from Visitor
 */
abstract class Validation_Rule
{
    protected string $name;
    public function get_name(): string
    {
        return $this->name ?? static::class;
    }
    /**
     * Returns structure suitable for @see \GraphQL\Language\Visitor.
     *
     * @phpstan-return VisitorArray
     */
    public function get_visitor(Query_Validation_Context $context): array
    {
        return [];
    }
    /**
     * Returns structure suitable for @see \GraphQL\Language\Visitor.
     *
     * @phpstan-return VisitorArray
     */
    public function get_sdl_visitor(Sdl_Validation_Context $context): array
    {
        return [];
    }
}
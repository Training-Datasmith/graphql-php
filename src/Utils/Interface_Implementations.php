<?php

declare (strict_types=1);
namespace Graph_Ql\Utils;

use Graph_Ql\Type\Definition\Interface_Type;
use Graph_Ql\Type\Definition\Object_Type;
/**
 * A way to track interface implementations.
 *
 * Distinguishes between implementations by ObjectTypes and InterfaceTypes.
 */
class Interface_Implementations
{
    /** @var array<int, ObjectType> */
    private array $objects;
    /** @var array<int, InterfaceType> */
    private array $interfaces;
    /**
     * @param array<int, ObjectType> $objects
     * @param array<int, InterfaceType> $interfaces
     */
    public function __construct(array $objects, array $interfaces)
    {
        $this->objects = $objects;
        $this->interfaces = $interfaces;
    }
    /** @return array<int, ObjectType> */
    public function objects(): array
    {
        return $this->objects;
    }
    /** @return array<int, InterfaceType> */
    public function interfaces(): array
    {
        return $this->interfaces;
    }
}
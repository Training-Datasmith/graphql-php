<?php

declare (strict_types=1);
namespace Graph_Ql\Type\Definition;

/**
 * export type GraphQLImplementingType =
 * GraphQLObjectType |
 * GraphQLInterfaceType;.
 */
interface Implementing_Type
{
    public function implements_interface(Interface_Type $interface_type): bool;
    /** @return array<int, InterfaceType> */
    public function get_interfaces(): array;
}
<?php

declare (strict_types=1);
namespace Graph_Ql\Type\Definition;

/**
 * export type InputType =
 * | ScalarType
 * | EnumType
 * | InputObjectType
 * | ListOfType<InputType>
 * | NonNull<
 * | ScalarType
 * | EnumType
 * | InputObjectType
 * | ListOfType<InputType>,
 * >;.
 */
interface Input_Type
{
}
<?php

declare (strict_types=1);
namespace Graph_Ql\Utils;

use Graph_Ql\Error\Invariant_Violation;
use Graph_Ql\Type\Definition\Implementing_Type;
use Graph_Ql\Type\Definition\List_Of_Type;
use Graph_Ql\Type\Definition\Non_Null;
use Graph_Ql\Type\Definition\Type;
use Graph_Ql\Type\Schema;
class Type_Comparators
{
    /** Provided two types, return true if the types are equal (invariant). */
    public static function is_equal_type(Type $type_a, Type $type_b): bool
    {
        // Equivalent types are equal.
        if ($type_a === $type_b) {
            return true;
        }
        if (self::are_same_built_in_scalar($type_a, $type_b)) {
            return true;
        }
        // If either type is non-null, the other must also be non-null.
        if ($type_a instanceof Non_Null && $type_b instanceof Non_Null) {
            return self::is_equal_type($type_a->get_wrapped_type(), $type_b->get_wrapped_type());
        }
        // If either type is a list, the other must also be a list.
        if ($type_a instanceof List_Of_Type && $type_b instanceof List_Of_Type) {
            return self::is_equal_type($type_a->get_wrapped_type(), $type_b->get_wrapped_type());
        }
        // Otherwise the types are not equal.
        return false;
    }
    /**
     * Provided a type and a super type, return true if the first type is either
     * equal or a subset of the second super type (covariant).
     *
     * @throws InvariantViolation
     */
    public static function is_type_sub_type_of(Schema $schema, Type $maybe_sub_type, Type $super_type): bool
    {
        // Equivalent type is a valid subtype
        if ($maybe_sub_type === $super_type) {
            return true;
        }
        if (self::are_same_built_in_scalar($maybe_sub_type, $super_type)) {
            return true;
        }
        // If superType is non-null, maybeSubType must also be nullable.
        if ($super_type instanceof Non_Null) {
            if ($maybe_sub_type instanceof Non_Null) {
                return self::is_type_sub_type_of($schema, $maybe_sub_type->get_wrapped_type(), $super_type->get_wrapped_type());
            }
            return false;
        }
        if ($maybe_sub_type instanceof Non_Null) {
            // If superType is nullable, maybeSubType may be non-null.
            return self::is_type_sub_type_of($schema, $maybe_sub_type->get_wrapped_type(), $super_type);
        }
        // If superType type is a list, maybeSubType type must also be a list.
        if ($super_type instanceof List_Of_Type) {
            if ($maybe_sub_type instanceof List_Of_Type) {
                return self::is_type_sub_type_of($schema, $maybe_sub_type->get_wrapped_type(), $super_type->get_wrapped_type());
            }
            return false;
        }
        if ($maybe_sub_type instanceof List_Of_Type) {
            // If superType is not a list, maybeSubType must also be not a list.
            return false;
        }
        if (Type::is_abstract_type($super_type)) {
            // If superType type is an abstract type, maybeSubType type may be a currently
            // possible object or interface type.
            return $maybe_sub_type instanceof Implementing_Type && $schema->is_sub_type($super_type, $maybe_sub_type);
        }
        return false;
    }
    /**
     * Built-in scalars may exist as different instances when a type loader
     * overrides them. Compare by name to handle this case.
     */
    private static function are_same_built_in_scalar(Type $type_a, Type $type_b): bool
    {
        return Type::is_built_in_scalar($type_a) && Type::is_built_in_scalar($type_b) && $type_a->name() === $type_b->name();
    }
}
<?php

declare (strict_types=1);
namespace Graph_Ql\Type\Definition;

use Graph_Ql\Error\Invariant_Violation;
use Graph_Ql\Type\Introspection;
use Graph_Ql\Type\Schema_Config;
use Graph_Ql\Utils\Utils;
/**
 * Registry of built-in GraphQL types and base class for all other types.
 */
abstract class Type implements \JsonSerializable
{
    public const INT = 'Int';
    public const FLOAT = 'Float';
    public const STRING = 'String';
    public const BOOLEAN = 'Boolean';
    public const ID = 'ID';
    /** @var list<string> */
    public const BUILT_IN_SCALAR_NAMES = [self::INT, self::FLOAT, self::STRING, self::BOOLEAN, self::ID];
    /**
     * @deprecated use {@see Type::BUILT_IN_SCALAR_NAMES}
     *
     * @var list<string>
     */
    public const STANDARD_TYPE_NAMES = self::BUILT_IN_SCALAR_NAMES;
    /**
     * Names of all built-in types: built-in scalars and introspection types.
     *
     * @see Type::BUILT_IN_SCALAR_NAMES for just the built-in scalar names.
     *
     * @var list<string>
     */
    public const BUILT_IN_TYPE_NAMES = [...self::BUILT_IN_SCALAR_NAMES, ...Introspection::TYPE_NAMES];
    /** @var array<string, ScalarType>|null */
    protected static ?array $built_in_scalars;
    /** @var array<string, Type&NamedType>|null */
    protected static ?array $built_in_types;
    /**
     * Returns the built-in Int scalar type.
     *
     * @api
     */
    public static function int(): Scalar_Type
    {
        return static::$built_in_scalars[self::INT] ??= new Int_Type();
        // @phpstan-ignore missingType.checkedException (static configuration is known to be correct)
    }
    /**
     * Returns the built-in Float scalar type.
     *
     * @api
     */
    public static function float(): Scalar_Type
    {
        return static::$built_in_scalars[self::FLOAT] ??= new Float_Type();
        // @phpstan-ignore missingType.checkedException (static configuration is known to be correct)
    }
    /**
     * Returns the built-in String scalar type.
     *
     * @api
     */
    public static function string(): Scalar_Type
    {
        return static::$built_in_scalars[self::STRING] ??= new String_Type();
        // @phpstan-ignore missingType.checkedException (static configuration is known to be correct)
    }
    /**
     * Returns the built-in Boolean scalar type.
     *
     * @api
     */
    public static function boolean(): Scalar_Type
    {
        return static::$built_in_scalars[self::BOOLEAN] ??= new Boolean_Type();
        // @phpstan-ignore missingType.checkedException (static configuration is known to be correct)
    }
    /**
     * Returns the built-in ID scalar type.
     *
     * @api
     */
    public static function id(): Scalar_Type
    {
        return static::$built_in_scalars[self::ID] ??= new Id_Type();
        // @phpstan-ignore missingType.checkedException (static configuration is known to be correct)
    }
    /**
     * Wraps the given type in a list type.
     *
     * @template T of Type
     *
     * @param T|callable():T $type
     *
     * @return ListOfType<T>
     *
     * @api
     */
    public static function list_of($type): List_Of_Type
    {
        return new List_Of_Type($type);
    }
    /**
     * Wraps the given type in a non-null type.
     *
     * @param NonNull|(NullableType&Type)|callable():(NullableType&Type) $type
     *
     * @api
     */
    public static function non_null($type): Non_Null
    {
        if ($type instanceof Non_Null) {
            return $type;
        }
        return new Non_Null($type);
    }
    /**
     * Returns all built-in types: built-in scalars and introspection types.
     *
     * @api
     *
     * @return array<string, Type&NamedType>
     */
    public static function built_in_types(): array
    {
        return self::$built_in_types ??= array_merge(Introspection::get_types(), self::built_in_scalars());
    }
    /**
     * Returns all built-in scalar types.
     *
     * @api
     *
     * @return array<string, ScalarType>
     */
    public static function built_in_scalars(): array
    {
        return [self::INT => static::int(), self::FLOAT => static::float(), self::STRING => static::string(), self::BOOLEAN => static::boolean(), self::ID => static::id()];
    }
    /**
     * Returns all built-in scalar types.
     *
     * @deprecated use {@see Type::builtInScalars()}
     *
     * @return array<string, ScalarType>
     */
    public static function get_standard_types(): array
    {
        return self::built_in_scalars();
    }
    /**
     * Allows partially or completely overriding the standard types globally.
     *
     * @deprecated prefer per-schema scalar overrides via {@see SchemaConfig::$types} or {@see SchemaConfig::$typeLoader}
     *
     * @param array<ScalarType> $types
     *
     * @throws InvariantViolation
     */
    public static function override_standard_types(array $types): void
    {
        // Reset caches that might contain instances of built-in scalars
        static::$built_in_types = null;
        Introspection::reset_cached_instances();
        Directive::reset_cached_instances();
        foreach ($types as $type) {
            // @phpstan-ignore-next-line generic type is not enforced by PHP
            if (!$type instanceof Scalar_Type) {
                $type_class = Scalar_Type::class;
                $not_type = Utils::print_safe($type);
                throw new Invariant_Violation("Expecting instance of {$type_class}, got {$not_type}");
            }
            if (!in_array($type->name, self::BUILT_IN_SCALAR_NAMES, true)) {
                $standard_type_names = implode(', ', self::BUILT_IN_SCALAR_NAMES);
                $not_standard_type_name = Utils::print_safe($type->name);
                throw new Invariant_Violation("Expecting one of the following names for a standard type: {$standard_type_names}; got {$not_standard_type_name}");
            }
            static::$built_in_scalars[$type->name] = $type;
        }
    }
    /**
     * Determines if the given type is a built-in scalar (Int, Float, String, Boolean, ID).
     *
     * Does not unwrap NonNull/List wrappers — checks the type instance directly.
     * ScalarType is a NamedType, so {@see Type::getNamedType()} is unnecessary.
     *
     * @param mixed $type
     *
     * @phpstan-assert-if-true ScalarType $type
     *
     * @api
     */
    public static function is_built_in_scalar($type): bool
    {
        return $type instanceof Scalar_Type && in_array($type->name, self::BUILT_IN_SCALAR_NAMES, true);
    }
    /**
     * Determines if the given type is an input type.
     *
     * @param mixed $type
     *
     * @api
     */
    public static function is_input_type(?\Graph_Ql\Type\Definition\Type $type): bool
    {
        return self::get_named_type($type) instanceof Input_Type;
    }
    /**
     * Returns the underlying named type of the given type.
     *
     * @return (Type&NamedType)|null
     *
     * @phpstan-return ($type is null ? null : Type&NamedType)
     *
     * @api
     */
    public static function get_named_type(?Type $type): ?Type
    {
        if ($type instanceof Wrapping_Type) {
            return $type->get_innermost_type();
        }
        assert($type === null || $type instanceof Named_Type, 'only other option');
        return $type;
    }
    /**
     * Determines if the given type is an output type.
     *
     * @param mixed $type
     *
     * @api
     */
    public static function is_output_type(?\Graph_Ql\Type\Definition\Type $type): bool
    {
        return self::get_named_type($type) instanceof Output_Type;
    }
    /**
     * Determines if the given type is a leaf type.
     *
     * @param mixed $type
     *
     * @api
     */
    public static function is_leaf_type($type): bool
    {
        return $type instanceof Leaf_Type;
    }
    /**
     * Determines if the given type is a composite type.
     *
     * @param mixed $type
     *
     * @api
     */
    public static function is_composite_type($type): bool
    {
        return $type instanceof Composite_Type;
    }
    /**
     * Determines if the given type is an abstract type.
     *
     * @param mixed $type
     *
     * @api
     */
    public static function is_abstract_type($type): bool
    {
        return $type instanceof Abstract_Type;
    }
    /**
     * Unwraps a potentially non-null type to return the underlying nullable type.
     *
     * @return Type&NullableType
     *
     * @api
     */
    public static function get_nullable_type(Type $type): Type
    {
        if ($type instanceof Non_Null) {
            return $type->get_wrapped_type();
        }
        assert($type instanceof Nullable_Type, 'only other option');
        return $type;
    }
    abstract public function to_string(): string;
    public function __toString(): string
    {
        return $this->to_string();
    }
    #[\Return_Type_Will_Change]
    public function jsonSerialize(): string
    {
        return $this->to_string();
    }
}
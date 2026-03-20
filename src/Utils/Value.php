<?php

declare (strict_types=1);
namespace Graph_Ql\Utils;

use Graph_Ql\Error\Client_Aware;
use Graph_Ql\Error\Coercion_Error;
use Graph_Ql\Error\Error;
use Graph_Ql\Error\Invariant_Violation;
use Graph_Ql\Type\Definition\Enum_Type;
use Graph_Ql\Type\Definition\Input_Object_Type;
use Graph_Ql\Type\Definition\Input_Type;
use Graph_Ql\Type\Definition\List_Of_Type;
use Graph_Ql\Type\Definition\Non_Null;
use Graph_Ql\Type\Definition\Scalar_Type;
use Graph_Ql\Type\Definition\Type;
use Graph_Ql\Type\Schema;
/**
 * @phpstan-type CoercedValue array{errors: null, value: mixed}
 * @phpstan-type CoercedErrors array{errors: array<int, CoercionError>, value: null}
 *
 * @phpstan-import-type InputPath from CoercionError
 */
class Value
{
    /**
     * Coerce the given value to match the given GraphQL Input Type.
     *
     * Returns either a value which is valid for the provided type,
     * or a list of encountered coercion errors.
     *
     * @param mixed $value
     * @param InputType&Type $type
     *
     * @phpstan-param InputPath|null $path
     *
     * @throws InvariantViolation
     *
     * @phpstan-return CoercedValue|CoercedErrors
     */
    public static function coerce_input_value($value, Input_Type $type, ?array $path = null, ?Schema $schema = null): array
    {
        if ($type instanceof Non_Null) {
            if ($value === null) {
                return self::of_errors([Coercion_Error::make("Expected non-nullable type \"{$type}\" not to be null.", $path, $value)]);
            }
            // @phpstan-ignore-next-line wrapped type is known to be input type after schema validation
            return self::coerce_input_value($value, $type->get_wrapped_type(), $path, $schema);
        }
        if ($value === null) {
            // Explicitly return the value null.
            return self::of_value(null);
        }
        // Account for type loader returning a different scalar instance than
        // the built-in singleton used in field definitions. Resolve the actual
        // type from the schema to ensure the correct parseValue() is called.
        if ($schema !== null && Type::is_built_in_scalar($type)) {
            $schema_type = $schema->get_type($type->name);
            assert($schema_type instanceof Scalar_Type, "Schema must provide a ScalarType for built-in scalar \"{$type->name}\".");
            $type = $schema_type;
        }
        if ($type instanceof Scalar_Type || $type instanceof Enum_Type) {
            try {
                return self::of_value($type->parse_value($value));
            } catch (\Throwable $error) {
                if ($error instanceof Error || $error instanceof Client_Aware && $error->is_client_safe()) {
                    return self::of_errors([Coercion_Error::make($error->get_message(), $path, $value, $error)]);
                }
                return self::of_errors([Coercion_Error::make("Expected type \"{$type->name}\".", $path, $value, $error)]);
            }
        }
        if ($type instanceof List_Of_Type) {
            $item_type = $type->get_wrapped_type();
            assert($item_type instanceof Input_Type, 'known through schema validation');
            if (is_iterable($value)) {
                $errors = [];
                $coerced_value = [];
                foreach ($value as $index => $item_value) {
                    $coerced_item = self::coerce_input_value($item_value, $item_type, [...$path ?? [], $index], $schema);
                    if (isset($coerced_item['errors'])) {
                        $errors = self::add($errors, $coerced_item['errors']);
                    } else {
                        $coerced_value[] = $coerced_item['value'];
                    }
                }
                return $errors === [] ? self::of_value($coerced_value) : self::of_errors($errors);
            }
            // Lists accept a non-list value as a list of one.
            $coerced_item = self::coerce_input_value($value, $item_type, null, $schema);
            return isset($coerced_item['errors']) ? $coerced_item : self::of_value([$coerced_item['value']]);
        }
        assert($type instanceof Input_Object_Type, 'we handled all other cases at this point');
        if ($value instanceof \stdClass) {
            // Cast objects to associative array before checking the fields.
            // Note that the coerced value will be an array.
            $value = (array) $value;
        } elseif (!is_array($value)) {
            return self::of_errors([Coercion_Error::make("Expected type \"{$type->name}\" to be an object.", $path, $value)]);
        }
        $errors = [];
        $coerced_value = [];
        $fields = $type->get_fields();
        foreach ($fields as $field_name => $field) {
            if (array_key_exists($field_name, $value)) {
                $field_value = $value[$field_name];
                $coerced_field = self::coerce_input_value($field_value, $field->get_type(), [...$path ?? [], $field_name], $schema);
                if (isset($coerced_field['errors'])) {
                    $errors = self::add($errors, $coerced_field['errors']);
                } else {
                    $coerced_value[$field_name] = $coerced_field['value'];
                }
            } elseif ($field->default_value_exists()) {
                $coerced_value[$field_name] = $field->default_value;
            } elseif ($field->get_type() instanceof Non_Null) {
                $errors = self::add($errors, Coercion_Error::make("Field \"{$field_name}\" of required type \"{$field->get_type()->to_string()}\" was not provided.", $path, $value));
            }
        }
        // Ensure every provided field is defined.
        foreach ($value as $field_name => $field) {
            if (array_key_exists($field_name, $fields)) {
                continue;
            }
            $suggestions = Utils::suggestion_list((string) $field_name, array_keys($fields));
            $message = "Field \"{$field_name}\" is not defined by type \"{$type->name}\"." . ($suggestions === [] ? '' : ' Did you mean ' . Utils::quoted_or_list($suggestions) . '?');
            $errors = self::add($errors, Coercion_Error::make($message, $path, $value));
        }
        // Validate OneOf constraints if this is a OneOf input type
        if ($type->is_one_of()) {
            $provided_field_count = 0;
            $null_field_name = null;
            foreach ($coerced_value as $field_name => $field_value) {
                if ($field_value !== null) {
                    ++$provided_field_count;
                } else {
                    $null_field_name = $field_name;
                }
            }
            // Check for null field values first (takes precedence)
            if ($null_field_name !== null) {
                $errors = self::add($errors, Coercion_Error::make("OneOf input object \"{$type->name}\" field \"{$null_field_name}\" must be non-null.", $path, $value));
            } elseif ($provided_field_count === 0) {
                $errors = self::add($errors, Coercion_Error::make("OneOf input object \"{$type->name}\" must specify exactly one field.", $path, $value));
            } elseif ($provided_field_count > 1) {
                $errors = self::add($errors, Coercion_Error::make("OneOf input object \"{$type->name}\" must specify exactly one field.", $path, $value));
            }
        }
        return $errors === [] ? self::of_value($type->parse_value($coerced_value)) : self::of_errors($errors);
    }
    /**
     * @param array<int, CoercionError> $errors
     *
     * @phpstan-return CoercedErrors
     */
    private static function of_errors(array $errors): array
    {
        return ['errors' => $errors, 'value' => null];
    }
    /**
     * @param mixed $value any value
     *
     * @phpstan-return CoercedValue
     */
    private static function of_value($value): array
    {
        return ['errors' => null, 'value' => $value];
    }
    /**
     * @param array<int, CoercionError> $errors
     * @param CoercionError|array<int, CoercionError> $errorOrErrors
     *
     * @return array<int, CoercionError>
     */
    private static function add(array $errors, $error_or_errors): array
    {
        $more_errors = is_array($error_or_errors) ? $error_or_errors : [$error_or_errors];
        return array_merge($errors, $more_errors);
    }
}
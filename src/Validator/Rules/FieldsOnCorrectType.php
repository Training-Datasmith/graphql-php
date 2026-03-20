<?php

declare (strict_types=1);
namespace Graph_Ql\Validator\Rules;

use Graph_Ql\Error\Error;
use Graph_Ql\Error\Invariant_Violation;
use Graph_Ql\Language\AST\Field_Node;
use Graph_Ql\Language\AST\Node_Kind;
use Graph_Ql\Type\Definition\Has_Fields_Type;
use Graph_Ql\Type\Definition\Named_Type;
use Graph_Ql\Type\Definition\Type;
use Graph_Ql\Type\Schema;
use Graph_Ql\Utils\Utils;
use Graph_Ql\Validator\Query_Validation_Context;
class Fields_On_Correct_Type extends Validation_Rule
{
    public function get_visitor(Query_Validation_Context $context): array
    {
        return [Node_Kind::FIELD => function (Field_Node $node) use ($context): void {
            $field_def = $context->get_field_def();
            if ($field_def !== null && $field_def->is_visible()) {
                return;
            }
            $type = $context->get_parent_type();
            if (!$type instanceof Named_Type) {
                return;
            }
            // This isn't valid. Let's find suggestions, if any.
            $schema = $context->get_schema();
            $field_name = $node->name->value;
            // First determine if there are any suggested types to condition on.
            $suggested_type_names = $this->get_suggested_type_names($schema, $type, $field_name);
            // If there are no suggested types, then perhaps this was a typo?
            $suggested_field_names = $suggested_type_names === [] ? $this->get_suggested_field_names($type, $field_name) : [];
            // Report an error, including helpful suggestions.
            $context->report_error(new Error(static::undefined_field_message($node->name->value, $type->name, $suggested_type_names, $suggested_field_names), [$node]));
        }];
    }
    /**
     * Go through all implementations of a type, as well as the interfaces
     * that it implements. If any of those types include the provided field,
     * suggest them, sorted by how often the type is referenced, starting
     * with interfaces.
     *
     * @throws InvariantViolation
     *
     * @return array<int, string>
     */
    protected function get_suggested_type_names(Schema $schema, Type $type, string $field_name): array
    {
        if (Type::is_abstract_type($type)) {
            $suggested_object_types = [];
            $interface_usage_count = [];
            foreach ($schema->get_possible_types($type) as $possible_type) {
                if (!$possible_type->has_field($field_name)) {
                    continue;
                }
                // This object type defines this field.
                $suggested_object_types[] = $possible_type->name;
                foreach ($possible_type->get_interfaces() as $possible_interface) {
                    if (!$possible_interface->has_field($field_name)) {
                        continue;
                    }
                    // This interface type defines this field.
                    $interface_usage_count[$possible_interface->name] = isset($interface_usage_count[$possible_interface->name]) ? $interface_usage_count[$possible_interface->name] + 1 : 0;
                }
            }
            // Suggest interface types based on how common they are.
            arsort($interface_usage_count);
            $suggested_interface_types = array_keys($interface_usage_count);
            // Suggest both interface and object types.
            return array_merge($suggested_interface_types, $suggested_object_types);
        }
        // Otherwise, must be an Object type, which does not have suggested types.
        return [];
    }
    /**
     * For the field name provided, determine if there are any similar field names
     * that may be the result of a typo.
     *
     * @throws InvariantViolation
     *
     * @return array<int, string>
     */
    protected function get_suggested_field_names(Type $type, string $field_name): array
    {
        if ($type instanceof Has_Fields_Type) {
            return Utils::suggestion_list($field_name, $type->get_field_names());
        }
        // Otherwise, must be a Union type, which does not define fields.
        return [];
    }
    /**
     * @param array<string> $suggestedTypeNames
     * @param array<string> $suggestedFieldNames
     */
    public static function undefined_field_message(string $field_name, string $type, array $suggested_type_names, array $suggested_field_names): string
    {
        $message = "Cannot query field \"{$field_name}\" on type \"{$type}\".";
        if ($suggested_type_names !== []) {
            $suggestions = Utils::quoted_or_list($suggested_type_names);
            $message .= " Did you mean to use an inline fragment on {$suggestions}?";
        } elseif ($suggested_field_names !== []) {
            $suggestions = Utils::quoted_or_list($suggested_field_names);
            $message .= " Did you mean {$suggestions}?";
        }
        return $message;
    }
}
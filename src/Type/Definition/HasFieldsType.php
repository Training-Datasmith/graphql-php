<?php

declare (strict_types=1);
namespace Graph_Ql\Type\Definition;

use Graph_Ql\Error\Invariant_Violation;
interface Has_Fields_Type
{
    /** @throws InvariantViolation */
    public function get_field(string $name): Field_Definition;
    public function has_field(string $name): bool;
    public function find_field(string $name): ?Field_Definition;
    /**
     * @throws InvariantViolation
     *
     * @return array<string, FieldDefinition>
     */
    public function get_fields(): array;
    /**
     * @throws InvariantViolation
     *
     * @return array<string, FieldDefinition>
     */
    public function get_visible_fields(): array;
    /**
     * Get all field names, including only visible fields.
     *
     * @throws InvariantViolation
     *
     * @return array<int, string>
     */
    public function get_field_names(): array;
}
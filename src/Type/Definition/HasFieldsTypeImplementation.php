<?php

declare (strict_types=1);
namespace Graph_Ql\Type\Definition;

use Graph_Ql\Error\Invariant_Violation;
/**
 * @see HasFieldsType
 */
trait Has_Fields_Type_Implementation
{
    /**
     * Lazily initialized.
     *
     * @var array<string, FieldDefinition|UnresolvedFieldDefinition>
     */
    private array $fields;
    /** @throws InvariantViolation */
    private function initialize_fields(): void
    {
        if (isset($this->fields)) {
            return;
        }
        $this->fields = Field_Definition::define_field_map($this, $this->config['fields']);
    }
    /** @throws InvariantViolation */
    public function get_field(string $name): Field_Definition
    {
        $field = $this->find_field($name);
        if ($field === null) {
            throw new Invariant_Violation("Field \"{$name}\" is not defined for type \"{$this->name}\"");
        }
        return $field;
    }
    /** @throws InvariantViolation */
    public function find_field(string $name): ?Field_Definition
    {
        $this->initialize_fields();
        if (!isset($this->fields[$name])) {
            return null;
        }
        $field = $this->fields[$name];
        if ($field instanceof Unresolved_Field_Definition) {
            return $this->fields[$name] = $field->resolve();
        }
        return $field;
    }
    /** @throws InvariantViolation */
    public function has_field(string $name): bool
    {
        $this->initialize_fields();
        return isset($this->fields[$name]);
    }
    /**
     * @throws InvariantViolation
     *
     * @return array<string, FieldDefinition>
     */
    public function get_fields(): array
    {
        $this->initialize_fields();
        foreach ($this->fields as $name => $field) {
            if ($field instanceof Unresolved_Field_Definition) {
                $this->fields[$name] = $field->resolve();
            }
        }
        // @phpstan-ignore-next-line all field definitions are now resolved
        return $this->fields;
    }
    /** @return array<string, FieldDefinition> */
    public function get_visible_fields(): array
    {
        return array_filter($this->get_fields(), fn(Field_Definition $field_definition): bool => $field_definition->is_visible());
    }
    /** @throws InvariantViolation */
    public function get_field_names(): array
    {
        $this->initialize_fields();
        $visible_field_names = array_map(fn(Field_Definition $field_definition): string => $field_definition->get_name(), $this->get_visible_fields());
        return array_values($visible_field_names);
    }
}
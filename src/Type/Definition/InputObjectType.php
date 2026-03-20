<?php

declare (strict_types=1);
namespace Graph_Ql\Type\Definition;

use Graph_Ql\Error\Error;
use Graph_Ql\Error\Invariant_Violation;
use Graph_Ql\Language\AST\Input_Object_Type_Definition_Node;
use Graph_Ql\Language\AST\Input_Object_Type_Extension_Node;
use Graph_Ql\Utils\Utils;
/**
 * @phpstan-import-type UnnamedInputObjectFieldConfig from InputObjectField
 *
 * @phpstan-type EagerFieldConfig InputObjectField|(Type&InputType)|UnnamedInputObjectFieldConfig
 * @phpstan-type LazyFieldConfig callable(): EagerFieldConfig
 * @phpstan-type FieldConfig EagerFieldConfig|LazyFieldConfig
 * @phpstan-type ParseValueFn callable(array<string, mixed>): mixed
 * @phpstan-type InputObjectConfig array{
 *   name?: string|null,
 *   description?: string|null,
 *   isOneOf?: bool|null,
 *   fields: iterable<FieldConfig>|callable(): iterable<FieldConfig>,
 *   parseValue?: ParseValueFn|null,
 *   astNode?: InputObjectTypeDefinitionNode|null,
 *   extensionASTNodes?: array<InputObjectTypeExtensionNode>|null
 * }
 */
class Input_Object_Type extends Type implements Input_Type, Nullable_Type, Named_Type
{
    use Named_Type_Implementation;
    public bool $is_one_of;
    /**
     * Lazily initialized.
     *
     * @var array<string, InputObjectField>
     */
    private array $fields;
    /** @var ParseValueFn|null */
    private $parse_value;
    public ?Input_Object_Type_Definition_Node $ast_node;
    /** @var array<InputObjectTypeExtensionNode> */
    public array $extension_ast_nodes;
    /** @phpstan-var InputObjectConfig */
    public array $config;
    /**
     * @phpstan-param InputObjectConfig $config
     *
     * @throws InvariantViolation
     * @throws InvariantViolation
     */
    public function __construct(array $config)
    {
        $this->name = $config['name'] ?? $this->infer_name();
        $this->description = $config['description'] ?? null;
        $this->is_one_of = $config['isOneOf'] ?? false;
        // $this->fields is initialized lazily
        $this->parse_value = $config['parseValue'] ?? null;
        $this->ast_node = $config['astNode'] ?? null;
        $this->extension_ast_nodes = $config['extensionASTNodes'] ?? [];
        $this->config = $config;
    }
    /** @throws InvariantViolation */
    public function get_field(string $name): Input_Object_Field
    {
        $field = $this->find_field($name);
        if ($field === null) {
            throw new Invariant_Violation("Field \"{$name}\" is not defined for type \"{$this->name}\"");
        }
        return $field;
    }
    /** @throws InvariantViolation */
    public function find_field(string $name): ?Input_Object_Field
    {
        if (!isset($this->fields)) {
            $this->initialize_fields();
        }
        return $this->fields[$name] ?? null;
    }
    /** @throws InvariantViolation */
    public function has_field(string $name): bool
    {
        if (!isset($this->fields)) {
            $this->initialize_fields();
        }
        return isset($this->fields[$name]);
    }
    /** Returns true if this is a oneOf input object type. */
    public function is_one_of(): bool
    {
        return $this->is_one_of;
    }
    /**
     * @throws InvariantViolation
     *
     * @return array<string, InputObjectField>
     */
    public function get_fields(): array
    {
        if (!isset($this->fields)) {
            $this->initialize_fields();
        }
        return $this->fields;
    }
    /** @throws InvariantViolation */
    protected function initialize_fields(): void
    {
        $fields = $this->config['fields'];
        if (is_callable($fields)) {
            $fields = $fields();
        }
        $this->fields = [];
        foreach ($fields as $name_or_index => $field) {
            $this->initialize_field($name_or_index, $field);
        }
    }
    /**
     * @param string|int $nameOrIndex
     *
     * @phpstan-param FieldConfig $field
     *
     * @throws InvariantViolation
     */
    protected function initialize_field($name_or_index, $field): void
    {
        if (is_callable($field)) {
            $field = $field();
        }
        assert($field instanceof Type || is_array($field) || $field instanceof Input_Object_Field);
        if ($field instanceof Type) {
            $field = ['type' => $field];
        }
        assert(is_array($field) || $field instanceof Input_Object_Field);
        // @phpstan-ignore-line TODO remove when using actual union types
        if (is_array($field)) {
            $field['name'] ??= $name_or_index;
            if (!is_string($field['name'])) {
                throw new Invariant_Violation("{$this->name} fields must be an associative array with field names as keys, an array of arrays with a name attribute, or a callable which returns one of those.");
            }
            $field = new Input_Object_Field($field);
            // @phpstan-ignore-line array type is wrongly inferred
        }
        assert($field instanceof Input_Object_Field);
        // @phpstan-ignore-line TODO remove when using actual union types
        $this->fields[$field->name] = $field;
    }
    /**
     * Parses an externally provided value (query variable) to use as an input.
     *
     * Should throw an exception with a client-friendly message on invalid values, @see ClientAware.
     *
     * @param array<string, mixed> $value
     *
     * @return mixed
     */
    public function parse_value(array $value)
    {
        if (isset($this->parse_value)) {
            return ($this->parse_value)($value);
        }
        return $value;
    }
    /**
     * Validates type config and throws if one of the type options is invalid.
     * Note: this method is shallow, it won't validate object fields and their arguments.
     *
     * @throws Error
     * @throws InvariantViolation
     */
    public function assert_valid(): void
    {
        Utils::assert_valid_name($this->name);
        $fields = $this->config['fields'] ?? null;
        // @phpstan-ignore nullCoalesce.initializedProperty (unnecessary according to types, but can happen during runtime)
        if (is_callable($fields)) {
            $fields = $fields();
        }
        if (!is_iterable($fields)) {
            $invalid_fields = Utils::print_safe($fields);
            throw new Invariant_Violation("{$this->name} fields must be an iterable or a callable which returns an iterable, got: {$invalid_fields}.");
        }
        $resolved_fields = $this->get_fields();
        foreach ($resolved_fields as $field) {
            $field->assert_valid($this);
        }
        // Additional validation for oneOf input objects
        if ($this->is_one_of()) {
            $this->validate_one_of_constraints($resolved_fields);
        }
    }
    /**
     * Validates that oneOf input object constraints are met.
     *
     * @param array<string, InputObjectField> $fields
     *
     * @throws InvariantViolation
     */
    private function validate_one_of_constraints(array $fields): void
    {
        if (count($fields) === 0) {
            throw new Invariant_Violation("OneOf input object type {$this->name} must define one or more fields.");
        }
        foreach ($fields as $field_name => $field) {
            $field_type = $field->get_type();
            // OneOf fields must be nullable (not wrapped in NonNull)
            if ($field_type instanceof Non_Null) {
                throw new Invariant_Violation("OneOf input object type {$this->name} field {$field_name} must be nullable.");
            }
            // OneOf fields cannot have default values
            if ($field->default_value_exists()) {
                throw new Invariant_Violation("OneOf input object type {$this->name} field {$field_name} cannot have a default value.");
            }
        }
    }
    public function ast_node(): ?Input_Object_Type_Definition_Node
    {
        return $this->ast_node;
    }
    /** @return array<InputObjectTypeExtensionNode> */
    public function extension_ast_nodes(): array
    {
        return $this->extension_ast_nodes;
    }
}
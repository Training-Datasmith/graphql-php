<?php

declare (strict_types=1);
namespace Graph_Ql\Type\Definition;

use Graph_Ql\Error\Invariant_Violation;
use Graph_Ql\Executor\Executor;
use Graph_Ql\Language\AST\Field_Definition_Node;
use Graph_Ql\Type\Schema;
use Graph_Ql\Utils\Utils;
/**
 * @see Executor
 *
 * @phpstan-import-type FieldResolver from Executor
 * @phpstan-import-type ArgsMapper from Executor
 * @phpstan-import-type ArgumentListConfig from Argument
 *
 * @phpstan-type FieldType (Type&OutputType)|callable(): (Type&OutputType)
 * @phpstan-type ComplexityFn callable(int, array<string, mixed>): int
 * @phpstan-type VisibilityFn callable(): bool
 * @phpstan-type FieldDefinitionConfig array{
 *     name: string,
 *     type: FieldType,
 *     resolve?: FieldResolver|null,
 *     args?: ArgumentListConfig|null,
 *     argsMapper?: ArgsMapper|null,
 *     description?: string|null,
 *     visible?: VisibilityFn|bool,
 *     deprecationReason?: string|null,
 *     astNode?: FieldDefinitionNode|null,
 *     complexity?: ComplexityFn|null
 * }
 * @phpstan-type UnnamedFieldDefinitionConfig array{
 *     type: FieldType,
 *     resolve?: FieldResolver|null,
 *     args?: ArgumentListConfig|null,
 *     argsMapper?: ArgsMapper|null,
 *     description?: string|null,
 *     visible?: VisibilityFn|bool,
 *     deprecationReason?: string|null,
 *     astNode?: FieldDefinitionNode|null,
 *     complexity?: ComplexityFn|null
 * }
 * @phpstan-type FieldsConfig iterable<mixed>|callable(): iterable<mixed>
 */
/*
 * TODO check if newer versions of PHPStan can handle the full definition, it currently crashes when it is used
 * @phpstan-type EagerListEntry FieldDefinitionConfig|(Type&OutputType)
 * @phpstan-type EagerMapEntry UnnamedFieldDefinitionConfig|FieldDefinition
 * @phpstan-type FieldsList iterable<EagerListEntry|(callable(): EagerListEntry)>
 * @phpstan-type FieldsMap iterable<string, EagerMapEntry|(callable(): EagerMapEntry)>
 * @phpstan-type FieldsIterable FieldsList|FieldsMap
 * @phpstan-type FieldsConfig FieldsIterable|(callable(): FieldsIterable)
 */
class Field_Definition
{
    public string $name;
    /** @var array<int, Argument> */
    public array $args;
    /**
     * Callback to transform args to value object.
     *
     * @var callable|null
     *
     * @phpstan-var ArgsMapper|null
     */
    public $args_mapper;
    /**
     * Callback for resolving field value given parent value.
     *
     * @var callable|null
     *
     * @phpstan-var FieldResolver|null
     */
    public $resolve_fn;
    public ?string $description;
    /**
     * @var callable|bool
     *
     * @phpstan-var VisibilityFn|bool
     */
    public $visible;
    public ?string $deprecation_reason;
    public ?Field_Definition_Node $ast_node;
    /**
     * @var callable|null
     *
     * @phpstan-var ComplexityFn|null
     */
    public $complexity_fn;
    /**
     * Original field definition config.
     *
     * @phpstan-var FieldDefinitionConfig
     */
    public array $config;
    /** @var Type&OutputType */
    private Type $type;
    /** @param FieldDefinitionConfig $config */
    public function __construct(array $config)
    {
        $this->name = $config['name'];
        $this->resolve_fn = $config['resolve'] ?? null;
        $this->args = isset($config['args']) ? Argument::list_from_config($config['args']) : [];
        $this->args_mapper = $config['argsMapper'] ?? null;
        $this->description = $config['description'] ?? null;
        $this->visible = $config['visible'] ?? true;
        $this->deprecation_reason = $config['deprecationReason'] ?? null;
        $this->ast_node = $config['astNode'] ?? null;
        $this->complexity_fn = $config['complexity'] ?? null;
        $this->config = $config;
    }
    /**
     * @param ObjectType|InterfaceType $parentType
     * @param callable|iterable $fields
     *
     * @phpstan-param FieldsConfig $fields
     *
     * @throws InvariantViolation
     *
     * @return array<string, self|UnresolvedFieldDefinition>
     */
    public static function define_field_map(Type $parent_type, $fields): array
    {
        if (is_callable($fields)) {
            $fields = $fields();
        }
        if (!is_iterable($fields)) {
            throw new Invariant_Violation("{$parent_type->name} fields must be an iterable or a callable which returns such an iterable.");
        }
        $map = [];
        foreach ($fields as $maybe_name => $field) {
            if (is_array($field)) {
                if (!isset($field['name'])) {
                    if (!is_string($maybe_name)) {
                        throw new Invariant_Violation("{$parent_type->name} fields must be an associative array with field names as keys or a function which returns such an array.");
                    }
                    $field['name'] = $maybe_name;
                }
                // @phpstan-ignore-next-line PHPStan won't let us define the whole type
                $field_def = new self($field);
            } elseif ($field instanceof self) {
                $field_def = $field;
            } elseif (is_callable($field)) {
                if (!is_string($maybe_name)) {
                    throw new Invariant_Violation("{$parent_type->name} lazy fields must be an associative array with field names as keys.");
                }
                $field_def = new Unresolved_Field_Definition($maybe_name, $field);
            } elseif ($field instanceof Type) {
                // @phpstan-ignore-next-line PHPStan won't let us define the whole type
                $field_def = new self(['name' => $maybe_name, 'type' => $field]);
            } else {
                $invalid_field_config = Utils::print_safe($field);
                throw new Invariant_Violation("{$parent_type->name}.{$maybe_name} field config must be an array, but got: {$invalid_field_config}");
            }
            $map[$field_def->get_name()] = $field_def;
        }
        return $map;
    }
    public function get_arg(string $name): ?Argument
    {
        foreach ($this->args as $arg) {
            if ($arg->name === $name) {
                return $arg;
            }
        }
        return null;
    }
    public function get_name(): string
    {
        return $this->name;
    }
    /** @return Type&OutputType */
    public function get_type(): Type
    {
        return $this->type ??= Schema::resolve_type($this->config['type']);
    }
    public function is_visible(): bool
    {
        if (is_bool($this->visible)) {
            return $this->visible;
        }
        return $this->visible = ($this->visible)();
    }
    public function is_deprecated(): bool
    {
        return (bool) $this->deprecation_reason;
    }
    /**
     * @param Type&NamedType $parentType
     *
     * @throws InvariantViolation
     */
    public function assert_valid(Type $parent_type): void
    {
        $error = Utils::is_valid_name_error($this->name);
        if ($error !== null) {
            throw new Invariant_Violation("{$parent_type->name}.{$this->name}: {$error->get_message()}");
        }
        $type = Type::get_named_type($this->get_type());
        if (!$type instanceof Output_Type) {
            $safe_type = Utils::print_safe($this->type);
            throw new Invariant_Violation("{$parent_type->name}.{$this->name} field type must be Output Type but got: {$safe_type}.");
        }
        // @phpstan-ignore-next-line unnecessary according to types, but can happen during runtime
        if ($this->resolve_fn !== null && !is_callable($this->resolve_fn)) {
            $safe_resolve_fn = Utils::print_safe($this->resolve_fn);
            throw new Invariant_Violation("{$parent_type->name}.{$this->name} field resolver must be a function if provided, but got: {$safe_resolve_fn}.");
        }
        foreach ($this->args as $field_argument) {
            $field_argument->assert_valid($this, $type);
        }
    }
}
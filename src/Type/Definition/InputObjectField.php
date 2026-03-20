<?php

declare (strict_types=1);
namespace Graph_Ql\Type\Definition;

use Graph_Ql\Error\Invariant_Violation;
use Graph_Ql\Language\AST\Input_Value_Definition_Node;
use Graph_Ql\Type\Schema;
use Graph_Ql\Utils\Utils;
/**
 * @phpstan-type ArgumentType (Type&InputType)|callable(): (Type&InputType)
 * @phpstan-type InputObjectFieldConfig array{
 *   name: string,
 *   type: ArgumentType,
 *   defaultValue?: mixed,
 *   description?: string|null,
 *   deprecationReason?: string|null,
 *   astNode?: InputValueDefinitionNode|null
 * }
 * @phpstan-type UnnamedInputObjectFieldConfig array{
 *   name?: string,
 *   type: ArgumentType,
 *   defaultValue?: mixed,
 *   description?: string|null,
 *   deprecationReason?: string|null,
 *   astNode?: InputValueDefinitionNode|null
 * }
 */
class Input_Object_Field
{
    public string $name;
    /** @var mixed */
    public $default_value;
    public ?string $description;
    public ?string $deprecation_reason;
    /** @var Type&InputType */
    private Type $type;
    public ?Input_Value_Definition_Node $ast_node;
    /** @phpstan-var InputObjectFieldConfig */
    public array $config;
    /** @phpstan-param InputObjectFieldConfig $config */
    public function __construct(array $config)
    {
        $this->name = $config['name'];
        $this->default_value = $config['defaultValue'] ?? null;
        $this->description = $config['description'] ?? null;
        $this->deprecation_reason = $config['deprecationReason'] ?? null;
        // Do nothing for type, it is lazy loaded in getType()
        $this->ast_node = $config['astNode'] ?? null;
        $this->config = $config;
    }
    /** @return Type&InputType */
    public function get_type(): Type
    {
        if (!isset($this->type)) {
            $this->type = Schema::resolve_type($this->config['type']);
        }
        return $this->type;
    }
    public function default_value_exists(): bool
    {
        return array_key_exists('defaultValue', $this->config);
    }
    public function is_required(): bool
    {
        return $this->get_type() instanceof Non_Null && !$this->default_value_exists();
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
        if (!$type instanceof Input_Type) {
            $not_input_type = Utils::print_safe($this->type);
            throw new Invariant_Violation("{$parent_type->name}.{$this->name} field type must be Input Type but got: {$not_input_type}");
        }
        // @phpstan-ignore-next-line should not happen if used properly
        if (array_key_exists('resolve', $this->config)) {
            throw new Invariant_Violation("{$parent_type->name}.{$this->name} field has a resolve property, but Input Types cannot define resolvers.");
        }
        if ($this->is_required() && $this->is_deprecated()) {
            throw new Invariant_Violation("Required input field {$parent_type->name}.{$this->name} cannot be deprecated.");
        }
    }
}
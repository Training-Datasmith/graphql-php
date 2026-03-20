<?php

declare (strict_types=1);
namespace Graph_Ql\Type\Definition;

use Graph_Ql\Error\Invariant_Violation;
use Graph_Ql\Language\AST\Input_Value_Definition_Node;
use Graph_Ql\Type\Schema;
use Graph_Ql\Utils\Utils;
/**
 * @phpstan-type ArgumentType (Type&InputType)|callable(): (Type&InputType)
 * @phpstan-type UnnamedArgumentConfig array{
 *     name?: string,
 *     type: ArgumentType,
 *     defaultValue?: mixed,
 *     description?: string|null,
 *     deprecationReason?: string|null,
 *     astNode?: InputValueDefinitionNode|null
 * }
 * @phpstan-type ArgumentConfig array{
 *     name: string,
 *     type: ArgumentType,
 *     defaultValue?: mixed,
 *     description?: string|null,
 *     deprecationReason?: string|null,
 *     astNode?: InputValueDefinitionNode|null
 * }
 * @phpstan-type ArgumentListConfig iterable<ArgumentConfig|ArgumentType>|iterable<UnnamedArgumentConfig>
 */
class Argument
{
    public string $name;
    /** @var mixed */
    public $default_value;
    public ?string $description;
    public ?string $deprecation_reason;
    /** @var Type&InputType */
    private Type $type;
    public ?Input_Value_Definition_Node $ast_node;
    /** @phpstan-var ArgumentConfig */
    public array $config;
    /** @phpstan-param ArgumentConfig $config */
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
    /**
     * @phpstan-param ArgumentListConfig $config
     *
     * @return array<int, self>
     */
    public static function list_from_config(iterable $config): array
    {
        $list = [];
        foreach ($config as $name => $arg_config) {
            if (!is_array($arg_config)) {
                $arg_config = ['type' => $arg_config];
            }
            /** @phpstan-var ArgumentConfig $argConfigWithName */
            $arg_config_with_name = $arg_config + ['name' => $name];
            $list[] = new self($arg_config_with_name);
        }
        return $list;
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
    public function assert_valid(Field_Definition $parent_field, Type $parent_type): void
    {
        $error = Utils::is_valid_name_error($this->name);
        if ($error !== null) {
            throw new Invariant_Violation("{$parent_type->name}.{$parent_field->name}({$this->name}:) {$error->get_message()}");
        }
        $type = Type::get_named_type($this->get_type());
        if (!$type instanceof Input_Type) {
            $not_input_type = Utils::print_safe($this->type);
            throw new Invariant_Violation("{$parent_type->name}.{$parent_field->name}({$this->name}): argument type must be Input Type but got: {$not_input_type}");
        }
        if ($this->is_required() && $this->is_deprecated()) {
            throw new Invariant_Violation("Required argument {$parent_type->name}.{$parent_field->name}({$this->name}:) cannot be deprecated.");
        }
    }
}
<?php

declare (strict_types=1);
namespace Graph_Ql\Type\Definition;

use Graph_Ql\Error\Error;
use Graph_Ql\Error\Invariant_Violation;
use Graph_Ql\Error\Serialization_Error;
use Graph_Ql\Language\AST\Enum_Type_Definition_Node;
use Graph_Ql\Language\AST\Enum_Type_Extension_Node;
use Graph_Ql\Language\AST\Enum_Value_Definition_Node;
use Graph_Ql\Language\AST\Enum_Value_Node;
use Graph_Ql\Language\AST\Node;
use Graph_Ql\Language\Printer;
use Graph_Ql\Utils\Mixed_Store;
use Graph_Ql\Utils\Utils;
/**
 * @see EnumValueDefinitionNode
 *
 * @phpstan-type PartialEnumValueConfig array{
 *   name?: string,
 *   value?: mixed,
 *   deprecationReason?: string|null,
 *   description?: string|null,
 *   astNode?: EnumValueDefinitionNode|null
 * }
 * @phpstan-type EnumValues iterable<string, PartialEnumValueConfig>|iterable<string, mixed>|iterable<int, string>
 * @phpstan-type EnumTypeConfig array{
 *   name?: string|null,
 *   description?: string|null,
 *   values: EnumValues|callable(): EnumValues,
 *   astNode?: EnumTypeDefinitionNode|null,
 *   extensionASTNodes?: array<EnumTypeExtensionNode>|null
 * }
 */
class Enum_Type extends Type implements Input_Type, Output_Type, Leaf_Type, Nullable_Type, Named_Type
{
    use Named_Type_Implementation;
    public ?Enum_Type_Definition_Node $ast_node;
    /** @var array<EnumTypeExtensionNode> */
    public array $extension_ast_nodes;
    /** @phpstan-var EnumTypeConfig */
    public array $config;
    /**
     * Lazily initialized.
     *
     * @var array<int, EnumValueDefinition>
     */
    private array $values;
    /**
     * Lazily initialized.
     *
     * @var MixedStore<EnumValueDefinition>
     */
    private Mixed_Store $value_lookup;
    /** @var array<string, EnumValueDefinition> */
    private array $name_lookup;
    /**
     * @phpstan-param EnumTypeConfig $config
     *
     * @throws InvariantViolation
     */
    public function __construct(array $config)
    {
        $this->name = $config['name'] ?? $this->infer_name();
        $this->description = $config['description'] ?? null;
        $this->ast_node = $config['astNode'] ?? null;
        $this->extension_ast_nodes = $config['extensionASTNodes'] ?? [];
        $this->config = $config;
    }
    /** @throws InvariantViolation */
    public function get_value(string $name): ?Enum_Value_Definition
    {
        if (!isset($this->name_lookup)) {
            $this->initialize_name_lookup();
        }
        return $this->name_lookup[$name] ?? null;
    }
    /**
     * @throws InvariantViolation
     *
     * @return array<int, EnumValueDefinition>
     */
    public function get_values(): array
    {
        if (!isset($this->values)) {
            $this->values = [];
            $values = $this->config['values'];
            if (is_callable($values)) {
                $values = $values();
            }
            // We are just assuming the config option is set correctly here, validation happens in assertValid()
            foreach ($values as $name => $value) {
                if (is_string($name)) {
                    if (is_array($value)) {
                        $value += ['name' => $name, 'value' => $name];
                    } else {
                        $value = ['name' => $name, 'value' => $value];
                    }
                } elseif (is_string($value)) {
                    $value = ['name' => $value, 'value' => $value];
                } else {
                    throw new Invariant_Violation("{$this->name} values must be an array with value names as keys or values.");
                }
                // @phpstan-ignore-next-line assume the config matches
                $this->values[] = new Enum_Value_Definition($value);
            }
        }
        return $this->values;
    }
    /**
     * @throws \InvalidArgumentException
     * @throws InvariantViolation
     * @throws SerializationError
     */
    public function serialize($value)
    {
        $lookup = $this->get_value_lookup();
        if (isset($lookup[$value])) {
            return $lookup[$value]->name;
        }
        if ($value instanceof \Backed_Enum) {
            return $value->name;
        }
        if ($value instanceof \Unit_Enum) {
            return $value->name;
        }
        $safe_value = Utils::print_safe($value);
        throw new Serialization_Error("Cannot serialize value as enum: {$safe_value}");
    }
    /**
     * @throws \InvalidArgumentException
     * @throws InvariantViolation
     *
     * @return MixedStore<EnumValueDefinition>
     */
    private function get_value_lookup(): Mixed_Store
    {
        if (!isset($this->value_lookup)) {
            $this->value_lookup = new Mixed_Store();
            foreach ($this->get_values() as $value) {
                $this->value_lookup->offsetSet($value->value, $value);
            }
        }
        return $this->value_lookup;
    }
    /**
     * @throws Error
     * @throws InvariantViolation
     */
    public function parse_value($value)
    {
        if (!is_string($value)) {
            $safe_value = Utils::print_safe_json($value);
            throw new Error("Enum \"{$this->name}\" cannot represent non-string value: {$safe_value}.{$this->did_you_mean($safe_value)}");
        }
        if (!isset($this->name_lookup)) {
            $this->initialize_name_lookup();
        }
        if (!isset($this->name_lookup[$value])) {
            throw new Error("Value \"{$value}\" does not exist in \"{$this->name}\" enum.{$this->did_you_mean($value)}");
        }
        return $this->name_lookup[$value]->value;
    }
    /**
     * @throws \JsonException
     * @throws Error
     * @throws InvariantViolation
     */
    public function parse_literal(Node $value_node, ?array $variables = null)
    {
        if (!$value_node instanceof Enum_Value_Node) {
            $value_str = Printer::do_print($value_node);
            throw new Error("Enum \"{$this->name}\" cannot represent non-enum value: {$value_str}.{$this->did_you_mean($value_str)}", $value_node);
        }
        $name = $value_node->value;
        if (!isset($this->name_lookup)) {
            $this->initialize_name_lookup();
        }
        if (isset($this->name_lookup[$name])) {
            return $this->name_lookup[$name]->value;
        }
        $value_str = Printer::do_print($value_node);
        throw new Error("Value \"{$value_str}\" does not exist in \"{$this->name}\" enum.{$this->did_you_mean($value_str)}", $value_node);
    }
    /**
     * @throws Error
     * @throws InvariantViolation
     */
    public function assert_valid(): void
    {
        Utils::assert_valid_name($this->name);
        $values = $this->config['values'] ?? null;
        // @phpstan-ignore nullCoalesce.initializedProperty (unnecessary according to types, but can happen during runtime)
        if (!is_iterable($values) && !is_callable($values)) {
            $not_iterable = Utils::print_safe($values);
            throw new Invariant_Violation("{$this->name} values must be an iterable or callable, got: {$not_iterable}");
        }
        $this->get_values();
    }
    /** @throws InvariantViolation */
    private function initialize_name_lookup(): void
    {
        $this->name_lookup = [];
        foreach ($this->get_values() as $value) {
            $this->name_lookup[$value->name] = $value;
        }
    }
    /** @throws InvariantViolation */
    protected function did_you_mean(string $unknown_value): ?string
    {
        $suggestions = Utils::suggestion_list($unknown_value, array_map(static fn(Enum_Value_Definition $value): string => $value->name, $this->get_values()));
        return $suggestions === [] ? null : ' Did you mean the enum value ' . Utils::quoted_or_list($suggestions) . '?';
    }
    public function ast_node(): ?Enum_Type_Definition_Node
    {
        return $this->ast_node;
    }
    /** @return array<EnumTypeExtensionNode> */
    public function extension_ast_nodes(): array
    {
        return $this->extension_ast_nodes;
    }
}
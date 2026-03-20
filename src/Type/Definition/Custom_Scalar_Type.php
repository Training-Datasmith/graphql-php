<?php

declare (strict_types=1);
namespace Graph_Ql\Type\Definition;

use Graph_Ql\Error\Error;
use Graph_Ql\Error\Invariant_Violation;
use Graph_Ql\Language\AST\Node;
use Graph_Ql\Language\AST\Scalar_Type_Definition_Node;
use Graph_Ql\Language\AST\Scalar_Type_Extension_Node;
use Graph_Ql\Language\AST\Value_Node;
use Graph_Ql\Utils\AST;
use Graph_Ql\Utils\Utils;
/**
 * @phpstan-type InputCustomScalarConfig array{
 *   name?: string|null,
 *   description?: string|null,
 *   serialize?: callable(mixed): mixed,
 *   parseValue: callable(mixed): mixed,
 *   parseLiteral: callable(ValueNode&Node, array<string, mixed>|null): mixed,
 *   astNode?: ScalarTypeDefinitionNode|null,
 *   extensionASTNodes?: array<ScalarTypeExtensionNode>|null
 * }
 * @phpstan-type OutputCustomScalarConfig array{
 *   name?: string|null,
 *   description?: string|null,
 *   serialize: callable(mixed): mixed,
 *   parseValue?: callable(mixed): mixed,
 *   parseLiteral?: callable(ValueNode&Node, array<string, mixed>|null): mixed,
 *   astNode?: ScalarTypeDefinitionNode|null,
 *   extensionASTNodes?: array<ScalarTypeExtensionNode>|null
 * }
 * @phpstan-type CustomScalarConfig InputCustomScalarConfig|OutputCustomScalarConfig
 */
class Custom_Scalar_Type extends Scalar_Type
{
    /** @phpstan-var CustomScalarConfig */
    // @phpstan-ignore-next-line specialize type
    public array $config;
    public function serialize($value)
    {
        if (isset($this->config['serialize'])) {
            return $this->config['serialize']($value);
        }
        return $value;
    }
    public function parse_value($value)
    {
        if (isset($this->config['parseValue'])) {
            return $this->config['parseValue']($value);
        }
        return $value;
    }
    /** @throws \Exception */
    public function parse_literal(Node $value_node, ?array $variables = null)
    {
        if (isset($this->config['parseLiteral'])) {
            return $this->config['parseLiteral']($value_node, $variables);
        }
        return AST::value_from_ast_untyped($value_node, $variables);
    }
    /**
     * @throws Error
     * @throws InvariantViolation
     */
    public function assert_valid(): void
    {
        parent::assert_valid();
        $serialize = $this->config['serialize'] ?? null;
        $parse_value = $this->config['parseValue'] ?? null;
        $parse_literal = $this->config['parseLiteral'] ?? null;
        $has_serialize = $serialize !== null;
        $has_parse_value = $parse_value !== null;
        $has_parse_literal = $parse_literal !== null;
        $has_parse = $has_parse_value && $has_parse_literal;
        if ($has_parse_value !== $has_parse_literal) {
            throw new Invariant_Violation("{$this->name} must provide both \"parseValue\" and \"parseLiteral\" functions to work as an input type.");
        }
        if (!$has_serialize && !$has_parse) {
            throw new Invariant_Violation("{$this->name} must provide \"parseValue\" and \"parseLiteral\" functions, \"serialize\" function, or both.");
        }
        // @phpstan-ignore-next-line unnecessary according to types, but can happen during runtime
        if ($has_serialize && !is_callable($serialize)) {
            $not_callable = Utils::print_safe($serialize);
            throw new Invariant_Violation("{$this->name} must provide \"serialize\" as a callable if given, but got: {$not_callable}.");
        }
        // @phpstan-ignore-next-line unnecessary according to types, but can happen during runtime
        if ($has_parse_value && !is_callable($parse_value)) {
            $not_callable = Utils::print_safe($parse_value);
            throw new Invariant_Violation("{$this->name} must provide \"parseValue\" as a callable if given, but got: {$not_callable}.");
        }
        // @phpstan-ignore-next-line unnecessary according to types, but can happen during runtime
        if ($has_parse_literal && !is_callable($parse_literal)) {
            $not_callable = Utils::print_safe($parse_literal);
            throw new Invariant_Violation("{$this->name} must provide \"parseLiteral\" as a callable if given, but got: {$not_callable}.");
        }
    }
}
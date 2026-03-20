<?php

declare (strict_types=1);
namespace Graph_Ql\Language\AST;

use Graph_Ql\Error\Invariant_Violation;
use Graph_Ql\Utils\Utils;
/**
 * type Node = NameNode
 * | DocumentNode
 * | OperationDefinitionNode
 * | VariableDefinitionNode
 * | VariableNode
 * | SelectionSetNode
 * | FieldNode
 * | ArgumentNode
 * | FragmentSpreadNode
 * | InlineFragmentNode
 * | FragmentDefinitionNode
 * | IntValueNode
 * | FloatValueNode
 * | StringValueNode
 * | BooleanValueNode
 * | EnumValueNode
 * | ListValueNode
 * | ObjectValueNode
 * | ObjectFieldNode
 * | DirectiveNode
 * | ListTypeNode
 * | NonNullTypeNode.
 *
 * @see \GraphQL\Tests\Language\AST\NodeTest
 */
abstract class Node implements \JsonSerializable
{
    public ?Location $loc = null;
    public string $kind;
    /** @param array<string, mixed> $vars */
    public function __construct(array $vars)
    {
        Utils::assign($this, $vars);
    }
    /**
     * Returns a clone of this instance and all its children, except Location $loc.
     *
     * @throws \JsonException
     * @throws InvariantViolation
     *
     * @return static
     */
    public function clone_deep(): self
    {
        return static::clone_value($this);
    }
    /**
     * @template TNode of Node
     * @template TCloneable of TNode|NodeList<TNode>|Location|string
     *
     * @phpstan-param TCloneable $value
     *
     * @throws \JsonException
     * @throws InvariantViolation
     *
     * @phpstan-return TCloneable
     */
    protected static function clone_value($value)
    {
        if ($value instanceof self) {
            $cloned = clone $value;
            foreach (get_object_vars($cloned) as $prop => $prop_value) {
                $cloned->{$prop} = static::clone_value($prop_value);
                // @phpstan-ignore argument.templateType
            }
            return $cloned;
        }
        if ($value instanceof Node_List) {
            /**
             * @phpstan-var TCloneable
             *
             * @phpstan-ignore varTag.nativeType (PHPStan is strict about template types and sees NodeList<TNode> as potentially different from TCloneable)
             */
            return $value->clone_deep();
        }
        return $value;
    }
    /** @throws \JsonException */
    public function __toString(): string
    {
        return json_encode($this, JSON_THROW_ON_ERROR);
    }
    /**
     * Improves upon the default serialization by:
     * - excluding null values
     * - excluding large reference values such as @see Location::$source.
     *
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return $this->to_array();
    }
    /** @return array<string, mixed> */
    public function to_array(): array
    {
        return self::recursive_to_array($this);
    }
    /** @return array<string, mixed> */
    private static function recursive_to_array(Node $node): array
    {
        $result = [];
        foreach (get_object_vars($node) as $prop => $prop_value) {
            if ($prop_value === null) {
                continue;
            }
            if ($prop_value instanceof Node_List) {
                $converted = [];
                foreach ($prop_value as $item) {
                    $converted[] = self::recursive_to_array($item);
                }
            } elseif ($prop_value instanceof Node) {
                $converted = self::recursive_to_array($prop_value);
            } elseif ($prop_value instanceof Location) {
                $converted = $prop_value->to_array();
            } else {
                $converted = $prop_value;
            }
            $result[$prop] = $converted;
        }
        return $result;
    }
}
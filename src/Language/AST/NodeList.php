<?php

declare (strict_types=1);
namespace Graph_Ql\Language\AST;

use Graph_Ql\Error\Invariant_Violation;
use Graph_Ql\Utils\AST;
/**
 * @template T of Node
 *
 * @phpstan-implements \ArrayAccess<array-key, T>
 * @phpstan-implements \IteratorAggregate<array-key, T>
 */
class Node_List implements \ArrayAccess, \IteratorAggregate, \Countable
{
    /**
     * @var array<Node|array>
     *
     * @phpstan-var array<T|array<string, mixed>>
     */
    private array $nodes;
    /**
     * @param array<Node|array> $nodes
     *
     * @phpstan-param array<T|array<string, mixed>> $nodes
     */
    public function __construct(array $nodes)
    {
        $this->nodes = $nodes;
    }
    /** @param int|string $offset */
    #[\Return_Type_Will_Change]
    public function offsetExists($offset): bool
    {
        return isset($this->nodes[$offset]);
    }
    /**
     * @param int|string $offset
     *
     * @phpstan-return T
     */
    #[\Return_Type_Will_Change]
    public function offsetGet($offset): Node
    {
        $item = $this->nodes[$offset];
        if (is_array($item)) {
            // @phpstan-ignore-next-line not really possible to express the correctness of this in PHP
            return $this->nodes[$offset] = AST::from_array($item);
        }
        return $item;
    }
    /**
     * @param int|string|null $offset
     * @param Node|array<string, mixed> $value
     *
     * @phpstan-param T|array<string, mixed> $value
     *
     * @throws \JsonException
     * @throws InvariantViolation
     */
    #[\Return_Type_Will_Change]
    public function offsetSet($offset, $value): void
    {
        if (is_array($value)) {
            /** @phpstan-var T $value */
            $value = AST::from_array($value);
        }
        // Happens when a Node is pushed via []=
        if ($offset === null) {
            $this->nodes[] = $value;
            return;
        }
        $this->nodes[$offset] = $value;
    }
    /** @param int|string $offset */
    #[\Return_Type_Will_Change]
    public function offsetUnset($offset): void
    {
        unset($this->nodes[$offset]);
    }
    public function getIterator(): \Traversable
    {
        foreach ($this->nodes as $key => $_) {
            yield $key => $this->offsetGet($key);
        }
    }
    public function count(): int
    {
        return count($this->nodes);
    }
    /**
     * Remove a portion of the NodeList and replace it with something else.
     *
     * @param T|iterable<T>|null $replacement
     *
     * @phpstan-return NodeList<T> the NodeList with the extracted elements
     */
    public function splice(int $offset, int $length, $replacement = null): Node_List
    {
        if (is_iterable($replacement) && !is_array($replacement)) {
            $replacement = iterator_to_array($replacement);
        }
        return new Node_List(array_splice($this->nodes, $offset, $length, $replacement));
    }
    /**
     * @phpstan-param iterable<array-key, T> $list
     *
     * @phpstan-return NodeList<T>
     */
    public function merge(iterable $list): Node_List
    {
        if (!is_array($list)) {
            $list = iterator_to_array($list);
        }
        return new Node_List(array_merge($this->nodes, $list));
    }
    /** Resets the keys of the stored nodes to contiguous numeric indexes. */
    public function reindex(): void
    {
        $this->nodes = array_values($this->nodes);
    }
    /**
     * Returns a clone of this instance and all its children, except Location $loc.
     *
     * @throws \JsonException
     * @throws InvariantViolation
     *
     * @return static<T>
     */
    public function clone_deep(): self
    {
        /** @var array<T> $empty */
        $empty = [];
        $cloned = new static($empty);
        foreach ($this->getIterator() as $key => $node) {
            $cloned[$key] = $node->clone_deep();
        }
        return $cloned;
    }
}
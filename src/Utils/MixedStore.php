<?php

declare (strict_types=1);
namespace Graph_Ql\Utils;

/**
 * Similar to PHP array, but allows any type of data to act as key (including arrays, objects, scalars).
 *
 * When storing array as key, access and modification is O(N). Avoid if possible.
 *
 * @template TValue of mixed
 *
 * @implements \ArrayAccess<mixed, TValue>
 *
 * @see \GraphQL\Tests\Utils\MixedStoreTest
 */
class Mixed_Store implements \ArrayAccess
{
    /** @var array<TValue> */
    private array $standard_store = [];
    /** @var array<TValue> */
    private array $float_store = [];
    /** @var \SplObjectStorage<object, TValue> */
    private \Spl_Object_Storage $object_store;
    /** @var array<int, array<mixed>> */
    private array $array_keys = [];
    /** @var array<int, TValue> */
    private array $array_values = [];
    /** @var array<mixed> */
    private ?array $last_array_key = null;
    /** @var TValue|null */
    private $last_array_value;
    /** @var TValue|null */
    private $null_value;
    private bool $null_value_is_set = false;
    /** @var TValue|null */
    private $true_value;
    private bool $true_value_is_set = false;
    /** @var TValue|null */
    private $false_value;
    private bool $false_value_is_set = false;
    public function __construct()
    {
        $this->object_store = new \Spl_Object_Storage();
    }
    /** @param mixed $offset */
    #[\Return_Type_Will_Change]
    public function offsetExists($offset): bool
    {
        if ($offset === false) {
            return $this->false_value_is_set;
        }
        if ($offset === true) {
            return $this->true_value_is_set;
        }
        if (is_int($offset) || is_string($offset)) {
            return array_key_exists($offset, $this->standard_store);
        }
        if (is_float($offset)) {
            return array_key_exists((string) $offset, $this->float_store);
        }
        if (is_object($offset)) {
            return $this->object_store->offsetExists($offset);
        }
        if (is_array($offset)) {
            foreach ($this->array_keys as $index => $entry) {
                if ($entry === $offset) {
                    $this->last_array_key = $offset;
                    $this->last_array_value = $this->array_values[$index];
                    return true;
                }
            }
        }
        if ($offset === null) {
            return $this->null_value_is_set;
        }
        return false;
    }
    /**
     * @param mixed $offset
     *
     * @return TValue|null
     */
    #[\Return_Type_Will_Change]
    public function offsetGet($offset)
    {
        if ($offset === true) {
            return $this->true_value;
        }
        if ($offset === false) {
            return $this->false_value;
        }
        if (is_int($offset) || is_string($offset)) {
            return $this->standard_store[$offset];
        }
        if (is_float($offset)) {
            return $this->float_store[(string) $offset];
        }
        if (is_object($offset)) {
            return $this->object_store->offsetGet($offset);
        }
        if (is_array($offset)) {
            // offsetGet is often called directly after offsetExists, so optimize to avoid second loop:
            if ($this->last_array_key === $offset) {
                return $this->last_array_value;
            }
            foreach ($this->array_keys as $index => $entry) {
                if ($entry === $offset) {
                    return $this->array_values[$index];
                }
            }
        }
        if ($offset === null) {
            return $this->null_value;
        }
        return null;
    }
    /**
     * @param mixed $offset
     * @param TValue $value
     *
     * @throws \InvalidArgumentException
     */
    #[\Return_Type_Will_Change]
    public function offsetSet($offset, $value): void
    {
        if ($offset === false) {
            $this->false_value = $value;
            $this->false_value_is_set = true;
        } elseif ($offset === true) {
            $this->true_value = $value;
            $this->true_value_is_set = true;
        } elseif (is_int($offset) || is_string($offset)) {
            $this->standard_store[$offset] = $value;
        } elseif (is_float($offset)) {
            $this->float_store[(string) $offset] = $value;
        } elseif (is_object($offset)) {
            $this->object_store[$offset] = $value;
        } elseif (is_array($offset)) {
            $this->array_keys[] = $offset;
            $this->array_values[] = $value;
        } elseif ($offset === null) {
            $this->null_value = $value;
            $this->null_value_is_set = true;
        } else {
            $unexpected_offset = Utils::print_safe($offset);
            throw new \InvalidArgumentException("Unexpected offset type: {$unexpected_offset}");
        }
    }
    /** @param mixed $offset */
    #[\Return_Type_Will_Change]
    public function offsetUnset($offset): void
    {
        if ($offset === true) {
            $this->true_value = null;
            $this->true_value_is_set = false;
        } elseif ($offset === false) {
            $this->false_value = null;
            $this->false_value_is_set = false;
        } elseif (is_int($offset) || is_string($offset)) {
            unset($this->standard_store[$offset]);
        } elseif (is_float($offset)) {
            unset($this->float_store[(string) $offset]);
        } elseif (is_object($offset)) {
            $this->object_store->offsetUnset($offset);
        } elseif (is_array($offset)) {
            $index = array_search($offset, $this->array_keys, true);
            if ($index !== false) {
                array_splice($this->array_keys, $index, 1);
                array_splice($this->array_values, $index, 1);
            }
        } elseif ($offset === null) {
            $this->null_value = null;
            $this->null_value_is_set = false;
        }
    }
}
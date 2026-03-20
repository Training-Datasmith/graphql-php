<?php

declare (strict_types=1);
namespace Graph_Ql\Executor\Promise\Adapter;

use Graph_Ql\Error\Invariant_Violation;
use Graph_Ql\Executor\Promise\Promise;
use Graph_Ql\Executor\Promise\Promise_Adapter;
use function React\Promise\all;
use React\Promise\Promise as ReactPromise;
use React\Promise\Promise_Interface as ReactPromiseInterface;
use function React\Promise\reject;
use function React\Promise\resolve;
class React_Promise_Adapter implements Promise_Adapter
{
    public function is_thenable($value): bool
    {
        return $value instanceof React_Promise_Interface;
    }
    /** @throws InvariantViolation */
    public function convert_thenable($thenable): Promise
    {
        return new Promise($thenable, $this);
    }
    /** @throws InvariantViolation */
    public function then(Promise $promise, ?callable $on_fulfilled = null, ?callable $on_rejected = null): Promise
    {
        $react_promise = $promise->adopted_promise;
        assert($react_promise instanceof React_Promise_Interface);
        return new Promise($react_promise->then($on_fulfilled, $on_rejected), $this);
    }
    /** @throws InvariantViolation */
    public function create(callable $resolver): Promise
    {
        $react_promise = new React_Promise($resolver);
        return new Promise($react_promise, $this);
    }
    /** @throws InvariantViolation */
    public function create_fulfilled($value = null): Promise
    {
        $react_promise = resolve($value);
        return new Promise($react_promise, $this);
    }
    /** @throws InvariantViolation */
    public function create_rejected(\Throwable $reason): Promise
    {
        $react_promise = reject($reason);
        return new Promise($react_promise, $this);
    }
    /** @throws InvariantViolation */
    public function all(iterable $promises_or_values): Promise
    {
        foreach ($promises_or_values as &$promise_or_value) {
            if ($promise_or_value instanceof Promise) {
                $promise_or_value = $promise_or_value->adopted_promise;
            }
        }
        $promises_or_values_array = is_array($promises_or_values) ? $promises_or_values : iterator_to_array($promises_or_values);
        $react_promise = all($promises_or_values_array)->then(static fn(array $values): array => array_map(static fn($key) => $values[$key], array_keys($promises_or_values_array)));
        return new Promise($react_promise, $this);
    }
}
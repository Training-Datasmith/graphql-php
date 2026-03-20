<?php

declare (strict_types=1);
namespace Graph_Ql\Executor\Promise;

/**
 * Provides a means for integration of async PHP platforms ([related docs](data-fetching.md#async-php)).
 */
interface Promise_Adapter
{
    /**
     * Is the value a promise or a deferred of the underlying platform?
     *
     * @param mixed $value
     *
     * @api
     */
    public function is_thenable($value): bool;
    /**
     * Converts thenable of the underlying platform into GraphQL\Executor\Promise\Promise instance.
     *
     * @param mixed $thenable
     *
     * @api
     */
    public function convert_thenable($thenable): Promise;
    /**
     * Accepts our Promise wrapper, extracts adopted promise out of it and executes actual `then` logic described
     * in Promises/A+ specs. Then returns new wrapped instance of GraphQL\Executor\Promise\Promise.
     *
     * @api
     */
    public function then(Promise $promise, ?callable $on_fulfilled = null, ?callable $on_rejected = null): Promise;
    /**
     * Creates a Promise from the given resolver callable.
     *
     * @param callable(callable $resolve, callable $reject): void $resolver
     *
     * @api
     */
    public function create(callable $resolver): Promise;
    /**
     * Creates a fulfilled Promise for a value if the value is not a promise.
     *
     * @param mixed $value
     *
     * @api
     */
    public function create_fulfilled($value = null): Promise;
    /**
     * Creates a rejected promise for a reason if the reason is not a promise.
     *
     * If the provided reason is a promise, then it is returned as-is.
     *
     * @api
     */
    public function create_rejected(\Throwable $reason): Promise;
    /**
     * Given an iterable of promises (or values), returns a promise that is fulfilled when all the
     * items in the iterable are fulfilled.
     *
     * @param iterable<Promise|mixed> $promisesOrValues
     *
     * @api
     */
    public function all(iterable $promises_or_values): Promise;
}
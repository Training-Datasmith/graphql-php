<?php

declare (strict_types=1);
namespace Graph_Ql\Executor\Promise\Adapter;

use Amp\Deferred;
use Amp\Failure;
use function Amp\Promise\all;
use Amp\Promise as AmpPromise;
use Amp\Success;
use Graph_Ql\Error\Invariant_Violation;
use Graph_Ql\Executor\Promise\Promise;
use Graph_Ql\Executor\Promise\Promise_Adapter;
class Amp_Promise_Adapter implements Promise_Adapter
{
    public function is_thenable($value): bool
    {
        return $value instanceof Amp_Promise;
    }
    /** @throws InvariantViolation */
    public function convert_thenable($thenable): Promise
    {
        return new Promise($thenable, $this);
    }
    /** @throws InvariantViolation */
    public function then(Promise $promise, ?callable $on_fulfilled = null, ?callable $on_rejected = null): Promise
    {
        $deferred = new Deferred();
        $on_resolve = static function (?\Throwable $reason, $value) use ($on_fulfilled, $on_rejected, $deferred): void {
            if ($reason === null && $on_fulfilled !== null) {
                self::resolve_with_callable($deferred, $on_fulfilled, $value);
            } elseif ($reason === null) {
                $deferred->resolve($value);
            } elseif ($on_rejected !== null) {
                self::resolve_with_callable($deferred, $on_rejected, $reason);
            } else {
                $deferred->fail($reason);
            }
        };
        $amp_promise = $promise->adopted_promise;
        assert($amp_promise instanceof Amp_Promise);
        $amp_promise->on_resolve($on_resolve);
        return new Promise($deferred->promise(), $this);
    }
    /** @throws InvariantViolation */
    public function create(callable $resolver): Promise
    {
        $deferred = new Deferred();
        $resolver(static function ($value) use ($deferred): void {
            $deferred->resolve($value);
        }, static function (\Throwable $exception) use ($deferred): void {
            $deferred->fail($exception);
        });
        return new Promise($deferred->promise(), $this);
    }
    /**
     * @throws \Error
     * @throws InvariantViolation
     */
    public function create_fulfilled($value = null): Promise
    {
        $promise = new Success($value);
        return new Promise($promise, $this);
    }
    /** @throws InvariantViolation */
    public function create_rejected(\Throwable $reason): Promise
    {
        $promise = new Failure($reason);
        return new Promise($promise, $this);
    }
    /**
     * @throws \Error
     * @throws InvariantViolation
     */
    public function all(iterable $promises_or_values): Promise
    {
        /** @var array<AmpPromise<mixed>> $promises */
        $promises = [];
        foreach ($promises_or_values as $key => $item) {
            if ($item instanceof Promise) {
                $amp_promise = $item->adopted_promise;
                assert($amp_promise instanceof Amp_Promise);
                $promises[$key] = $amp_promise;
            } elseif ($item instanceof Amp_Promise) {
                $promises[$key] = $item;
            }
        }
        $deferred = new Deferred();
        all($promises)->on_resolve(static function (?\Throwable $reason, ?array $values) use ($promises_or_values, $deferred): void {
            if ($reason === null) {
                assert(is_array($values), 'Either $reason or $values must be passed');
                $promises_or_values_array = is_array($promises_or_values) ? $promises_or_values : iterator_to_array($promises_or_values);
                $resolved_values = array_replace($promises_or_values_array, $values);
                $deferred->resolve($resolved_values);
                return;
            }
            $deferred->fail($reason);
        });
        return new Promise($deferred->promise(), $this);
    }
    /**
     * @template TArgument
     * @template TResult of AmpPromise<mixed>
     *
     * @param Deferred<TResult> $deferred
     * @param callable(TArgument): TResult $callback
     * @param TArgument $argument
     */
    private static function resolve_with_callable(Deferred $deferred, callable $callback, $argument): void
    {
        try {
            $result = $callback($argument);
        } catch (\Throwable $exception) {
            $deferred->fail($exception);
            return;
        }
        if ($result instanceof Promise) {
            /** @var TResult $result */
            $result = $result->adopted_promise;
        }
        $deferred->resolve($result);
    }
}
<?php

declare (strict_types=1);
namespace Graph_Ql\Executor\Promise\Adapter;

use Graph_Ql\Deferred;
use Graph_Ql\Error\Invariant_Violation;
use Graph_Ql\Executor\Promise\Promise;
use Graph_Ql\Executor\Promise\Promise_Adapter;
use Graph_Ql\Utils\Utils;
/**
 * Allows changing order of field resolution even in sync environments
 * (by leveraging queue of deferreds and promises).
 */
class Sync_Promise_Adapter implements Promise_Adapter
{
    public function is_thenable($value): bool
    {
        return $value instanceof Sync_Promise;
    }
    /** @throws InvariantViolation */
    public function convert_thenable($thenable): Promise
    {
        if (!$thenable instanceof Sync_Promise) {
            // End-users should always use Deferred, not SyncPromise directly
            $deferred_class = Deferred::class;
            $safe_thenable = Utils::print_safe($thenable);
            throw new Invariant_Violation("Expected instance of {$deferred_class}, got {$safe_thenable}.");
        }
        return new Promise($thenable, $this);
    }
    /** @throws InvariantViolation */
    public function then(Promise $promise, ?callable $on_fulfilled = null, ?callable $on_rejected = null): Promise
    {
        $sync_promise = $promise->adopted_promise;
        assert($sync_promise instanceof Sync_Promise);
        return new Promise($sync_promise->then($on_fulfilled, $on_rejected), $this);
    }
    /**
     * @throws \Exception
     * @throws InvariantViolation
     */
    public function create(callable $resolver): Promise
    {
        $sync_promise = new Sync_Promise();
        try {
            $resolver([$sync_promise, 'resolve'], [$sync_promise, 'reject']);
        } catch (\Throwable $e) {
            $sync_promise->reject($e);
        }
        return new Promise($sync_promise, $this);
    }
    /**
     * @throws \Exception
     * @throws InvariantViolation
     */
    public function create_fulfilled($value = null): Promise
    {
        $sync_promise = new Sync_Promise();
        return new Promise($sync_promise->resolve($value), $this);
    }
    /**
     * @throws \Exception
     * @throws InvariantViolation
     */
    public function create_rejected(\Throwable $reason): Promise
    {
        $sync_promise = new Sync_Promise();
        return new Promise($sync_promise->reject($reason), $this);
    }
    /**
     * @throws \Exception
     * @throws InvariantViolation
     */
    public function all(iterable $promises_or_values): Promise
    {
        $all = new Sync_Promise();
        $total = is_array($promises_or_values) ? count($promises_or_values) : iterator_count($promises_or_values);
        $count = 0;
        $result = [];
        $resolve_all_when_finished = function () use (&$count, &$total, $all, &$result): void {
            if ($count === $total) {
                $all->resolve($result);
            }
        };
        foreach ($promises_or_values as $index => $promise_or_value) {
            if ($promise_or_value instanceof Promise) {
                $result[$index] = null;
                $promise_or_value->then(static function ($value) use (&$result, $index, &$count, &$resolve_all_when_finished): void {
                    $result[$index] = $value;
                    ++$count;
                    $resolve_all_when_finished();
                }, [$all, 'reject']);
                continue;
            }
            $result[$index] = $promise_or_value;
            ++$count;
        }
        $resolve_all_when_finished();
        return new Promise($all, $this);
    }
    /**
     * Synchronously wait when promise completes.
     *
     * @throws InvariantViolation
     *
     * @return mixed
     */
    public function wait(Promise $promise)
    {
        $this->before_wait($promise);
        $sync_promise = $promise->adopted_promise;
        assert($sync_promise instanceof Sync_Promise);
        while ($sync_promise->state === Sync_Promise::PENDING) {
            Sync_Promise_Queue::run();
            $this->on_wait($promise);
        }
        if ($sync_promise->state === Sync_Promise::FULFILLED) {
            return $sync_promise->result;
        }
        if ($sync_promise->state === Sync_Promise::REJECTED) {
            throw $sync_promise->result;
        }
        throw new Invariant_Violation('Could not resolve promise.');
    }
    /** Execute just before starting to run promise completion. */
    protected function before_wait(Promise $promise): void
    {
    }
    /** Execute while running promise completion. */
    protected function on_wait(Promise $promise): void
    {
    }
}
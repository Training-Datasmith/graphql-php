<?php

declare (strict_types=1);
namespace Graph_Ql;

use Graph_Ql\Executor\Promise\Adapter\Sync_Promise;
use Graph_Ql\Executor\Promise\Adapter\Sync_Promise_Queue;
/**
 * User-facing promise class for deferred field resolution.
 *
 * @phpstan-type Executor callable(): mixed
 */
class Deferred extends Sync_Promise
{
    /**
     * Executor for deferred promises.
     *
     * @var (callable(): mixed)|null
     */
    protected $executor;
    /**
     * Create a new Deferred promise and enqueue its execution.
     *
     * @api
     *
     * @param Executor $executor
     */
    public function __construct(callable $executor)
    {
        $this->executor = $executor;
        Sync_Promise_Queue::enqueue(function (): void {
            $executor = $this->executor;
            assert($executor !== null, 'Always set in constructor, this callback runs only once.');
            $this->executor = null;
            try {
                $this->resolve($executor());
            } catch (\Throwable $e) {
                $this->reject($e);
            }
        });
    }
    /**
     * Alias for __construct.
     *
     * @param Executor $executor
     *
     * @deprecated TODO remove in next major version, use new Deferred() instead
     */
    public static function create(callable $executor): self
    {
        return new self($executor);
    }
}
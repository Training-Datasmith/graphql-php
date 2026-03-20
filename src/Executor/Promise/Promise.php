<?php

declare (strict_types=1);
namespace Graph_Ql\Executor\Promise;

use Amp\Promise as AmpPromise;
use Graph_Ql\Error\Invariant_Violation;
use Graph_Ql\Executor\Promise\Adapter\Sync_Promise;
use React\Promise\Promise_Interface as ReactPromise;
/**
 * Convenience wrapper for promises represented by Promise Adapter.
 */
class Promise
{
    /** @var SyncPromise|ReactPromise<mixed>|AmpPromise<mixed> */
    public $adopted_promise;
    private Promise_Adapter $adapter;
    /**
     * @param mixed $adoptedPromise
     *
     * @throws InvariantViolation
     */
    public function __construct($adopted_promise, Promise_Adapter $adapter)
    {
        if ($adopted_promise instanceof self) {
            $self_class = self::class;
            throw new Invariant_Violation("Expected promise from adapted system, got {$self_class}.");
        }
        $this->adopted_promise = $adopted_promise;
        $this->adapter = $adapter;
    }
    public function then(?callable $on_fulfilled = null, ?callable $on_rejected = null): Promise
    {
        return $this->adapter->then($this, $on_fulfilled, $on_rejected);
    }
}
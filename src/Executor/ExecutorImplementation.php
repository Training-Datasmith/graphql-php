<?php

declare (strict_types=1);
namespace Graph_Ql\Executor;

use Graph_Ql\Executor\Promise\Promise;
interface Executor_Implementation
{
    /** Returns promise of {@link ExecutionResult}. Promise should always resolve, never reject. */
    public function do_execute(): Promise;
}
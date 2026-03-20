<?php

declare (strict_types=1);
namespace Graph_Ql\Executor;

use Graph_Ql\Executor\Promise\Promise;
class Promise_Executor implements Executor_Implementation
{
    private Promise $result;
    public function __construct(Promise $result)
    {
        $this->result = $result;
    }
    public function do_execute(): Promise
    {
        return $this->result;
    }
}
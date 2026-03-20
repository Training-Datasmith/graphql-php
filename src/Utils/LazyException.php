<?php

declare (strict_types=1);
namespace Graph_Ql\Utils;

/**
 * Allows lazy calculation of a complex message when the exception is used in `assert()`.
 */
class Lazy_Exception extends \Exception
{
    /** @param callable(): string $makeMessage */
    public function __construct(callable $make_message)
    {
        parent::__construct($make_message());
    }
}
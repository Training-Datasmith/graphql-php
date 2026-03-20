<?php

declare (strict_types=1);
namespace Graph_Ql\Error;

use Graph_Ql\Language\Source;
class Syntax_Error extends Error
{
    public function __construct(Source $source, int $position, string $description)
    {
        parent::__construct("Syntax Error: {$description}", null, $source, [$position]);
    }
}
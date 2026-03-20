<?php

declare (strict_types=1);
namespace Graph_Ql\Validator;

use Graph_Ql\Error\Error;
use Graph_Ql\Language\AST\Document_Node;
use Graph_Ql\Type\Schema;
interface Validation_Context
{
    public function report_error(Error $error): void;
    /** @return list<Error> */
    public function get_errors(): array;
    public function get_document(): Document_Node;
    public function get_schema(): ?Schema;
}
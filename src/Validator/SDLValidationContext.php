<?php

declare (strict_types=1);
namespace Graph_Ql\Validator;

use Graph_Ql\Error\Error;
use Graph_Ql\Language\AST\Document_Node;
use Graph_Ql\Type\Schema;
class Sdl_Validation_Context implements Validation_Context
{
    protected Document_Node $ast;
    protected ?Schema $schema;
    /** @var list<Error> */
    protected array $errors = [];
    public function __construct(Document_Node $ast, ?Schema $schema)
    {
        $this->ast = $ast;
        $this->schema = $schema;
    }
    public function report_error(Error $error): void
    {
        $this->errors[] = $error;
    }
    public function get_errors(): array
    {
        return $this->errors;
    }
    public function get_document(): Document_Node
    {
        return $this->ast;
    }
    public function get_schema(): ?Schema
    {
        return $this->schema;
    }
}
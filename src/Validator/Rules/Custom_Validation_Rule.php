<?php

declare (strict_types=1);
namespace Graph_Ql\Validator\Rules;

use Graph_Ql\Language\AST\Node;
use Graph_Ql\Language\Visitor_Operation;
use Graph_Ql\Validator\Validation_Context;
/**
 * @see Node, VisitorOperation
 *
 * @phpstan-type NodeVisitorFnResult VisitorOperation|mixed|null
 * @phpstan-type VisitorFnResult array<string, callable(Node): NodeVisitorFnResult>|array<string, array<string, callable(Node): NodeVisitorFnResult>>
 * @phpstan-type VisitorFn callable(ValidationContext): VisitorFnResult
 */
class Custom_Validation_Rule extends Validation_Rule
{
    /**
     * @var callable
     *
     * @phpstan-var VisitorFn
     */
    protected $visitor_fn;
    /** @phpstan-param VisitorFn $visitorFn */
    public function __construct(string $name, callable $visitor_fn)
    {
        $this->name = $name;
        $this->visitor_fn = $visitor_fn;
    }
    public function get_visitor(Validation_Context $context): array
    {
        return ($this->visitor_fn)($context);
    }
}
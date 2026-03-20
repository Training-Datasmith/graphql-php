<?php

declare (strict_types=1);
namespace Graph_Ql\Validator\Rules;

use Graph_Ql\Error\Error;
use Graph_Ql\Language\AST\Name_Node;
use Graph_Ql\Language\AST\Node_Kind;
use Graph_Ql\Language\AST\Operation_Definition_Node;
use Graph_Ql\Language\Visitor;
use Graph_Ql\Language\Visitor_Operation;
use Graph_Ql\Validator\Query_Validation_Context;
class Unique_Operation_Names extends Validation_Rule
{
    /** @var array<string, NameNode> */
    protected array $known_operation_names;
    public function get_visitor(Query_Validation_Context $context): array
    {
        $this->known_operation_names = [];
        return [Node_Kind::OPERATION_DEFINITION => function (Operation_Definition_Node $node) use ($context): Visitor_Operation {
            $operation_name = $node->name;
            if ($operation_name !== null) {
                if (!isset($this->known_operation_names[$operation_name->value])) {
                    $this->known_operation_names[$operation_name->value] = $operation_name;
                } else {
                    $context->report_error(new Error(static::duplicate_operation_name_message($operation_name->value), [$this->known_operation_names[$operation_name->value], $operation_name]));
                }
            }
            return Visitor::skip_node();
        }, Node_Kind::FRAGMENT_DEFINITION => static fn(): Visitor_Operation => Visitor::skip_node()];
    }
    public static function duplicate_operation_name_message(string $operation_name): string
    {
        return "There can be only one operation named \"{$operation_name}\".";
    }
}
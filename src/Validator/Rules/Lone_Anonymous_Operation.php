<?php

declare (strict_types=1);
namespace Graph_Ql\Validator\Rules;

use Graph_Ql\Error\Error;
use Graph_Ql\Language\AST\Document_Node;
use Graph_Ql\Language\AST\Node_Kind;
use Graph_Ql\Language\AST\Operation_Definition_Node;
use Graph_Ql\Validator\Query_Validation_Context;
/**
 * Lone anonymous operation.
 *
 * A GraphQL document is only valid if when it contains an anonymous operation
 * (the query shorthand) that it contains only that one operation definition.
 */
class Lone_Anonymous_Operation extends Validation_Rule
{
    public function get_visitor(Query_Validation_Context $context): array
    {
        $operation_count = 0;
        return [Node_Kind::DOCUMENT => static function (Document_Node $node) use (&$operation_count): void {
            $operation_count = 0;
            foreach ($node->definitions as $definition) {
                if ($definition instanceof Operation_Definition_Node) {
                    ++$operation_count;
                }
            }
        }, Node_Kind::OPERATION_DEFINITION => static function (Operation_Definition_Node $node) use (&$operation_count, $context): void {
            if ($node->name !== null || $operation_count <= 1) {
                return;
            }
            $context->report_error(new Error(static::anon_operation_not_alone_message(), [$node]));
        }];
    }
    public static function anon_operation_not_alone_message(): string
    {
        return 'This anonymous operation must be the only defined operation.';
    }
}
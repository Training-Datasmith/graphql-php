<?php

declare (strict_types=1);
namespace Graph_Ql\Validator\Rules;

use Graph_Ql\Error\Error;
use Graph_Ql\Language\AST\Node_Kind;
use Graph_Ql\Language\AST\Schema_Definition_Node;
use Graph_Ql\Language\AST\Schema_Extension_Node;
use Graph_Ql\Language\Visitor;
use Graph_Ql\Language\Visitor_Operation;
use Graph_Ql\Validator\Sdl_Validation_Context;
/**
 * Unique operation types.
 *
 * A GraphQL document is only valid if it has only one type per operation.
 */
class Unique_Operation_Types extends Validation_Rule
{
    public function get_sdl_visitor(Sdl_Validation_Context $context): array
    {
        $schema = $context->get_schema();
        $defined_operation_types = [];
        $existing_operation_types = $schema !== null ? ['query' => $schema->get_query_type(), 'mutation' => $schema->get_mutation_type(), 'subscription' => $schema->get_subscription_type()] : [];
        /**
         * @param SchemaDefinitionNode|SchemaExtensionNode $node
         */
        $check_operation_types = static function ($node) use ($context, &$defined_operation_types, $existing_operation_types): Visitor_Operation {
            foreach ($node->operation_types as $operation_type) {
                $operation = $operation_type->operation;
                $already_defined_operation_type = $defined_operation_types[$operation] ?? null;
                if (isset($existing_operation_types[$operation])) {
                    $context->report_error(new Error("Type for {$operation} already defined in the schema. It cannot be redefined.", $operation_type));
                } elseif ($already_defined_operation_type !== null) {
                    $context->report_error(new Error("There can be only one {$operation} type in schema.", [$already_defined_operation_type, $operation_type]));
                } else {
                    $defined_operation_types[$operation] = $operation_type;
                }
            }
            return Visitor::skip_node();
        };
        return [Node_Kind::SCHEMA_DEFINITION => $check_operation_types, Node_Kind::SCHEMA_EXTENSION => $check_operation_types];
    }
}
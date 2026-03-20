<?php

declare (strict_types=1);
namespace Graph_Ql\Validator\Rules;

use Graph_Ql\Error\Error;
use Graph_Ql\Language\AST\Document_Node;
use Graph_Ql\Language\AST\Executable_Definition_Node;
use Graph_Ql\Language\AST\Node_Kind;
use Graph_Ql\Language\AST\Schema_Definition_Node;
use Graph_Ql\Language\AST\Schema_Extension_Node;
use Graph_Ql\Language\AST\Type_Definition_Node;
use Graph_Ql\Language\AST\Type_Extension_Node;
use Graph_Ql\Language\Visitor;
use Graph_Ql\Language\Visitor_Operation;
use Graph_Ql\Validator\Query_Validation_Context;
/**
 * Executable definitions.
 *
 * A GraphQL document is only valid for execution if all definitions are either
 * operation or fragment definitions.
 */
class Executable_Definitions extends Validation_Rule
{
    public function get_visitor(Query_Validation_Context $context): array
    {
        return [Node_Kind::DOCUMENT => static function (Document_Node $node) use ($context): Visitor_Operation {
            foreach ($node->definitions as $definition) {
                if (!$definition instanceof Executable_Definition_Node) {
                    if ($definition instanceof Schema_Definition_Node || $definition instanceof Schema_Extension_Node) {
                        $def_name = 'schema';
                    } else {
                        assert($definition instanceof Type_Definition_Node || $definition instanceof Type_Extension_Node, 'only other option');
                        $def_name = "\"{$definition->get_name()->value}\"";
                    }
                    $context->report_error(new Error(static::non_executable_definition_message($def_name), [$definition]));
                }
            }
            return Visitor::skip_node();
        }];
    }
    public static function non_executable_definition_message(string $def_name): string
    {
        return "The {$def_name} definition is not executable.";
    }
}
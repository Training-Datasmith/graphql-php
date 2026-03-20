<?php

declare (strict_types=1);
namespace Graph_Ql\Validator\Rules;

use Graph_Ql\Error\Error;
use Graph_Ql\Language\AST\Fragment_Definition_Node;
use Graph_Ql\Language\AST\Name_Node;
use Graph_Ql\Language\AST\Node_Kind;
use Graph_Ql\Language\Visitor;
use Graph_Ql\Language\Visitor_Operation;
use Graph_Ql\Validator\Query_Validation_Context;
class Unique_Fragment_Names extends Validation_Rule
{
    /** @var array<string, NameNode> */
    protected array $known_fragment_names;
    public function get_visitor(Query_Validation_Context $context): array
    {
        $this->known_fragment_names = [];
        return [Node_Kind::OPERATION_DEFINITION => static fn(): Visitor_Operation => Visitor::skip_node(), Node_Kind::FRAGMENT_DEFINITION => function (Fragment_Definition_Node $node) use ($context): Visitor_Operation {
            $fragment_name = $node->name->value;
            if (!isset($this->known_fragment_names[$fragment_name])) {
                $this->known_fragment_names[$fragment_name] = $node->name;
            } else {
                $context->report_error(new Error(static::duplicate_fragment_name_message($fragment_name), [$this->known_fragment_names[$fragment_name], $node->name]));
            }
            return Visitor::skip_node();
        }];
    }
    public static function duplicate_fragment_name_message(string $frag_name): string
    {
        return "There can be only one fragment named \"{$frag_name}\".";
    }
}
<?php

declare (strict_types=1);
namespace Graph_Ql\Validator\Rules;

use Graph_Ql\Error\Error;
use Graph_Ql\Language\AST\Field_Node;
use Graph_Ql\Language\AST\Node_Kind;
use Graph_Ql\Validator\Query_Validation_Context;
class Disable_Introspection extends Query_Security_Rule
{
    public const ENABLED = 1;
    protected int $is_enabled;
    public function __construct(int $enabled)
    {
        $this->set_enabled($enabled);
    }
    public function set_enabled(int $enabled): void
    {
        $this->is_enabled = $enabled;
    }
    public function get_visitor(Query_Validation_Context $context): array
    {
        return $this->invoke_if_needed($context, [Node_Kind::FIELD => static function (Field_Node $node) use ($context): void {
            if ($node->name->value !== '__type' && $node->name->value !== '__schema') {
                return;
            }
            $context->report_error(new Error(static::introspection_disabled_message(), [$node]));
        }]);
    }
    public static function introspection_disabled_message(): string
    {
        return 'GraphQL introspection is not allowed, but the query contained __schema or __type';
    }
    protected function is_enabled(): bool
    {
        return $this->is_enabled !== self::DISABLED;
    }
}
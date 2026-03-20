<?php

declare (strict_types=1);
namespace Graph_Ql\Validator\Rules;

use Graph_Ql\Error\Error;
use Graph_Ql\Error\Invariant_Violation;
use Graph_Ql\Executor\Values;
use Graph_Ql\Language\AST\Document_Node;
use Graph_Ql\Language\AST\Field_Node;
use Graph_Ql\Language\AST\Fragment_Spread_Node;
use Graph_Ql\Language\AST\Inline_Fragment_Node;
use Graph_Ql\Language\AST\Node_Kind;
use Graph_Ql\Language\AST\Node_List;
use Graph_Ql\Language\AST\Operation_Definition_Node;
use Graph_Ql\Language\AST\Selection_Node;
use Graph_Ql\Language\AST\Selection_Set_Node;
use Graph_Ql\Language\AST\Variable_Definition_Node;
use Graph_Ql\Language\Visitor;
use Graph_Ql\Language\Visitor_Operation;
use Graph_Ql\Type\Definition\Directive;
use Graph_Ql\Type\Definition\Field_Definition;
use Graph_Ql\Type\Introspection;
use Graph_Ql\Validator\Query_Validation_Context;
/**
 * @phpstan-import-type ASTAndDefs from QuerySecurityRule
 */
class Query_Complexity extends Query_Security_Rule
{
    protected int $max_query_complexity;
    protected int $query_complexity;
    /** @var array<string, mixed> */
    protected array $raw_variable_values = [];
    /** @var NodeList<VariableDefinitionNode> */
    protected Node_List $variable_defs;
    /** @phpstan-var ASTAndDefs */
    protected \ArrayObject $field_node_and_defs;
    protected Query_Validation_Context $context;
    /** @throws \InvalidArgumentException */
    public function __construct(int $max_query_complexity)
    {
        $this->set_max_query_complexity($max_query_complexity);
    }
    public function get_visitor(Query_Validation_Context $context): array
    {
        $this->query_complexity = 0;
        $this->context = $context;
        $this->variable_defs = new Node_List([]);
        $this->field_node_and_defs = new \ArrayObject();
        return $this->invoke_if_needed($context, [Node_Kind::SELECTION_SET => function (Selection_Set_Node $selection_set) use ($context): void {
            $this->field_node_and_defs = $this->collect_field_as_ts_and_defs($context, $context->get_parent_type(), $selection_set, null, $this->field_node_and_defs);
        }, Node_Kind::VARIABLE_DEFINITION => function ($def): Visitor_Operation {
            $this->variable_defs[] = $def;
            return Visitor::skip_node();
        }, Node_Kind::DOCUMENT => ['leave' => function (Document_Node $document) use ($context): void {
            $errors = $context->get_errors();
            if ($errors !== []) {
                return;
            }
            if ($this->max_query_complexity === self::DISABLED) {
                return;
            }
            foreach ($document->definitions as $definition) {
                if (!$definition instanceof Operation_Definition_Node) {
                    continue;
                }
                $this->query_complexity = $this->field_complexity($definition->selection_set);
                if ($this->query_complexity > $this->max_query_complexity) {
                    $context->report_error(new Error(static::max_query_complexity_error_message($this->max_query_complexity, $this->query_complexity)));
                    return;
                }
            }
        }]]);
    }
    /** @throws \Exception */
    protected function field_complexity(Selection_Set_Node $selection_set): int
    {
        $complexity = 0;
        foreach ($selection_set->selections as $selection) {
            $complexity += $this->node_complexity($selection);
        }
        return $complexity;
    }
    /** @throws \Exception */
    protected function node_complexity(Selection_Node $node): int
    {
        switch (true) {
            case $node instanceof Field_Node:
                // Exclude __schema field and all nested content from complexity calculation
                if ($node->name->value === Introspection::SCHEMA_FIELD_NAME) {
                    return 0;
                }
                if ($this->directive_excludes_field($node)) {
                    return 0;
                }
                $children_complexity = isset($node->selection_set) ? $this->field_complexity($node->selection_set) : 0;
                $field_def = $this->field_definition($node);
                if ($field_def instanceof Field_Definition && $field_def->complexity_fn !== null) {
                    $field_arguments = $this->build_field_arguments($node);
                    return ($field_def->complexity_fn)($children_complexity, $field_arguments);
                }
                return $children_complexity + 1;
            case $node instanceof Inline_Fragment_Node:
                return $this->field_complexity($node->selection_set);
            case $node instanceof Fragment_Spread_Node:
                $fragment = $this->get_fragment($node);
                if ($fragment !== null) {
                    return $this->field_complexity($fragment->selection_set);
                }
        }
        return 0;
    }
    protected function field_definition(Field_Node $field): ?Field_Definition
    {
        foreach ($this->field_node_and_defs[$this->get_field_name($field)] ?? [] as [$node, $def]) {
            if ($node === $field) {
                return $def;
            }
        }
        return null;
    }
    /**
     * Will the given field be executed at all, given the directives placed upon it?
     *
     * @throws \Exception
     * @throws \ReflectionException
     * @throws InvariantViolation
     */
    protected function directive_excludes_field(Field_Node $node): bool
    {
        foreach ($node->directives as $directive_node) {
            if ($directive_node->name->value === Directive::DEPRECATED_NAME) {
                return false;
            }
            [$errors, $variable_values] = Values::get_variable_values($this->context->get_schema(), $this->variable_defs, $this->get_raw_variable_values());
            if ($errors !== null && $errors !== []) {
                throw new Error(implode("\n\n", array_map(static fn(Error $error): string => $error->get_message(), $errors)));
            }
            if ($directive_node->name->value === Directive::INCLUDE_NAME) {
                $include_arguments = Values::get_argument_values(Directive::include_directive(), $directive_node, $variable_values);
                assert(is_bool($include_arguments['if']), 'ensured by query validation');
                return !$include_arguments['if'];
            }
            if ($directive_node->name->value === Directive::SKIP_NAME) {
                $skip_arguments = Values::get_argument_values(Directive::skip_directive(), $directive_node, $variable_values);
                assert(is_bool($skip_arguments['if']), 'ensured by query validation');
                return $skip_arguments['if'];
            }
        }
        return false;
    }
    /** @return array<string, mixed> */
    public function get_raw_variable_values(): array
    {
        return $this->raw_variable_values;
    }
    /** @param array<string, mixed>|null $rawVariableValues */
    public function set_raw_variable_values(?array $raw_variable_values = null): void
    {
        $this->raw_variable_values = $raw_variable_values ?? [];
    }
    /**
     * @throws \Exception
     * @throws Error
     *
     * @return array<string, mixed>
     */
    protected function build_field_arguments(Field_Node $node): array
    {
        $raw_variable_values = $this->get_raw_variable_values();
        $field_def = $this->field_definition($node);
        /** @var array<string, mixed> $args */
        $args = [];
        if ($field_def instanceof Field_Definition) {
            [$errors, $variable_values] = Values::get_variable_values($this->context->get_schema(), $this->variable_defs, $raw_variable_values);
            if (is_array($errors) && $errors !== []) {
                throw new Error(implode("\n\n", array_map(static fn($error): string => $error->get_message(), $errors)));
            }
            $args = Values::get_argument_values($field_def, $node, $variable_values);
        }
        return $args;
    }
    public function get_max_query_complexity(): int
    {
        return $this->max_query_complexity;
    }
    /**
     * Complexity of the first operation exceeding the defined limit, or, in case no operation
     * exceeds the limit, complexity of the last defined operation.
     */
    public function get_query_complexity(): int
    {
        return $this->query_complexity;
    }
    /**
     * Set max query complexity. If equal to 0 no check is done. Must be greater or equal to 0.
     *
     * @throws \InvalidArgumentException
     */
    public function set_max_query_complexity(int $max_query_complexity): void
    {
        $this->check_if_greater_or_equal_to_zero('maxQueryComplexity', $max_query_complexity);
        $this->max_query_complexity = $max_query_complexity;
    }
    public static function max_query_complexity_error_message(int $max, int $count): string
    {
        return "Max query complexity should be {$max} but got {$count}.";
    }
    protected function is_enabled(): bool
    {
        return $this->max_query_complexity !== self::DISABLED;
    }
}
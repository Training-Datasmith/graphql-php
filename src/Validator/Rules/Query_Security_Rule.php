<?php

declare (strict_types=1);
namespace Graph_Ql\Validator\Rules;

use Graph_Ql\Error\Invariant_Violation;
use Graph_Ql\Language\AST\Field_Node;
use Graph_Ql\Language\AST\Fragment_Definition_Node;
use Graph_Ql\Language\AST\Fragment_Spread_Node;
use Graph_Ql\Language\AST\Inline_Fragment_Node;
use Graph_Ql\Language\AST\Selection_Set_Node;
use Graph_Ql\Language\Visitor;
use Graph_Ql\Type\Definition\Field_Definition;
use Graph_Ql\Type\Definition\Has_Fields_Type;
use Graph_Ql\Type\Definition\Type;
use Graph_Ql\Type\Introspection;
use Graph_Ql\Utils\AST;
use Graph_Ql\Validator\Query_Validation_Context;
/**
 * @see Visitor, FieldDefinition
 *
 * @phpstan-import-type VisitorArray from Visitor
 *
 * @phpstan-type ASTAndDefs \ArrayObject<string, \ArrayObject<int, array{FieldNode, FieldDefinition|null}>>
 */
abstract class Query_Security_Rule extends Validation_Rule
{
    public const DISABLED = 0;
    /** @var array<string, FragmentDefinitionNode> */
    protected array $fragments = [];
    /** @throws \InvalidArgumentException */
    protected function check_if_greater_or_equal_to_zero(string $name, int $value): void
    {
        if ($value < 0) {
            throw new \InvalidArgumentException("\${$name} argument must be greater or equal to 0.");
        }
    }
    protected function get_fragment(Fragment_Spread_Node $fragment_spread): ?Fragment_Definition_Node
    {
        return $this->fragments[$fragment_spread->name->value] ?? null;
    }
    /** @return array<string, FragmentDefinitionNode> */
    protected function get_fragments(): array
    {
        return $this->fragments;
    }
    /**
     * @phpstan-param VisitorArray $validators
     *
     * @phpstan-return VisitorArray
     */
    protected function invoke_if_needed(Query_Validation_Context $context, array $validators): array
    {
        if (!$this->is_enabled()) {
            return [];
        }
        $this->gather_fragment_definition($context);
        return $validators;
    }
    abstract protected function is_enabled(): bool;
    protected function gather_fragment_definition(Query_Validation_Context $context): void
    {
        // Gather all the fragment definition.
        // Importantly this does not include inline fragments.
        $definitions = $context->get_document()->definitions;
        foreach ($definitions as $node) {
            if ($node instanceof Fragment_Definition_Node) {
                $this->fragments[$node->name->value] = $node;
            }
        }
    }
    /**
     * Given a selectionSet, adds all fields in that selection to
     * the passed in map of fields, and returns it at the end.
     *
     * Note: This is not the same as execution's collectFields because at static
     * time we do not know what object type will be used, so we unconditionally
     * spread in all fragments.
     *
     * @see \GraphQL\Validator\Rules\OverlappingFieldsCanBeMerged
     *
     * @param \ArrayObject<string, true>|null $visitedFragmentNames
     *
     * @phpstan-param ASTAndDefs|null $astAndDefs
     *
     * @throws \Exception
     * @throws \ReflectionException
     * @throws InvariantViolation
     *
     * @phpstan-return ASTAndDefs
     */
    protected function collect_field_as_ts_and_defs(Query_Validation_Context $context, ?Type $parent_type, Selection_Set_Node $selection_set, ?\ArrayObject $visited_fragment_names = null, ?\ArrayObject $ast_and_defs = null): \ArrayObject
    {
        $visited_fragment_names ??= new \ArrayObject();
        $ast_and_defs ??= new \ArrayObject();
        foreach ($selection_set->selections as $selection) {
            if ($selection instanceof Field_Node) {
                $field_name = $selection->name->value;
                $field_def = null;
                if ($parent_type instanceof Has_Fields_Type) {
                    $schema_meta_field_def = Introspection::schema_meta_field_def();
                    $type_meta_field_def = Introspection::type_meta_field_def();
                    $type_name_meta_field_def = Introspection::type_name_meta_field_def();
                    $query_type = $context->get_schema()->get_query_type();
                    if ($field_name === $schema_meta_field_def->name && $query_type === $parent_type) {
                        $field_def = $schema_meta_field_def;
                    } elseif ($field_name === $type_meta_field_def->name && $query_type === $parent_type) {
                        $field_def = $type_meta_field_def;
                    } elseif ($field_name === $type_name_meta_field_def->name) {
                        $field_def = $type_name_meta_field_def;
                    } elseif ($parent_type->has_field($field_name)) {
                        $field_def = $parent_type->get_field($field_name);
                    }
                }
                $response_name = $this->get_field_name($selection);
                $response_context = $ast_and_defs[$response_name] ??= new \ArrayObject();
                $response_context[] = [$selection, $field_def];
            } elseif ($selection instanceof Inline_Fragment_Node) {
                $type_condition = $selection->type_condition;
                $fragment_parent_type = $type_condition === null ? $parent_type : AST::type_from_ast([$context->get_schema(), 'getType'], $type_condition);
                $ast_and_defs = $this->collect_field_as_ts_and_defs($context, $fragment_parent_type, $selection->selection_set, $visited_fragment_names, $ast_and_defs);
            } elseif ($selection instanceof Fragment_Spread_Node) {
                $frag_name = $selection->name->value;
                if (isset($visited_fragment_names[$frag_name])) {
                    continue;
                }
                $visited_fragment_names[$frag_name] = true;
                $fragment = $context->get_fragment($frag_name);
                if ($fragment === null) {
                    continue;
                }
                $ast_and_defs = $this->collect_field_as_ts_and_defs($context, AST::type_from_ast([$context->get_schema(), 'getType'], $fragment->type_condition), $fragment->selection_set, $visited_fragment_names, $ast_and_defs);
            }
        }
        return $ast_and_defs;
    }
    protected function get_field_name(Field_Node $node): string
    {
        $field_name = $node->name->value;
        return $node->alias === null ? $field_name : $node->alias->value;
    }
}
<?php

declare (strict_types=1);
namespace Graph_Ql\Type\Definition;

use Graph_Ql\Error\Error;
use Graph_Ql\Error\Invariant_Violation;
use Graph_Ql\Executor\Values;
use Graph_Ql\Language\AST\Field_Node;
use Graph_Ql\Language\AST\Fragment_Definition_Node;
use Graph_Ql\Language\AST\Fragment_Spread_Node;
use Graph_Ql\Language\AST\Inline_Fragment_Node;
use Graph_Ql\Language\AST\Selection_Set_Node;
use Graph_Ql\Type\Introspection;
use Graph_Ql\Type\Schema;
/**
 * @phpstan-type QueryPlanOptions array{
 *   groupImplementorFields?: bool,
 * }
 */
class Query_Plan
{
    /**
     * Map from type names to a list of fields referenced of that type.
     *
     * @var array<string, array<string, true>>
     */
    private array $type_to_fields = [];
    private Schema $schema;
    /** @var array<string, mixed> */
    private array $query_plan = [];
    /** @var array<string, mixed> */
    private array $variable_values;
    /** @var array<string, FragmentDefinitionNode> */
    private array $fragments;
    private bool $group_implementor_fields;
    /**
     * @param iterable<FieldNode> $fieldNodes
     * @param array<string, mixed> $variableValues
     * @param array<string, FragmentDefinitionNode> $fragments
     * @param QueryPlanOptions $options
     *
     * @throws \Exception
     * @throws Error
     * @throws InvariantViolation
     */
    public function __construct(Object_Type $parent_type, Schema $schema, iterable $field_nodes, array $variable_values, array $fragments, array $options = [])
    {
        $this->schema = $schema;
        $this->variable_values = $variable_values;
        $this->fragments = $fragments;
        $this->group_implementor_fields = $options['groupImplementorFields'] ?? false;
        $this->analyze_query_plan($parent_type, $field_nodes);
    }
    /** @return array<string, mixed> */
    public function query_plan(): array
    {
        return $this->query_plan;
    }
    /** @return array<int, string> */
    public function get_referenced_types(): array
    {
        return array_keys($this->type_to_fields);
    }
    public function has_type(string $type): bool
    {
        return isset($this->type_to_fields[$type]);
    }
    /**
     * TODO return array<string, true>.
     *
     * @return array<int, string>
     */
    public function get_referenced_fields(): array
    {
        $all_fields = [];
        foreach ($this->type_to_fields as $fields) {
            foreach ($fields as $field => $_) {
                $all_fields[$field] = true;
            }
        }
        return array_keys($all_fields);
    }
    public function has_field(string $field): bool
    {
        foreach ($this->type_to_fields as $fields) {
            if (array_key_exists($field, $fields)) {
                return true;
            }
        }
        return false;
    }
    /**
     * TODO return array<string, true>.
     *
     * @return array<int, string>
     */
    public function sub_fields(string $typename): array
    {
        return array_keys($this->type_to_fields[$typename] ?? []);
    }
    /**
     * @param iterable<FieldNode> $fieldNodes
     *
     * @throws \Exception
     * @throws Error
     * @throws InvariantViolation
     */
    private function analyze_query_plan(Object_Type $parent_type, iterable $field_nodes): void
    {
        $query_plan = [];
        $implementors = [];
        foreach ($field_nodes as $field_node) {
            if ($field_node->selection_set === null) {
                continue;
            }
            $type = Type::get_named_type($parent_type->get_field($field_node->name->value)->get_type());
            $subfields = $this->analyze_selection_set($field_node->selection_set, $type, $implementors);
            $query_plan = $this->array_merge_deep($query_plan, $subfields);
        }
        if ($this->group_implementor_fields) {
            $this->query_plan = ['fields' => $query_plan];
            if ($implementors !== []) {
                $this->query_plan['implementors'] = $implementors;
            }
        } else {
            $this->query_plan = $query_plan;
        }
    }
    /**
     * @param Type&NamedType $parentType
     * @param array<string, mixed> $implementors
     *
     * @throws \Exception
     * @throws Error
     * @throws InvariantViolation
     *
     * @return array<mixed>
     */
    private function analyze_selection_set(Selection_Set_Node $selection_set, Type $parent_type, array &$implementors): array
    {
        $fields = [];
        $implementors = [];
        foreach ($selection_set->selections as $selection) {
            if ($selection instanceof Field_Node) {
                $field_name = $selection->name->value;
                if ($field_name === Introspection::TYPE_NAME_FIELD_NAME) {
                    continue;
                }
                assert($parent_type instanceof Has_Fields_Type, 'ensured by query validation');
                $type = $parent_type->get_field($field_name);
                $selection_type = $type->get_type();
                $sub_implementors = [];
                $nested_selection_set = $selection->selection_set;
                $subfields = $nested_selection_set === null ? [] : $this->analyze_sub_fields($selection_type, $nested_selection_set, $sub_implementors);
                $fields[$field_name] = ['type' => $selection_type, 'fields' => $subfields, 'args' => Values::get_argument_values($type, $selection, $this->variable_values)];
                if ($this->group_implementor_fields && $sub_implementors !== []) {
                    $fields[$field_name]['implementors'] = $sub_implementors;
                }
            } elseif ($selection instanceof Fragment_Spread_Node) {
                $spread_name = $selection->name->value;
                $fragment = $this->fragments[$spread_name] ?? null;
                if ($fragment === null) {
                    continue;
                }
                $type = $this->schema->get_type($fragment->type_condition->name->value);
                assert($type instanceof Type, 'ensured by query validation');
                $subfields = $this->analyze_sub_fields($type, $fragment->selection_set);
                $fields = $this->merge_fields($parent_type, $type, $fields, $subfields, $implementors);
            } elseif ($selection instanceof Inline_Fragment_Node) {
                $type_condition = $selection->type_condition;
                $type = $type_condition === null ? $parent_type : $this->schema->get_type($type_condition->name->value);
                assert($type instanceof Type, 'ensured by query validation');
                $subfields = $this->analyze_sub_fields($type, $selection->selection_set);
                $fields = $this->merge_fields($parent_type, $type, $fields, $subfields, $implementors);
            }
        }
        $parent_type_name = $parent_type->name();
        // TODO evaluate if this line is really necessary.
        // It causes abstract types to appear in getReferencedTypes() even if they do not have any fields directly referencing them.
        $this->type_to_fields[$parent_type_name] ??= [];
        foreach ($fields as $field_name => $_) {
            $this->type_to_fields[$parent_type_name][$field_name] = true;
        }
        return $fields;
    }
    /**
     * @param array<string, mixed> $implementors
     *
     * @throws \Exception
     * @throws Error
     *
     * @return array<mixed>
     */
    private function analyze_sub_fields(Type $type, Selection_Set_Node $selection_set, array &$implementors = []): array
    {
        $type = Type::get_named_type($type);
        return $type instanceof Object_Type || $type instanceof Abstract_Type ? $this->analyze_selection_set($selection_set, $type, $implementors) : [];
    }
    /**
     * @param Type&NamedType $parentType
     * @param Type&NamedType $type
     * @param array<mixed> $fields
     * @param array<mixed> $subfields
     * @param array<string, mixed> $implementors
     *
     * @return array<mixed>
     */
    private function merge_fields(Type $parent_type, Type $type, array $fields, array $subfields, array &$implementors): array
    {
        if ($this->group_implementor_fields && $parent_type instanceof Abstract_Type && !$type instanceof Abstract_Type) {
            $name = $type->name;
            assert(is_string($name));
            $implementors[$name] = ['type' => $type, 'fields' => $this->array_merge_deep($implementors[$name]['fields'] ?? [], array_diff_key($subfields, $fields))];
            return $this->array_merge_deep($fields, array_intersect_key($subfields, $fields));
        }
        return $this->array_merge_deep($subfields, $fields);
    }
    /**
     * Merges nested arrays, but handles non array values differently from array_merge_recursive.
     * While array_merge_recursive tries to merge non-array values, in this implementation they will be overwritten.
     *
     * @see https://stackoverflow.com/a/25712428
     *
     * @param array<mixed> $array1
     * @param array<mixed> $array2
     *
     * @return array<mixed>
     */
    private function array_merge_deep(array $array1, array $array2): array
    {
        foreach ($array2 as $key => &$value) {
            if (is_numeric($key)) {
                if (!in_array($value, $array1, true)) {
                    $array1[] = $value;
                }
            } elseif (is_array($value) && isset($array1[$key]) && is_array($array1[$key])) {
                $array1[$key] = $this->array_merge_deep($array1[$key], $value);
            } else {
                $array1[$key] = $value;
            }
        }
        return $array1;
    }
}
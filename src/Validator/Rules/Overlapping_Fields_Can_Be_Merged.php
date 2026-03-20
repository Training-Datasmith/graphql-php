<?php

declare (strict_types=1);
namespace Graph_Ql\Validator\Rules;

use Graph_Ql\Error\Error;
use Graph_Ql\Language\AST\Argument_Node;
use Graph_Ql\Language\AST\Field_Node;
use Graph_Ql\Language\AST\Fragment_Definition_Node;
use Graph_Ql\Language\AST\Fragment_Spread_Node;
use Graph_Ql\Language\AST\Inline_Fragment_Node;
use Graph_Ql\Language\AST\Node;
use Graph_Ql\Language\AST\Node_Kind;
use Graph_Ql\Language\AST\Node_List;
use Graph_Ql\Language\AST\Selection_Set_Node;
use Graph_Ql\Language\Printer;
use Graph_Ql\Type\Definition\Field_Definition;
use Graph_Ql\Type\Definition\Interface_Type;
use Graph_Ql\Type\Definition\List_Of_Type;
use Graph_Ql\Type\Definition\Non_Null;
use Graph_Ql\Type\Definition\Object_Type;
use Graph_Ql\Type\Definition\Type;
use Graph_Ql\Utils\AST;
use Graph_Ql\Utils\Pair_Set;
use Graph_Ql\Validator\Query_Validation_Context;
/**
 * ReasonOrReasons is recursive, but PHPStan does not support that.
 *
 * @phpstan-type ReasonOrReasons string|array<array{string, string|array<mixed>}>
 * @phpstan-type Conflict array{array{string, ReasonOrReasons}, array<int, FieldNode>, array<int, FieldNode>}
 * @phpstan-type FieldInfo array{Type|null, FieldNode, FieldDefinition|null}
 * @phpstan-type FieldMap array<string, array<int, FieldInfo>>
 */
class Overlapping_Fields_Can_Be_Merged extends Validation_Rule
{
    /**
     * A memoization for when two fragments are compared "between" each other for
     * conflicts. Two fragments may be compared many times, so memoizing this can
     * dramatically improve the performance of this validator.
     */
    protected Pair_Set $compared_fragment_pairs;
    /**
     * A cache for the "field map" and list of fragment names found in any given
     * selection set. Selection sets may be asked for this information multiple
     * times, so this improves the performance of this validator.
     *
     * @phpstan-var \SplObjectStorage<SelectionSetNode, array{FieldMap, array<int, string>}>
     */
    protected \Spl_Object_Storage $cached_fields_and_fragment_names;
    public function get_visitor(Query_Validation_Context $context): array
    {
        $this->compared_fragment_pairs = new Pair_Set();
        $this->cached_fields_and_fragment_names = new \Spl_Object_Storage();
        return [Node_Kind::SELECTION_SET => function (Selection_Set_Node $selection_set) use ($context): void {
            $conflicts = $this->find_conflicts_within_selection_set($context, $context->get_parent_type(), $selection_set);
            foreach ($conflicts as $conflict) {
                [[$response_name, $reason], $fields1, $fields2] = $conflict;
                $context->report_error(new Error(static::fields_conflict_message($response_name, $reason), array_merge($fields1, $fields2)));
            }
        }];
    }
    /**
     * Find all conflicts found "within" a selection set, including those found
     * via spreading in fragments. Called when visiting each SelectionSet in the
     * GraphQL Document.
     *
     * @throws \Exception
     *
     * @phpstan-return array<int, Conflict>
     */
    protected function find_conflicts_within_selection_set(Query_Validation_Context $context, ?Type $parent_type, Selection_Set_Node $selection_set): array
    {
        [$field_map, $fragment_names] = $this->get_fields_and_fragment_names($context, $parent_type, $selection_set);
        $conflicts = [];
        // (A) Find all conflicts "within" the fields of this selection set.
        // Note: this is the *only place* `collectConflictsWithin` is called.
        $this->collect_conflicts_within($context, $conflicts, $field_map);
        $fragment_names_length = count($fragment_names);
        if ($fragment_names_length !== 0) {
            // (B) Then collect conflicts between these fields and those represented by
            // each spread fragment name found.
            $compared_fragments = [];
            for ($i = 0; $i < $fragment_names_length; ++$i) {
                $this->collect_conflicts_between_fields_and_fragment($context, $conflicts, $compared_fragments, false, $field_map, $fragment_names[$i]);
                // (C) Then compare this fragment with all other fragments found in this
                // selection set to collect conflicts between fragments spread together.
                // This compares each item in the list of fragment names to every other item
                // in that same list (except for itself).
                for ($j = $i + 1; $j < $fragment_names_length; ++$j) {
                    $this->collect_conflicts_between_fragments($context, $conflicts, false, $fragment_names[$i], $fragment_names[$j]);
                }
            }
        }
        return $conflicts;
    }
    /**
     * Given a selection set, return the collection of fields (a mapping of response
     * name to field ASTs and definitions) as well as a list of fragment names
     * referenced via fragment spreads.
     *
     * @throws \Exception
     *
     * @return array{FieldMap, array<int, string>}
     */
    protected function get_fields_and_fragment_names(Query_Validation_Context $context, ?Type $parent_type, Selection_Set_Node $selection_set): array
    {
        if (!isset($this->cached_fields_and_fragment_names[$selection_set])) {
            /** @phpstan-var FieldMap $astAndDefs */
            $ast_and_defs = [];
            /** @var array<string, bool> $fragmentNames */
            $fragment_names = [];
            $this->internal_collect_fields_and_fragment_names($context, $parent_type, $selection_set, $ast_and_defs, $fragment_names);
            return $this->cached_fields_and_fragment_names[$selection_set] = [$ast_and_defs, array_keys($fragment_names)];
        }
        return $this->cached_fields_and_fragment_names[$selection_set];
    }
    /**
     * Algorithm:.
     *
     * Conflicts occur when two fields exist in a query which will produce the same
     * response name, but represent differing values, thus creating a conflict.
     * The algorithm below finds all conflicts via making a series of comparisons
     * between fields. In order to compare as few fields as possible, this makes
     * a series of comparisons "within" sets of fields and "between" sets of fields.
     *
     * Given any selection set, a collection produces both a set of fields by
     * also including all inline fragments, as well as a list of fragments
     * referenced by fragment spreads.
     *
     * A) Each selection set represented in the document first compares "within" its
     * collected set of fields, finding any conflicts between every pair of
     * overlapping fields.
     * Note: This is the *only time* that a the fields "within" a set are compared
     * to each other. After this only fields "between" sets are compared.
     *
     * B) Also, if any fragment is referenced in a selection set, then a
     * comparison is made "between" the original set of fields and the
     * referenced fragment.
     *
     * C) Also, if multiple fragments are referenced, then comparisons
     * are made "between" each referenced fragment.
     *
     * D) When comparing "between" a set of fields and a referenced fragment, first
     * a comparison is made between each field in the original set of fields and
     * each field in the the referenced set of fields.
     *
     * E) Also, if any fragment is referenced in the referenced selection set,
     * then a comparison is made "between" the original set of fields and the
     * referenced fragment (recursively referring to step D).
     *
     * F) When comparing "between" two fragments, first a comparison is made between
     * each field in the first referenced set of fields and each field in the the
     * second referenced set of fields.
     *
     * G) Also, any fragments referenced by the first must be compared to the
     * second, and any fragments referenced by the second must be compared to the
     * first (recursively referring to step F).
     *
     * H) When comparing two fields, if both have selection sets, then a comparison
     * is made "between" both selection sets, first comparing the set of fields in
     * the first selection set with the set of fields in the second.
     *
     * I) Also, if any fragment is referenced in either selection set, then a
     * comparison is made "between" the other set of fields and the
     * referenced fragment.
     *
     * J) Also, if two fragments are referenced in both selection sets, then a
     * comparison is made "between" the two fragments.
     */
    /**
     * Given a reference to a fragment, return the represented collection of fields
     * as well as a list of nested fragment names referenced via fragment spreads.
     *
     * @param array<string, bool> $fragmentNames
     *
     * @phpstan-param FieldMap $astAndDefs
     *
     * @throws \Exception
     */
    protected function internal_collect_fields_and_fragment_names(Query_Validation_Context $context, ?Type $parent_type, Selection_Set_Node $selection_set, array &$ast_and_defs, array &$fragment_names): void
    {
        foreach ($selection_set->selections as $selection) {
            switch (true) {
                case $selection instanceof Field_Node:
                    $field_name = $selection->name->value;
                    $field_def = null;
                    if (($parent_type instanceof Object_Type || $parent_type instanceof Interface_Type) && $parent_type->has_field($field_name)) {
                        $field_def = $parent_type->get_field($field_name);
                    }
                    $response_name = $selection->alias->value ?? $field_name;
                    $ast_and_defs[$response_name] ??= [];
                    $ast_and_defs[$response_name][] = [$parent_type, $selection, $field_def];
                    break;
                case $selection instanceof Fragment_Spread_Node:
                    $fragment_names[$selection->name->value] = true;
                    break;
                case $selection instanceof Inline_Fragment_Node:
                    $type_condition = $selection->type_condition;
                    $inline_fragment_type = $type_condition === null ? $parent_type : AST::type_from_ast([$context->get_schema(), 'getType'], $type_condition);
                    $this->internal_collect_fields_and_fragment_names($context, $inline_fragment_type, $selection->selection_set, $ast_and_defs, $fragment_names);
                    break;
            }
        }
    }
    /**
     * Collect all Conflicts "within" one collection of fields.
     *
     * @param array<int, Conflict> $conflicts
     *
     * @phpstan-param FieldMap $fieldMap
     *
     * @throws \Exception
     */
    protected function collect_conflicts_within(Query_Validation_Context $context, array &$conflicts, array $field_map): void
    {
        // A field map is a keyed collection, where each key represents a response
        // name and the value at that key is a list of all fields which provide that
        // response name. For every response name, if there are multiple fields, they
        // must be compared to find a potential conflict.
        foreach ($field_map as $response_name => $fields) {
            // This compares every field in the list to every other field in this list
            // (except to itself). If the list only has one item, nothing needs to
            // be compared.
            $fields_length = count($fields);
            if ($fields_length <= 1) {
                continue;
            }
            for ($i = 0; $i < $fields_length; ++$i) {
                for ($j = $i + 1; $j < $fields_length; ++$j) {
                    $conflict = $this->find_conflict(
                        $context,
                        false,
                        // within one collection is never mutually exclusive
                        $response_name,
                        $fields[$i],
                        $fields[$j]
                    );
                    if ($conflict !== null) {
                        $conflicts[] = $conflict;
                    }
                }
            }
        }
    }
    /**
     * Determines if there is a conflict between two particular fields, including
     * comparing their sub-fields.
     *
     * @param array{Type|null, FieldNode, FieldDefinition|null} $field1
     * @param array{Type|null, FieldNode, FieldDefinition|null} $field2
     *
     * @throws \Exception
     *
     * @phpstan-return Conflict|null
     */
    protected function find_conflict(Query_Validation_Context $context, bool $parent_fields_are_mutually_exclusive, string $response_name, array $field1, array $field2): ?array
    {
        [$parent_type1, $ast1, $def1] = $field1;
        [$parent_type2, $ast2, $def2] = $field2;
        // If it is known that two fields could not possibly apply at the same
        // time, due to the parent types, then it is safe to permit them to diverge
        // in aliased field or arguments used as they will not present any ambiguity
        // by differing.
        // It is known that two parent types could never overlap if they are
        // different Object types. Interface or Union types might overlap - if not
        // in the current state of the schema, then perhaps in some future version,
        // thus may not safely diverge.
        $are_mutually_exclusive = $parent_fields_are_mutually_exclusive || $parent_type1 !== $parent_type2 && $parent_type1 instanceof Object_Type && $parent_type2 instanceof Object_Type;
        // The return type for each field.
        $type1 = $def1 === null ? null : $def1->get_type();
        $type2 = $def2 === null ? null : $def2->get_type();
        if (!$are_mutually_exclusive) {
            // Two aliases must refer to the same field.
            $name1 = $ast1->name->value;
            $name2 = $ast2->name->value;
            if ($name1 !== $name2) {
                return [[$response_name, "{$name1} and {$name2} are different fields"], [$ast1], [$ast2]];
            }
            if (!$this->same_arguments($ast1->arguments, $ast2->arguments)) {
                return [[$response_name, 'they have differing arguments'], [$ast1], [$ast2]];
            }
        }
        if ($type1 !== null && $type2 !== null && $this->do_types_conflict($type1, $type2)) {
            return [[$response_name, "they return conflicting types {$type1} and {$type2}"], [$ast1], [$ast2]];
        }
        // Collect and compare sub-fields. Use the same "visited fragment names" list
        // for both collections so fields in a fragment reference are never
        // compared to themselves.
        $selection_set1 = $ast1->selection_set;
        $selection_set2 = $ast2->selection_set;
        if ($selection_set1 !== null && $selection_set2 !== null) {
            $conflicts = $this->find_conflicts_between_sub_selection_sets($context, $are_mutually_exclusive, Type::get_named_type($type1), $selection_set1, Type::get_named_type($type2), $selection_set2);
            return $this->subfield_conflicts($conflicts, $response_name, $ast1, $ast2);
        }
        return null;
    }
    /**
     * @param NodeList<ArgumentNode> $arguments1 keep
     * @param NodeList<ArgumentNode> $arguments2 keep
     *
     * @throws \JsonException
     */
    protected function same_arguments(Node_List $arguments1, Node_List $arguments2): bool
    {
        if (count($arguments1) !== count($arguments2)) {
            return false;
        }
        foreach ($arguments1 as $argument1) {
            $argument2 = null;
            foreach ($arguments2 as $argument) {
                if ($argument->name->value === $argument1->name->value) {
                    $argument2 = $argument;
                    break;
                }
            }
            if ($argument2 === null) {
                return false;
            }
            if (!$this->same_value($argument1->value, $argument2->value)) {
                return false;
            }
        }
        return true;
    }
    /** @throws \JsonException */
    protected function same_value(Node $value1, Node $value2): bool
    {
        return Printer::do_print($value1) === Printer::do_print($value2);
    }
    /**
     * Two types conflict if both types could not apply to a value simultaneously.
     *
     * Composite types are ignored as their individual field types will be compared
     * later recursively. However, List and Non-Null types must match.
     */
    protected function do_types_conflict(Type $type1, Type $type2): bool
    {
        if ($type1 instanceof List_Of_Type) {
            return $type2 instanceof List_Of_Type ? $this->do_types_conflict($type1->get_wrapped_type(), $type2->get_wrapped_type()) : true;
        }
        if ($type2 instanceof List_Of_Type) {
            return true;
        }
        if ($type1 instanceof Non_Null) {
            return $type2 instanceof Non_Null ? $this->do_types_conflict($type1->get_wrapped_type(), $type2->get_wrapped_type()) : true;
        }
        if ($type2 instanceof Non_Null) {
            return true;
        }
        if (Type::is_leaf_type($type1) || Type::is_leaf_type($type2)) {
            return $type1 !== $type2;
        }
        return false;
    }
    /**
     * Find all conflicts found between two selection sets, including those found
     * via spreading in fragments. Called when determining if conflicts exist
     * between the sub-fields of two overlapping fields.
     *
     * @throws \Exception
     *
     * @return array<int, Conflict>
     */
    protected function find_conflicts_between_sub_selection_sets(Query_Validation_Context $context, bool $are_mutually_exclusive, ?Type $parent_type1, Selection_Set_Node $selection_set1, ?Type $parent_type2, Selection_Set_Node $selection_set2): array
    {
        $conflicts = [];
        [$field_map1, $fragment_names1] = $this->get_fields_and_fragment_names($context, $parent_type1, $selection_set1);
        [$field_map2, $fragment_names2] = $this->get_fields_and_fragment_names($context, $parent_type2, $selection_set2);
        // (H) First, collect all conflicts between these two collections of field.
        $this->collect_conflicts_between($context, $conflicts, $are_mutually_exclusive, $field_map1, $field_map2);
        // (I) Then collect conflicts between the first collection of fields and
        // those referenced by each fragment name associated with the second.
        $fragment_names2length = count($fragment_names2);
        if ($fragment_names2length !== 0) {
            $compared_fragments = [];
            for ($j = 0; $j < $fragment_names2length; ++$j) {
                $this->collect_conflicts_between_fields_and_fragment($context, $conflicts, $compared_fragments, $are_mutually_exclusive, $field_map1, $fragment_names2[$j]);
            }
        }
        // (I) Then collect conflicts between the second collection of fields and
        // those referenced by each fragment name associated with the first.
        $fragment_names1length = count($fragment_names1);
        if ($fragment_names1length !== 0) {
            $compared_fragments = [];
            for ($i = 0; $i < $fragment_names1length; ++$i) {
                $this->collect_conflicts_between_fields_and_fragment($context, $conflicts, $compared_fragments, $are_mutually_exclusive, $field_map2, $fragment_names1[$i]);
            }
        }
        // (J) Also collect conflicts between any fragment names by the first and
        // fragment names by the second. This compares each item in the first set of
        // names to each item in the second set of names.
        for ($i = 0; $i < $fragment_names1length; ++$i) {
            for ($j = 0; $j < $fragment_names2length; ++$j) {
                $this->collect_conflicts_between_fragments($context, $conflicts, $are_mutually_exclusive, $fragment_names1[$i], $fragment_names2[$j]);
            }
        }
        return $conflicts;
    }
    /**
     * Collect all Conflicts between two collections of fields. This is similar to,
     * but different from the `collectConflictsWithin` function above. This check
     * assumes that `collectConflictsWithin` has already been called on each
     * provided collection of fields. This is true because this validator traverses
     * each individual selection set.
     *
     * @phpstan-param array<int, Conflict> $conflicts
     * @phpstan-param FieldMap $fieldMap1
     * @phpstan-param FieldMap $fieldMap2
     *
     * @throws \Exception
     */
    protected function collect_conflicts_between(Query_Validation_Context $context, array &$conflicts, bool $parent_fields_are_mutually_exclusive, array $field_map1, array $field_map2): void
    {
        // A field map is a keyed collection, where each key represents a response
        // name and the value at that key is a list of all fields which provide that
        // response name. For any response name which appears in both provided field
        // maps, each field from the first field map must be compared to every field
        // in the second field map to find potential conflicts.
        foreach ($field_map1 as $response_name => $fields1) {
            if (!isset($field_map2[$response_name])) {
                continue;
            }
            $fields2 = $field_map2[$response_name];
            $fields1Length = count($fields1);
            $fields2Length = count($fields2);
            for ($i = 0; $i < $fields1Length; ++$i) {
                for ($j = 0; $j < $fields2Length; ++$j) {
                    $conflict = $this->find_conflict($context, $parent_fields_are_mutually_exclusive, $response_name, $fields1[$i], $fields2[$j]);
                    if ($conflict !== null) {
                        $conflicts[] = $conflict;
                    }
                }
            }
        }
    }
    /**
     * Collect all conflicts found between a set of fields and a fragment reference
     * including via spreading in any nested fragments.
     *
     * @param array<string, true> $comparedFragments
     *
     * @phpstan-param array<int, Conflict> $conflicts
     * @phpstan-param FieldMap $fieldMap
     *
     * @throws \Exception
     */
    protected function collect_conflicts_between_fields_and_fragment(Query_Validation_Context $context, array &$conflicts, array &$compared_fragments, bool $are_mutually_exclusive, array $field_map, string $fragment_name): void
    {
        if (isset($compared_fragments[$fragment_name])) {
            return;
        }
        $compared_fragments[$fragment_name] = true;
        $fragment = $context->get_fragment($fragment_name);
        if ($fragment === null) {
            return;
        }
        [$field_map2, $fragment_names2] = $this->get_referenced_fields_and_fragment_names($context, $fragment);
        if ($field_map === $field_map2) {
            return;
        }
        // (D) First collect any conflicts between the provided collection of fields
        // and the collection of fields represented by the given fragment.
        $this->collect_conflicts_between($context, $conflicts, $are_mutually_exclusive, $field_map, $field_map2);
        // (E) Then collect any conflicts between the provided collection of fields
        // and any fragment names found in the given fragment.
        $fragment_names2length = count($fragment_names2);
        for ($i = 0; $i < $fragment_names2length; ++$i) {
            $this->collect_conflicts_between_fields_and_fragment($context, $conflicts, $compared_fragments, $are_mutually_exclusive, $field_map, $fragment_names2[$i]);
        }
    }
    /**
     * Given a reference to a fragment, return the represented collection of fields
     * as well as a list of nested fragment names referenced via fragment spreads.
     *
     * @throws \Exception
     *
     * @phpstan-return array{FieldMap, array<int, string>}
     */
    protected function get_referenced_fields_and_fragment_names(Query_Validation_Context $context, Fragment_Definition_Node $fragment): array
    {
        // Short-circuit building a type from the AST if possible.
        if (isset($this->cached_fields_and_fragment_names[$fragment->selection_set])) {
            return $this->cached_fields_and_fragment_names[$fragment->selection_set];
        }
        $fragment_type = AST::type_from_ast([$context->get_schema(), 'getType'], $fragment->type_condition);
        return $this->get_fields_and_fragment_names($context, $fragment_type, $fragment->selection_set);
    }
    /**
     * Collect all conflicts found between two fragments, including via spreading in
     * any nested fragments.
     *
     * @phpstan-param array<int, Conflict> $conflicts
     *
     * @throws \Exception
     */
    protected function collect_conflicts_between_fragments(Query_Validation_Context $context, array &$conflicts, bool $are_mutually_exclusive, string $fragment_name1, string $fragment_name2): void
    {
        // No need to compare a fragment to itself.
        if ($fragment_name1 === $fragment_name2) {
            return;
        }
        // Memoize so two fragments are not compared for conflicts more than once.
        if ($this->compared_fragment_pairs->has($fragment_name1, $fragment_name2, $are_mutually_exclusive)) {
            return;
        }
        $this->compared_fragment_pairs->add($fragment_name1, $fragment_name2, $are_mutually_exclusive);
        $fragment1 = $context->get_fragment($fragment_name1);
        $fragment2 = $context->get_fragment($fragment_name2);
        if ($fragment1 === null || $fragment2 === null) {
            return;
        }
        [$field_map1, $fragment_names1] = $this->get_referenced_fields_and_fragment_names($context, $fragment1);
        [$field_map2, $fragment_names2] = $this->get_referenced_fields_and_fragment_names($context, $fragment2);
        // (F) First, collect all conflicts between these two collections of fields
        // (not including any nested fragments).
        $this->collect_conflicts_between($context, $conflicts, $are_mutually_exclusive, $field_map1, $field_map2);
        // (G) Then collect conflicts between the first fragment and any nested
        // fragments spread in the second fragment.
        $fragment_names2length = count($fragment_names2);
        for ($j = 0; $j < $fragment_names2length; ++$j) {
            $this->collect_conflicts_between_fragments($context, $conflicts, $are_mutually_exclusive, $fragment_name1, $fragment_names2[$j]);
        }
        // (G) Then collect conflicts between the second fragment and any nested
        // fragments spread in the first fragment.
        $fragment_names1length = count($fragment_names1);
        for ($i = 0; $i < $fragment_names1length; ++$i) {
            $this->collect_conflicts_between_fragments($context, $conflicts, $are_mutually_exclusive, $fragment_names1[$i], $fragment_name2);
        }
    }
    /**
     * Merge Conflicts between two sub-fields into a single Conflict.
     *
     * @phpstan-param array<int, Conflict> $conflicts
     *
     * @phpstan-return Conflict|null
     */
    protected function subfield_conflicts(array $conflicts, string $response_name, Field_Node $ast1, Field_Node $ast2): ?array
    {
        if ($conflicts === []) {
            return null;
        }
        $reasons = [];
        foreach ($conflicts as $conflict) {
            $reasons[] = $conflict[0];
        }
        $fields1 = [$ast1];
        foreach ($conflicts as $conflict) {
            foreach ($conflict[1] as $field) {
                $fields1[] = $field;
            }
        }
        $fields2 = [$ast2];
        foreach ($conflicts as $conflict) {
            foreach ($conflict[2] as $field) {
                $fields2[] = $field;
            }
        }
        return [[$response_name, $reasons], $fields1, $fields2];
    }
    /**
     * @param string|array $reasonOrReasons
     *
     * @phpstan-param ReasonOrReasons $reasonOrReasons
     */
    public static function fields_conflict_message(string $response_name, $reason_or_reasons): string
    {
        $reason_message = static::reason_message($reason_or_reasons);
        return "Fields \"{$response_name}\" conflict because {$reason_message}. Use different aliases on the fields to fetch both if this was intentional.";
    }
    /**
     * @param string|array $reasonOrReasons
     *
     * @phpstan-param ReasonOrReasons $reasonOrReasons
     */
    public static function reason_message($reason_or_reasons): string
    {
        if (is_array($reason_or_reasons)) {
            $reasons = array_map(static function (array $reason): string {
                [$response_name, $sub_reason] = $reason;
                $reason_message = static::reason_message($sub_reason);
                return "subfields \"{$response_name}\" conflict because {$reason_message}";
            }, $reason_or_reasons);
            return implode(' and ', $reasons);
        }
        return $reason_or_reasons;
    }
}
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
use Graph_Ql\Language\AST\Operation_Definition_Node;
use Graph_Ql\Language\AST\Selection_Set_Node;
use Graph_Ql\Type\Introspection;
use Graph_Ql\Type\Schema;
/**
 * Structure containing information useful for field resolution process.
 *
 * Passed as 4th argument to every field resolver. See [docs on field resolving (data fetching)](data-fetching.md).
 *
 * @phpstan-import-type QueryPlanOptions from QueryPlan
 *
 * @phpstan-type Path list<string|int>
 */
class Resolve_Info
{
    /**
     * The definition of the field being resolved.
     *
     * @api
     */
    public Field_Definition $field_definition;
    /**
     * The name of the field being resolved.
     *
     * @api
     */
    public string $field_name;
    /**
     * Expected return type of the field being resolved.
     *
     * @api
     */
    public Type $return_type;
    /**
     * AST of all nodes referencing this field in the query.
     *
     * @api
     *
     * @var \ArrayObject<int, FieldNode>
     */
    public \ArrayObject $field_nodes;
    /**
     * Parent type of the field being resolved.
     *
     * @api
     */
    public Object_Type $parent_type;
    /**
     * Path to this field from the very root value. When fields are aliased, the path includes aliases.
     *
     * @api
     *
     * @var list<string|int>
     *
     * @phpstan-var Path
     */
    public array $path;
    /**
     * Path to this field from the very root value. This will never include aliases.
     *
     * @api
     *
     * @var list<string|int>
     *
     * @phpstan-var Path
     */
    public array $unaliased_path;
    /**
     * Instance of a schema used for execution.
     *
     * @api
     */
    public Schema $schema;
    /**
     * AST of all fragments defined in query.
     *
     * @api
     *
     * @var array<string, FragmentDefinitionNode>
     */
    public array $fragments = [];
    /**
     * Root value passed to query execution.
     *
     * @api
     *
     * @var mixed
     */
    public $root_value;
    /**
     * AST of operation definition node (query, mutation).
     *
     * @api
     */
    public Operation_Definition_Node $operation;
    /**
     * Array of variables passed to query execution.
     *
     * @api
     *
     * @var array<string, mixed>
     */
    public array $variable_values = [];
    /**
     * @param \ArrayObject<int, FieldNode> $fieldNodes
     * @param list<string|int> $path
     * @param array<string, FragmentDefinitionNode> $fragments
     * @param mixed|null $rootValue
     * @param array<string, mixed> $variableValues
     * @param list<string|int> $unaliasedPath
     *
     * @phpstan-param Path $path
     * @phpstan-param Path $unaliasedPath
     */
    public function __construct(Field_Definition $field_definition, \ArrayObject $field_nodes, Object_Type $parent_type, array $path, Schema $schema, array $fragments, $root_value, Operation_Definition_Node $operation, array $variable_values, array $unaliased_path = [])
    {
        $this->field_definition = $field_definition;
        $this->field_name = $field_definition->name;
        $this->return_type = $field_definition->get_type();
        $this->field_nodes = $field_nodes;
        $this->parent_type = $parent_type;
        $this->path = $path;
        $this->unaliased_path = $unaliased_path;
        $this->schema = $schema;
        $this->fragments = $fragments;
        $this->root_value = $root_value;
        $this->operation = $operation;
        $this->variable_values = $variable_values;
    }
    /**
     * Returns names of all fields selected in query for `$this->fieldName` up to `$depth` levels.
     *
     * Example:
     * {
     *   root {
     *     id
     *     nested {
     *       nested1
     *       nested2 {
     *         nested3
     *       }
     *     }
     *   }
     * }
     *
     * Given this ResolveInfo instance is a part of root field resolution, and $depth === 1,
     * this method will return:
     * [
     *     'id' => true,
     *     'nested' => [
     *         'nested1' => true,
     *         'nested2' => true,
     *     ],
     * ]
     *
     * This method does not consider conditional typed fragments.
     * Use it with care for fields of interface and union types.
     *
     * @param int $depth How many levels to include in the output beyond the first
     *
     * @return array<string, mixed>
     *
     * @api
     */
    public function get_field_selection(int $depth = 0): array
    {
        $fields = [];
        foreach ($this->field_nodes as $field_node) {
            $selection_set = $field_node->selection_set;
            if ($selection_set !== null) {
                $fields = array_merge_recursive($fields, $this->fold_selection_set($selection_set, $depth));
            }
        }
        return $fields;
    }
    /**
     * Returns names and args of all fields selected in query for `$this->fieldName` up to `$depth` levels, including aliases.
     *
     * The result maps original field names to a map of selections for that field, including aliases.
     * For each of those selections, you can find the following keys:
     * - "args" contains the passed arguments for this field/alias (not on an union inline fragment)
     * - "type" contains the related Type instance found (will be the same for all aliases of a field)
     * - "selectionSet" contains potential nested fields of this field/alias (only on ObjectType). The structure is recursive from here.
     * - "unions" contains potential object types contained in an UnionType (only on UnionType). The structure is recursive from here and will go through the selectionSet of the object types.
     *
     * Example:
     * {
     *   root {
     *     id
     *     nested {
     *      nested1(myArg: 1)
     *      nested1Bis: nested1
     *     }
     *     alias1: nested {
     *       nested1(myArg: 2, mySecondAg: "test")
     *     }
     *     myUnion(myArg: 3) {
     *       ...on Nested {
     *         nested1(myArg: 4)
     *       }
     *       ...on MyCustomObject {
     *         nested3
     *       }
     *     }
     *   }
     * }
     *
     * Given this ResolveInfo instance is a part of root field resolution,
     * $depth === 1,
     * and fields "nested" represents an ObjectType named "Nested",
     * this method will return:
     * [
     *     'id' => [
     *         'id' => [
     *              'args' => [],
     *              'type' => GraphQL\Type\Definition\IntType Object ( ... )),
     *         ],
     *     ],
     *     'nested' => [
     *         'nested' => [
     *             'args' => [],
     *             'type' => GraphQL\Type\Definition\ObjectType Object ( ... )),
     *             'selectionSet' => [
     *                 'nested1' => [
     *                     'nested1' => [
     *                          'args' => [
     *                              'myArg' => 1,
     *                          ],
     *                          'type' => GraphQL\Type\Definition\StringType Object ( ... )),
     *                      ],
     *                      'nested1Bis' => [
     *                          'args' => [],
     *                          'type' => GraphQL\Type\Definition\StringType Object ( ... )),
     *                      ],
     *                 ],
     *             ],
     *         ],
     *     ],
     *     'alias1' => [
     *         'alias1' => [
     *             'args' => [],
     *             'type' => GraphQL\Type\Definition\ObjectType Object ( ... )),
     *             'selectionSet' => [
     *                 'nested1' => [
     *                     'nested1' => [
     *                          'args' => [
     *                              'myArg' => 2,
     *                              'mySecondAg' => "test",
     *                          ],
     *                          'type' => GraphQL\Type\Definition\StringType Object ( ... )),
     *                      ],
     *                 ],
     *             ],
     *         ],
     *     ],
     *     'myUnion' => [
     *         'myUnion' => [
     *              'args' => [
     *                  'myArg' => 3,
     *              ],
     *              'type' => GraphQL\Type\Definition\UnionType Object ( ... )),
     *              'unions' => [
     *                  'Nested' => [
     *                      'type' => GraphQL\Type\Definition\ObjectType Object ( ... )),
     *                      'selectionSet' => [
     *                          'nested1' => [
     *                              'nested1' => [
     *                                  'args' => [
     *                                      'myArg' => 4,
     *                                  ],
     *                                  'type' => GraphQL\Type\Definition\StringType Object ( ... )),
     *                              ],
     *                          ],
     *                      ],
     *                  ],
     *                  'MyCustomObject' => [
     *                       'type' => GraphQL\Tests\Type\TestClasses\MyCustomType Object ( ... )),
     *                       'selectionSet' => [
     *                           'nested3' => [
     *                               'nested3' => [
     *                                   'args' => [],
     *                                   'type' => GraphQL\Type\Definition\StringType Object ( ... )),
     *                               ],
     *                           ],
     *                       ],
     *                   ],
     *              ],
     *          ],
     *      ],
     * ]
     *
     * @param int $depth How many levels to include in the output beyond the first
     *
     * @throws \Exception
     * @throws Error
     * @throws InvariantViolation
     *
     * @return array<string, mixed>
     *
     * @api
     */
    public function get_field_selection_with_aliases(int $depth = 0): array
    {
        $fields = [];
        foreach ($this->field_nodes as $field_node) {
            $selection_set = $field_node->selection_set;
            if ($selection_set !== null) {
                $field = $this->parent_type->get_field($field_node->name->value);
                $field_type = $field->get_type();
                $fields = array_merge_recursive($fields, $this->fold_selection_with_alias($selection_set, $depth, $field_type));
            }
        }
        return $fields;
    }
    /**
     * @param QueryPlanOptions $options
     *
     * @throws \Exception
     * @throws Error
     * @throws InvariantViolation
     */
    public function look_ahead(array $options = []): Query_Plan
    {
        return new Query_Plan($this->parent_type, $this->schema, $this->field_nodes, $this->variable_values, $this->fragments, $options);
    }
    /** @return array<string, bool> */
    private function fold_selection_set(Selection_Set_Node $selection_set, int $descend): array
    {
        /** @var array<string, bool> $fields */
        $fields = [];
        foreach ($selection_set->selections as $selection) {
            if ($selection instanceof Field_Node) {
                $fields[$selection->name->value] = $descend > 0 && $selection->selection_set !== null ? array_merge_recursive($fields[$selection->name->value] ?? [], $this->fold_selection_set($selection->selection_set, $descend - 1)) : true;
            } elseif ($selection instanceof Fragment_Spread_Node) {
                $spread_name = $selection->name->value;
                $fragment = $this->fragments[$spread_name] ?? null;
                if ($fragment === null) {
                    continue;
                }
                $fields = array_merge_recursive($this->fold_selection_set($fragment->selection_set, $descend), $fields);
            } elseif ($selection instanceof Inline_Fragment_Node) {
                $fields = array_merge_recursive($this->fold_selection_set($selection->selection_set, $descend), $fields);
            }
        }
        return $fields;
    }
    /**
     * @throws \Exception
     * @throws Error
     * @throws InvariantViolation
     *
     * @return array<string>
     */
    private function fold_selection_with_alias(Selection_Set_Node $selection_set, int $descend, Type $parent_type): array
    {
        /** @var array<string, bool> $fields */
        $fields = [];
        if ($parent_type instanceof Wrapping_Type) {
            $parent_type = $parent_type->get_innermost_type();
        }
        foreach ($selection_set->selections as $selection) {
            if ($selection instanceof Field_Node) {
                $field_name = $selection->name->value;
                $alias_name = $selection->alias->value ?? $field_name;
                if ($field_name === Introspection::TYPE_NAME_FIELD_NAME) {
                    continue;
                }
                assert($parent_type instanceof Has_Fields_Type, 'ensured by query validation');
                $alias_info =& $fields[$field_name][$alias_name];
                $field_def = $parent_type->get_field($field_name);
                $alias_info['args'] = Values::get_argument_values($field_def, $selection, $this->variable_values);
                $field_type = $field_def->get_type();
                $named_field_type = $field_type;
                if ($named_field_type instanceof Wrapping_Type) {
                    $named_field_type = $named_field_type->get_innermost_type();
                }
                $alias_info['type'] = $named_field_type;
                if ($descend <= 0) {
                    continue;
                }
                $nested_selection_set = $selection->selection_set;
                if ($nested_selection_set === null) {
                    continue;
                }
                if ($named_field_type instanceof Union_Type) {
                    $alias_info['unions'] = $this->fold_selection_with_alias($nested_selection_set, $descend, $field_type);
                    continue;
                }
                $alias_info['selectionSet'] = $this->fold_selection_with_alias($nested_selection_set, $descend - 1, $field_type);
            } elseif ($selection instanceof Fragment_Spread_Node) {
                $spread_name = $selection->name->value;
                $fragment = $this->fragments[$spread_name] ?? null;
                if ($fragment === null) {
                    continue;
                }
                $field_type = $this->schema->get_type($fragment->type_condition->name->value);
                assert($field_type instanceof Type, 'ensured by query validation');
                $fields = array_merge_recursive($this->fold_selection_with_alias($fragment->selection_set, $descend, $field_type), $fields);
            } elseif ($selection instanceof Inline_Fragment_Node) {
                $type_condition = $selection->type_condition;
                $field_type = $type_condition === null ? $parent_type : $this->schema->get_type($type_condition->name->value);
                assert($field_type instanceof Type, 'ensured by query validation');
                if ($parent_type instanceof Union_Type) {
                    assert($field_type instanceof Named_Type, 'ensured by query validation');
                    $field_type_info =& $fields[$field_type->name()];
                    $field_type_info['type'] = $field_type;
                    $field_type_info['selectionSet'] = $this->fold_selection_with_alias($selection->selection_set, $descend, $field_type);
                    continue;
                }
                $fields = array_merge_recursive($this->fold_selection_with_alias($selection->selection_set, $descend, $field_type), $fields);
            }
        }
        return $fields;
    }
}
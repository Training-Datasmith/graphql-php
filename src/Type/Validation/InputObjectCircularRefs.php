<?php

declare (strict_types=1);
namespace Graph_Ql\Type\Validation;

use Graph_Ql\Error\Invariant_Violation;
use Graph_Ql\Language\AST\Input_Value_Definition_Node;
use Graph_Ql\Type\Definition\Input_Object_Field;
use Graph_Ql\Type\Definition\Input_Object_Type;
use Graph_Ql\Type\Definition\Non_Null;
use Graph_Ql\Type\Schema_Validation_Context;
class Input_Object_Circular_Refs
{
    private Schema_Validation_Context $schema_validation_context;
    /**
     * Tracks already visited types to maintain O(N) and to ensure that cycles
     * are not redundantly reported.
     *
     * @var array<string, bool>
     */
    private array $visited_types = [];
    /** @var array<int, InputObjectField> */
    private array $field_path = [];
    /**
     * Position in the type path.
     *
     * @var array<string, int>
     */
    private array $field_path_index_by_type_name = [];
    public function __construct(Schema_Validation_Context $schema_validation_context)
    {
        $this->schema_validation_context = $schema_validation_context;
    }
    /**
     * This does a straight-forward DFS to find cycles.
     * It does not terminate when a cycle was found but continues to explore
     * the graph to find all possible cycles.
     *
     * @throws InvariantViolation
     */
    public function validate(Input_Object_Type $input_obj): void
    {
        if (isset($this->visited_types[$input_obj->name])) {
            return;
        }
        $this->visited_types[$input_obj->name] = true;
        $this->field_path_index_by_type_name[$input_obj->name] = count($this->field_path);
        $field_map = $input_obj->get_fields();
        foreach ($field_map as $field) {
            $type = $field->get_type();
            if ($type instanceof Non_Null) {
                $field_type = $type->get_wrapped_type();
                // If the type of the field is anything else then a non-nullable input object,
                // there is no chance of an unbreakable cycle
                if ($field_type instanceof Input_Object_Type) {
                    $this->field_path[] = $field;
                    if (!isset($this->field_path_index_by_type_name[$field_type->name])) {
                        $this->validate($field_type);
                    } else {
                        $cycle_index = $this->field_path_index_by_type_name[$field_type->name];
                        $cycle_path = array_slice($this->field_path, $cycle_index);
                        $field_names = implode('.', array_map(static fn(Input_Object_Field $field): string => $field->name, $cycle_path));
                        $field_nodes = array_map(static fn(Input_Object_Field $field): ?Input_Value_Definition_Node => $field->ast_node, $cycle_path);
                        $this->schema_validation_context->report_error("Cannot reference Input Object \"{$field_type->name}\" within itself through a series of non-null fields: \"{$field_names}\".", $field_nodes);
                    }
                }
            }
            array_pop($this->field_path);
        }
        unset($this->field_path_index_by_type_name[$input_obj->name]);
    }
}
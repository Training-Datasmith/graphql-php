<?php

declare (strict_types=1);
namespace Graph_Ql\Validator\Rules;

use Graph_Ql\Error\Error;
use Graph_Ql\Error\Invariant_Violation;
use Graph_Ql\Language\AST\Directive_Definition_Node;
use Graph_Ql\Language\AST\Directive_Node;
use Graph_Ql\Language\AST\Enum_Type_Definition_Node;
use Graph_Ql\Language\AST\Enum_Type_Extension_Node;
use Graph_Ql\Language\AST\Enum_Value_Definition_Node;
use Graph_Ql\Language\AST\Field_Definition_Node;
use Graph_Ql\Language\AST\Field_Node;
use Graph_Ql\Language\AST\Fragment_Definition_Node;
use Graph_Ql\Language\AST\Fragment_Spread_Node;
use Graph_Ql\Language\AST\Inline_Fragment_Node;
use Graph_Ql\Language\AST\Input_Object_Type_Definition_Node;
use Graph_Ql\Language\AST\Input_Object_Type_Extension_Node;
use Graph_Ql\Language\AST\Input_Value_Definition_Node;
use Graph_Ql\Language\AST\Interface_Type_Definition_Node;
use Graph_Ql\Language\AST\Interface_Type_Extension_Node;
use Graph_Ql\Language\AST\Node;
use Graph_Ql\Language\AST\Node_Kind;
use Graph_Ql\Language\AST\Node_List;
use Graph_Ql\Language\AST\Object_Type_Definition_Node;
use Graph_Ql\Language\AST\Object_Type_Extension_Node;
use Graph_Ql\Language\AST\Operation_Definition_Node;
use Graph_Ql\Language\AST\Scalar_Type_Definition_Node;
use Graph_Ql\Language\AST\Scalar_Type_Extension_Node;
use Graph_Ql\Language\AST\Schema_Definition_Node;
use Graph_Ql\Language\AST\Schema_Extension_Node;
use Graph_Ql\Language\AST\Union_Type_Definition_Node;
use Graph_Ql\Language\AST\Union_Type_Extension_Node;
use Graph_Ql\Language\AST\Variable_Definition_Node;
use Graph_Ql\Language\Directive_Location;
use Graph_Ql\Language\Visitor;
use Graph_Ql\Type\Definition\Directive;
use Graph_Ql\Validator\Query_Validation_Context;
use Graph_Ql\Validator\Sdl_Validation_Context;
use Graph_Ql\Validator\Validation_Context;
/**
 * @phpstan-import-type VisitorArray from Visitor
 */
class Known_Directives extends Validation_Rule
{
    /** @throws InvariantViolation */
    public function get_visitor(Query_Validation_Context $context): array
    {
        return $this->get_ast_visitor($context);
    }
    /** @throws InvariantViolation */
    public function get_sdl_visitor(Sdl_Validation_Context $context): array
    {
        return $this->get_ast_visitor($context);
    }
    /**
     * @throws InvariantViolation
     *
     * @phpstan-return VisitorArray
     */
    public function get_ast_visitor(Validation_Context $context): array
    {
        $locations_map = [];
        $schema = $context->get_schema();
        $defined_directives = $schema === null ? Directive::get_internal_directives() : $schema->get_directives();
        foreach ($defined_directives as $directive) {
            $locations_map[$directive->name] = $directive->locations;
        }
        $ast_definition = $context->get_document()->definitions;
        foreach ($ast_definition as $def) {
            if ($def instanceof Directive_Definition_Node) {
                $location_names = [];
                foreach ($def->locations as $location) {
                    $location_names[] = $location->value;
                }
                $locations_map[$def->name->value] = $location_names;
            }
        }
        return [Node_Kind::DIRECTIVE => function (Directive_Node $node, $key, $parent, $path, array $ancestors) use ($context, $locations_map): void {
            $name = $node->name->value;
            $locations = $locations_map[$name] ?? null;
            if ($locations === null) {
                $context->report_error(new Error(static::unknown_directive_message($name), [$node]));
                return;
            }
            $candidate_location = $this->get_directive_location_for_ast_path($ancestors);
            if ($candidate_location === '' || in_array($candidate_location, $locations, true)) {
                return;
            }
            $context->report_error(new Error(static::misplaced_directive_message($name, $candidate_location), [$node]));
        }];
    }
    public static function unknown_directive_message(string $directive_name): string
    {
        return "Unknown directive \"@{$directive_name}\".";
    }
    /**
     * @param array<Node|NodeList<Node>> $ancestors
     *
     * @throws \Exception
     */
    protected function get_directive_location_for_ast_path(array $ancestors): string
    {
        $applied_to = $ancestors[count($ancestors) - 1];
        switch (true) {
            case $applied_to instanceof Operation_Definition_Node:
                switch ($applied_to->operation) {
                    case 'query':
                        return Directive_Location::QUERY;
                    case 'mutation':
                        return Directive_Location::MUTATION;
                    case 'subscription':
                        return Directive_Location::SUBSCRIPTION;
                }
            // no break, since all possible cases were handled
            case $applied_to instanceof Field_Node:
                return Directive_Location::FIELD;
            case $applied_to instanceof Fragment_Spread_Node:
                return Directive_Location::FRAGMENT_SPREAD;
            case $applied_to instanceof Inline_Fragment_Node:
                return Directive_Location::INLINE_FRAGMENT;
            case $applied_to instanceof Fragment_Definition_Node:
                return Directive_Location::FRAGMENT_DEFINITION;
            case $applied_to instanceof Variable_Definition_Node:
                return Directive_Location::VARIABLE_DEFINITION;
            case $applied_to instanceof Schema_Definition_Node:
            case $applied_to instanceof Schema_Extension_Node:
                return Directive_Location::SCHEMA;
            case $applied_to instanceof Scalar_Type_Definition_Node:
            case $applied_to instanceof Scalar_Type_Extension_Node:
                return Directive_Location::SCALAR;
            case $applied_to instanceof Object_Type_Definition_Node:
            case $applied_to instanceof Object_Type_Extension_Node:
                return Directive_Location::OBJECT;
            case $applied_to instanceof Field_Definition_Node:
                return Directive_Location::FIELD_DEFINITION;
            case $applied_to instanceof Interface_Type_Definition_Node:
            case $applied_to instanceof Interface_Type_Extension_Node:
                return Directive_Location::IFACE;
            case $applied_to instanceof Union_Type_Definition_Node:
            case $applied_to instanceof Union_Type_Extension_Node:
                return Directive_Location::UNION;
            case $applied_to instanceof Enum_Type_Definition_Node:
            case $applied_to instanceof Enum_Type_Extension_Node:
                return Directive_Location::ENUM;
            case $applied_to instanceof Enum_Value_Definition_Node:
                return Directive_Location::ENUM_VALUE;
            case $applied_to instanceof Input_Object_Type_Definition_Node:
            case $applied_to instanceof Input_Object_Type_Extension_Node:
                return Directive_Location::INPUT_OBJECT;
            case $applied_to instanceof Input_Value_Definition_Node:
                $parent_node = $ancestors[count($ancestors) - 3];
                return $parent_node instanceof Input_Object_Type_Definition_Node ? Directive_Location::INPUT_FIELD_DEFINITION : Directive_Location::ARGUMENT_DEFINITION;
            default:
                $unknown_location = get_class($applied_to);
                throw new \Exception("Unknown directive location: {$unknown_location}.");
        }
    }
    public static function misplaced_directive_message(string $directive_name, string $location): string
    {
        return "Directive \"{$directive_name}\" may not be used on \"{$location}\".";
    }
}
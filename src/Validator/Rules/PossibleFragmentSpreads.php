<?php

declare (strict_types=1);
namespace Graph_Ql\Validator\Rules;

use Graph_Ql\Error\Error;
use Graph_Ql\Error\Invariant_Violation;
use Graph_Ql\Language\AST\Fragment_Spread_Node;
use Graph_Ql\Language\AST\Inline_Fragment_Node;
use Graph_Ql\Language\AST\Node_Kind;
use Graph_Ql\Type\Definition\Abstract_Type;
use Graph_Ql\Type\Definition\Composite_Type;
use Graph_Ql\Type\Definition\Interface_Type;
use Graph_Ql\Type\Definition\Object_Type;
use Graph_Ql\Type\Definition\Type;
use Graph_Ql\Type\Definition\Union_Type;
use Graph_Ql\Type\Schema;
use Graph_Ql\Utils\AST;
use Graph_Ql\Validator\Query_Validation_Context;
class Possible_Fragment_Spreads extends Validation_Rule
{
    public function get_visitor(Query_Validation_Context $context): array
    {
        return [Node_Kind::INLINE_FRAGMENT => function (Inline_Fragment_Node $node) use ($context): void {
            $frag_type = $context->get_type();
            $parent_type = $context->get_parent_type();
            if (!$frag_type instanceof Composite_Type || !$parent_type instanceof Composite_Type || $this->do_types_overlap($context->get_schema(), $frag_type, $parent_type)) {
                return;
            }
            $context->report_error(new Error(static::type_incompatible_anon_spread_message($parent_type->to_string(), $frag_type->to_string()), [$node]));
        }, Node_Kind::FRAGMENT_SPREAD => function (Fragment_Spread_Node $node) use ($context): void {
            $frag_name = $node->name->value;
            $frag_type = $this->get_fragment_type($context, $frag_name);
            $parent_type = $context->get_parent_type();
            if ($frag_type === null || $parent_type === null || $this->do_types_overlap($context->get_schema(), $frag_type, $parent_type)) {
                return;
            }
            $context->report_error(new Error(static::type_incompatible_spread_message($frag_name, $parent_type->to_string(), $frag_type->to_string()), [$node]));
        }];
    }
    /**
     * @param CompositeType&Type $fragType
     * @param CompositeType&Type $parentType
     *
     * @throws InvariantViolation
     */
    protected function do_types_overlap(Schema $schema, Composite_Type $frag_type, Composite_Type $parent_type): bool
    {
        // Checking in the order of the most frequently used scenarios:
        // Parent type === fragment type
        if ($parent_type === $frag_type) {
            return true;
        }
        // Parent type is interface or union, fragment type is object type
        if ($parent_type instanceof Abstract_Type && $frag_type instanceof Object_Type) {
            return $schema->is_sub_type($parent_type, $frag_type);
        }
        // Parent type is object type, fragment type is interface (or rather rare - union)
        if ($parent_type instanceof Object_Type && $frag_type instanceof Abstract_Type) {
            return $schema->is_sub_type($frag_type, $parent_type);
        }
        // Both are object types:
        if ($parent_type instanceof Object_Type && $frag_type instanceof Object_Type) {
            return $parent_type === $frag_type;
        }
        // Both are interfaces
        // This case may be assumed valid only when implementations of two interfaces intersect
        // But we don't have information about all implementations at runtime
        // (getting this information via $schema->getPossibleTypes() requires scanning through whole schema
        // which is very costly to do at each request due to PHP "shared nothing" architecture)
        //
        // So in this case we just make it pass - invalid fragment spreads will be simply ignored during execution
        // See also https://github.com/webonyx/graphql-php/issues/69#issuecomment-283954602
        if ($parent_type instanceof Interface_Type && $frag_type instanceof Interface_Type) {
            return true;
            // Note that there is one case when we do have information about all implementations:
            // When schema descriptor is defined ($schema->hasDescriptor())
            // BUT we must avoid situation when some query that worked in development had suddenly stopped
            // working in production. So staying consistent and always validate.
        }
        // Interface within union
        if ($parent_type instanceof Union_Type && $frag_type instanceof Interface_Type) {
            foreach ($parent_type->get_types() as $type) {
                if ($type->implements_interface($frag_type)) {
                    return true;
                }
            }
        }
        if ($parent_type instanceof Interface_Type && $frag_type instanceof Union_Type) {
            foreach ($frag_type->get_types() as $type) {
                if ($type->implements_interface($parent_type)) {
                    return true;
                }
            }
        }
        if ($parent_type instanceof Union_Type && $frag_type instanceof Union_Type) {
            foreach ($frag_type->get_types() as $type) {
                if ($parent_type->is_possible_type($type)) {
                    return true;
                }
            }
        }
        return false;
    }
    public static function type_incompatible_anon_spread_message(string $parent_type, string $frag_type): string
    {
        return "Fragment cannot be spread here as objects of type \"{$parent_type}\" can never be of type \"{$frag_type}\".";
    }
    /**
     * @throws \Exception
     *
     * @return (CompositeType&Type)|null
     */
    protected function get_fragment_type(Query_Validation_Context $context, string $name): ?Type
    {
        $frag = $context->get_fragment($name);
        if ($frag === null) {
            return null;
        }
        $type = AST::type_from_ast([$context->get_schema(), 'getType'], $frag->type_condition);
        return $type instanceof Composite_Type ? $type : null;
    }
    public static function type_incompatible_spread_message(string $frag_name, string $parent_type, string $frag_type): string
    {
        return "Fragment \"{$frag_name}\" cannot be spread here as objects of type \"{$parent_type}\" can never be of type \"{$frag_type}\".";
    }
}
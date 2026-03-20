<?php

declare (strict_types=1);
namespace Graph_Ql\Type\Definition;

use Graph_Ql\Deferred;
/**
 * @phpstan-type ResolveTypeReturn ObjectType|string|callable(): (ObjectType|string|null)|Deferred|null
 * @phpstan-type ResolveType callable(mixed $objectValue, mixed $context, ResolveInfo $resolveInfo): ResolveTypeReturn
 * @phpstan-type ResolveValue callable(mixed $objectValue, mixed $context, ResolveInfo $resolveInfo): mixed
 */
interface Abstract_Type
{
    /**
     * Receives the original resolved value and transforms it if necessary.
     *
     * This will be called before `resolveType`.
     *
     * @param mixed $objectValue The resolved value for the object type
     * @param mixed $context The context that was passed to GraphQL::execute()
     *
     * @return mixed The possibly transformed value
     */
    public function resolve_value($object_value, $context, Resolve_Info $info);
    /**
     * Resolves the concrete ObjectType for the given value.
     *
     * This will be called after `resolveValue`.
     *
     * @param mixed $objectValue The resolved value for the object type
     * @param mixed $context The context that was passed to GraphQL::execute()
     *
     * @return ObjectType|string|callable|Deferred|null
     *
     * @phpstan-return ResolveTypeReturn
     */
    public function resolve_type($object_value, $context, Resolve_Info $info);
}
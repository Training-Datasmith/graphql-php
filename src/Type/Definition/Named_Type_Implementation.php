<?php

declare (strict_types=1);
namespace Graph_Ql\Type\Definition;

use Graph_Ql\Error\Invariant_Violation;
/**
 * @see NamedType
 */
trait Named_Type_Implementation
{
    public string $name;
    public ?string $description;
    public function to_string(): string
    {
        return $this->name;
    }
    /** @throws InvariantViolation */
    protected function infer_name(): string
    {
        if (isset($this->name)) {
            // @phpstan-ignore-line property might be uninitialized
            return $this->name;
        }
        // If class is extended - infer name from className
        // QueryType -> Type
        // SomeOtherType -> SomeOther
        $reflection = new \ReflectionClass($this);
        $name = $reflection->get_short_name();
        if ($reflection->get_namespace_name() !== __NAMESPACE__) {
            $without_prefix_type = preg_replace('~Type$~', '', $name);
            assert(is_string($without_prefix_type), 'regex is statically known to be correct');
            return $without_prefix_type;
        }
        throw new Invariant_Violation('Must provide name for Type.');
    }
    public function is_built_in_type(): bool
    {
        return in_array($this->name, Type::BUILT_IN_TYPE_NAMES, true);
    }
    public function name(): string
    {
        return $this->name;
    }
    public function description(): ?string
    {
        return $this->description;
    }
}
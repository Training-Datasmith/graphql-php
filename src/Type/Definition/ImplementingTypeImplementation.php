<?php

declare (strict_types=1);
namespace Graph_Ql\Type\Definition;

use Graph_Ql\Error\Invariant_Violation;
use Graph_Ql\Type\Schema;
/**
 * @see ImplementingType
 */
trait Implementing_Type_Implementation
{
    /**
     * Lazily initialized.
     *
     * @var array<int, InterfaceType>
     */
    private array $interfaces;
    public function implements_interface(Interface_Type $interface_type): bool
    {
        if (!isset($this->interfaces)) {
            $this->initialize_interfaces();
        }
        foreach ($this->interfaces as $interface) {
            if ($interface_type->name === $interface->name) {
                return true;
            }
        }
        return false;
    }
    /** @return array<int, InterfaceType> */
    public function get_interfaces(): array
    {
        if (!isset($this->interfaces)) {
            $this->initialize_interfaces();
        }
        return $this->interfaces;
    }
    private function initialize_interfaces(): void
    {
        $this->interfaces = [];
        if (!isset($this->config['interfaces'])) {
            return;
        }
        $interfaces = $this->config['interfaces'];
        if (is_callable($interfaces)) {
            $interfaces = $interfaces();
        }
        foreach ($interfaces as $interface) {
            $this->interfaces[] = Schema::resolve_type($interface);
            // @phpstan-ignore argument.templateType
        }
    }
    /** @throws InvariantViolation */
    protected function assert_valid_interfaces(): void
    {
        if (!isset($this->config['interfaces'])) {
            return;
        }
        $interfaces = $this->config['interfaces'];
        if (is_callable($interfaces)) {
            $interfaces = $interfaces();
        }
        // @phpstan-ignore-next-line should not happen if used correctly
        if (!is_iterable($interfaces)) {
            throw new Invariant_Violation("{$this->name} interfaces must be an iterable or a callable which returns an iterable.");
        }
    }
}
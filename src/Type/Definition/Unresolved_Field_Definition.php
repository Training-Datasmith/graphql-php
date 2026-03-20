<?php

declare (strict_types=1);
namespace Graph_Ql\Type\Definition;

/**
 * @phpstan-import-type UnnamedFieldDefinitionConfig from FieldDefinition
 *
 * @phpstan-type DefinitionResolver callable(): (FieldDefinition|(Type&OutputType)|UnnamedFieldDefinitionConfig)
 */
class Unresolved_Field_Definition
{
    private string $name;
    /**
     * @var callable
     *
     * @phpstan-var DefinitionResolver
     */
    private $definition_resolver;
    /** @param DefinitionResolver $definitionResolver */
    public function __construct(string $name, callable $definition_resolver)
    {
        $this->name = $name;
        $this->definition_resolver = $definition_resolver;
    }
    public function get_name(): string
    {
        return $this->name;
    }
    public function resolve(): Field_Definition
    {
        $field = ($this->definition_resolver)();
        if ($field instanceof Field_Definition) {
            return $field;
        }
        if ($field instanceof Type) {
            return new Field_Definition(['name' => $this->name, 'type' => $field]);
        }
        return new Field_Definition($field + ['name' => $this->name]);
    }
}
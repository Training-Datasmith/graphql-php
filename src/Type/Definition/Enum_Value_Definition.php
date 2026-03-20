<?php

declare (strict_types=1);
namespace Graph_Ql\Type\Definition;

use Graph_Ql\Language\AST\Enum_Value_Definition_Node;
/**
 * @phpstan-type EnumValueConfig array{
 *   name: string,
 *   value?: mixed,
 *   deprecationReason?: string|null,
 *   description?: string|null,
 *   astNode?: EnumValueDefinitionNode|null
 * }
 */
class Enum_Value_Definition
{
    public string $name;
    /** @var mixed */
    public $value;
    public ?string $deprecation_reason;
    public ?string $description;
    public ?Enum_Value_Definition_Node $ast_node;
    /** @phpstan-var EnumValueConfig */
    public array $config;
    /** @phpstan-param EnumValueConfig $config */
    public function __construct(array $config)
    {
        $this->name = $config['name'];
        $this->value = $config['value'] ?? null;
        $this->deprecation_reason = $config['deprecationReason'] ?? null;
        $this->description = $config['description'] ?? null;
        $this->ast_node = $config['astNode'] ?? null;
        $this->config = $config;
    }
    public function is_deprecated(): bool
    {
        return (bool) $this->deprecation_reason;
    }
}
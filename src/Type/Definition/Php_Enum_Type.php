<?php

declare (strict_types=1);
namespace Graph_Ql\Type\Definition;

use Graph_Ql\Error\Serialization_Error;
use Graph_Ql\Language\AST\Enum_Type_Definition_Node;
use Graph_Ql\Language\AST\Enum_Type_Extension_Node;
use Graph_Ql\Utils\Php_Doc;
use Graph_Ql\Utils\Utils;
/**
 * @phpstan-import-type PartialEnumValueConfig from EnumType
 */
class Php_Enum_Type extends Enum_Type
{
    public const MULTIPLE_DESCRIPTIONS_DISALLOWED = 'Using more than 1 Description attribute is not supported.';
    public const MULTIPLE_DEPRECATIONS_DISALLOWED = 'Using more than 1 Deprecated attribute is not supported.';
    /** @var class-string<\UnitEnum> */
    protected string $enum_class;
    /**
     * @param class-string<\UnitEnum> $enumClass The fully qualified class name of a native PHP enum
     * @param string|null $name The name the enum will have in the schema, defaults to the basename of the given class
     * @param string|null $description The description the enum will have in the schema, defaults to PHPDoc of the given class
     * @param array<EnumTypeExtensionNode>|null $extensionASTNodes
     *
     * @throws \Exception
     * @throws \ReflectionException
     */
    public function __construct(string $enum_class, ?string $name = null, ?string $description = null, ?Enum_Type_Definition_Node $ast_node = null, ?array $extension_ast_nodes = null)
    {
        $this->enum_class = $enum_class;
        $reflection = new \Reflection_Enum($enum_class);
        /**
         * @var array<string, PartialEnumValueConfig> $enumDefinitions
         */
        $enum_definitions = [];
        foreach ($reflection->get_cases() as $case) {
            $enum_definitions[$case->name] = ['value' => $case->get_value(), 'description' => $this->extract_description($case), 'deprecationReason' => $this->deprecation_reason($case)];
        }
        parent::__construct(['name' => $name ?? $this->base_name($enum_class), 'values' => $enum_definitions, 'description' => $description ?? $this->extract_description($reflection), 'astNode' => $ast_node, 'extensionASTNodes' => $extension_ast_nodes]);
    }
    public function serialize($value): string
    {
        if ($value instanceof $this->enum_class) {
            return $value->name;
        }
        if (is_a($this->enum_class, \Backed_Enum::class, true)) {
            try {
                $instance = $this->enum_class::from($value);
            } catch (\Value_Error|\TypeError $error) {
                $not_enum_instance_or_value = Utils::print_safe($value);
                throw new Serialization_Error("Cannot serialize value as enum: {$not_enum_instance_or_value}, expected instance or valid value of {$this->enum_class}.", $error->get_code(), $error);
            }
            return $instance->name;
        }
        $not_enum = Utils::print_safe($value);
        throw new Serialization_Error("Cannot serialize value as enum: {$not_enum}, expected instance of {$this->enum_class}.");
    }
    public function parse_value($value)
    {
        // Can happen when variable values undergo a serialization cycle before execution
        if ($value instanceof $this->enum_class) {
            return $value;
        }
        return parent::parse_value($value);
    }
    /** @param class-string $class */
    protected function base_name(string $class): string
    {
        $parts = explode('\\', $class);
        return end($parts);
    }
    /**
     * @param \ReflectionClassConstant|\ReflectionClass<\UnitEnum> $reflection
     *
     * @throws \Exception
     */
    protected function extract_description(\Reflection_Class_Constant|\ReflectionClass $reflection): ?string
    {
        $attributes = $reflection->get_attributes(Description::class);
        if (count($attributes) === 1) {
            return $attributes[0]->new_instance()->description;
        }
        if (count($attributes) > 1) {
            throw new \Exception(self::MULTIPLE_DESCRIPTIONS_DISALLOWED);
        }
        $comment = $reflection->get_doc_comment();
        $unpadded = Php_Doc::unpad($comment);
        return Php_Doc::unwrap($unpadded);
    }
    /** @throws \Exception */
    protected function deprecation_reason(\Reflection_Class_Constant $reflection): ?string
    {
        $attributes = $reflection->get_attributes(Deprecated::class);
        if (count($attributes) === 1) {
            return $attributes[0]->new_instance()->reason;
        }
        if (count($attributes) > 1) {
            throw new \Exception(self::MULTIPLE_DEPRECATIONS_DISALLOWED);
        }
        return null;
    }
}
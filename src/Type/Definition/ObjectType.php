<?php

declare (strict_types=1);
namespace Graph_Ql\Type\Definition;

use Graph_Ql\Deferred;
use Graph_Ql\Error\Error;
use Graph_Ql\Error\Invariant_Violation;
use Graph_Ql\Executor\Executor;
use Graph_Ql\Language\AST\Object_Type_Definition_Node;
use Graph_Ql\Language\AST\Object_Type_Extension_Node;
use Graph_Ql\Utils\Utils;
/**
 * Object Type Definition.
 *
 * Most GraphQL types you define will be object types.
 * Object types have a name, but most importantly describe their fields.
 *
 * Example:
 *
 *     $AddressType = new ObjectType([
 *         'name' => 'Address',
 *         'fields' => [
 *             'street' => GraphQL\Type\Definition\Type::string(),
 *             'number' => GraphQL\Type\Definition\Type::int(),
 *             'formatted' => [
 *                 'type' => GraphQL\Type\Definition\Type::string(),
 *                 'resolve' => fn (AddressModel $address): string => "{$address->number} {$address->street}",
 *             ],
 *         ],
 *     ]);
 *
 * When two types need to refer to each other, or a type needs to refer to
 * itself in a field, you can use a function expression (aka a closure or a
 * thunk) to supply the fields lazily.
 *
 * Example:
 *
 *     $PersonType = null;
 *     $PersonType = new ObjectType([
 *         'name' => 'Person',
 *         'fields' => fn (): array => [
 *             'name' => GraphQL\Type\Definition\Type::string(),
 *             'bestFriend' => $PersonType,
 *         ],
 *     ]);
 *
 * @phpstan-import-type FieldResolver from Executor
 * @phpstan-import-type ArgsMapper from Executor
 *
 * @phpstan-type InterfaceTypeReference InterfaceType|callable(): InterfaceType
 * @phpstan-type ObjectConfig array{
 *   name?: string|null,
 *   description?: string|null,
 *   resolveField?: FieldResolver|null,
 *   argsMapper?: ArgsMapper|null,
 *   fields: (callable(): iterable<mixed>)|iterable<mixed>,
 *   interfaces?: iterable<InterfaceTypeReference>|callable(): iterable<InterfaceTypeReference>,
 *   isTypeOf?: (callable(mixed $objectValue, mixed $context, ResolveInfo $resolveInfo): (bool|Deferred|null))|null,
 *   astNode?: ObjectTypeDefinitionNode|null,
 *   extensionASTNodes?: array<ObjectTypeExtensionNode>|null
 * }
 */
class Object_Type extends Type implements Output_Type, Composite_Type, Nullable_Type, Has_Fields_Type, Named_Type, Implementing_Type
{
    use Has_Fields_Type_Implementation;
    use Named_Type_Implementation;
    use Implementing_Type_Implementation;
    public ?Object_Type_Definition_Node $ast_node;
    /** @var array<ObjectTypeExtensionNode> */
    public array $extension_ast_nodes;
    /**
     * @var callable|null
     *
     * @phpstan-var FieldResolver|null
     */
    public $resolve_field_fn;
    /**
     * @var callable|null
     *
     * @phpstan-var ArgsMapper|null
     */
    public $args_mapper;
    /** @phpstan-var ObjectConfig */
    public array $config;
    /**
     * @phpstan-param ObjectConfig $config
     *
     * @throws InvariantViolation
     */
    public function __construct(array $config)
    {
        $this->name = $config['name'] ?? $this->infer_name();
        $this->description = $config['description'] ?? null;
        $this->resolve_field_fn = $config['resolveField'] ?? null;
        $this->args_mapper = $config['argsMapper'] ?? null;
        $this->ast_node = $config['astNode'] ?? null;
        $this->extension_ast_nodes = $config['extensionASTNodes'] ?? [];
        $this->config = $config;
    }
    /**
     * @param mixed $type
     *
     * @throws InvariantViolation
     */
    public static function assert_object_type($type): self
    {
        if (!$type instanceof self) {
            $not_object_type = Utils::print_safe($type);
            throw new Invariant_Violation("Expected {$not_object_type} to be a GraphQL Object type.");
        }
        return $type;
    }
    /**
     * @param mixed $objectValue The resolved value for the object type
     * @param mixed $context The context that was passed to GraphQL::execute()
     *
     * @return bool|Deferred|null
     */
    public function is_type_of($object_value, $context, Resolve_Info $info)
    {
        return isset($this->config['isTypeOf']) ? $this->config['isTypeOf']($object_value, $context, $info) : null;
    }
    /**
     * Validates type config and throws if one of the type options is invalid.
     * Note: this method is shallow, it won't validate object fields and their arguments.
     *
     * @throws Error
     * @throws InvariantViolation
     */
    public function assert_valid(): void
    {
        Utils::assert_valid_name($this->name);
        $is_type_of = $this->config['isTypeOf'] ?? null;
        // @phpstan-ignore-next-line unnecessary according to types, but can happen during runtime
        if (isset($is_type_of) && !is_callable($is_type_of)) {
            $not_callable = Utils::print_safe($is_type_of);
            throw new Invariant_Violation("{$this->name} must provide \"isTypeOf\" as null or a callable, but got: {$not_callable}.");
        }
        foreach ($this->get_fields() as $field) {
            $field->assert_valid($this);
        }
        $this->assert_valid_interfaces();
    }
    public function ast_node(): ?Object_Type_Definition_Node
    {
        return $this->ast_node;
    }
    /** @return array<ObjectTypeExtensionNode> */
    public function extension_ast_nodes(): array
    {
        return $this->extension_ast_nodes;
    }
}
<?php

declare (strict_types=1);
namespace Graph_Ql\Type;

use Graph_Ql\Error\Error;
use Graph_Ql\Error\Invariant_Violation;
use Graph_Ql\Graph_Ql;
use Graph_Ql\Language\AST\Operation_Definition_Node;
use Graph_Ql\Language\AST\Schema_Definition_Node;
use Graph_Ql\Language\AST\Schema_Extension_Node;
use Graph_Ql\Type\Definition\Abstract_Type;
use Graph_Ql\Type\Definition\Directive;
use Graph_Ql\Type\Definition\Implementing_Type;
use Graph_Ql\Type\Definition\Interface_Type;
use Graph_Ql\Type\Definition\Named_Type;
use Graph_Ql\Type\Definition\Object_Type;
use Graph_Ql\Type\Definition\Scalar_Type;
use Graph_Ql\Type\Definition\Type;
use Graph_Ql\Type\Definition\Union_Type;
use Graph_Ql\Utils\Interface_Implementations;
use Graph_Ql\Utils\Type_Info;
use Graph_Ql\Utils\Utils;
/**
 * Schema Definition (see [schema definition docs](schema-definition.md)).
 *
 * A Schema is created by supplying the root types of each type of operation:
 * query, mutation (optional) and subscription (optional). A schema definition is
 * then supplied to the validator and executor. Usage Example:
 *
 *     $schema = new GraphQL\Type\Schema([
 *       'query' => $MyAppQueryRootType,
 *       'mutation' => $MyAppMutationRootType,
 *     ]);
 *
 * Or using Schema Config instance:
 *
 *     $config = GraphQL\Type\SchemaConfig::create()
 *         ->setQuery($MyAppQueryRootType)
 *         ->setMutation($MyAppMutationRootType);
 *
 *     $schema = new GraphQL\Type\Schema($config);
 *
 * @phpstan-import-type SchemaConfigOptions from SchemaConfig
 * @phpstan-import-type OperationType from OperationDefinitionNode
 *
 * @see \GraphQL\Tests\Type\SchemaTest
 */
class Schema
{
    private Schema_Config $config;
    /**
     * Contains currently resolved schema types.
     *
     * @var array<string, Type&NamedType>
     */
    private array $resolved_types = [];
    /**
     * Lazily initialised.
     *
     * @var array<string, InterfaceImplementations>
     */
    private array $implementations_map;
    /** True when $resolvedTypes contains all possible schema types. */
    private bool $fully_loaded = false;
    /** @var array<int, Error> */
    private array $validation_errors;
    public ?string $description;
    public ?Schema_Definition_Node $ast_node;
    /** @var array<SchemaExtensionNode> */
    public array $extension_ast_nodes = [];
    /**
     * @param SchemaConfig|array<string, mixed> $config
     *
     * @phpstan-param SchemaConfig|SchemaConfigOptions $config
     *
     * @throws InvariantViolation
     *
     * @api
     */
    public function __construct($config)
    {
        if (is_array($config)) {
            $config = Schema_Config::create($config);
        }
        // If this schema was built from a source known to be valid, then it may be
        // marked with assumeValid to avoid an additional type system validation.
        if ($config->get_assume_valid()) {
            $this->validation_errors = [];
        }
        $this->description = $config->description;
        $this->ast_node = $config->ast_node;
        $this->extension_ast_nodes = $config->extension_ast_nodes;
        $this->config = $config;
    }
    /**
     * Returns all types in this schema.
     *
     * This operation requires a full schema scan. Do not use in production environment.
     *
     * @throws InvariantViolation
     *
     * @return array<string, Type&NamedType> Keys represent type names, values are instances of corresponding type definitions
     *
     * @api
     */
    public function get_type_map(): array
    {
        if (!$this->fully_loaded) {
            $types = $this->config->types;
            if (is_callable($types)) {
                $types = $types();
            }
            // Reset order of user provided types, since calls to getType() may have loaded them
            $this->resolved_types = [];
            // Separate built-in scalar overrides to avoid identity conflicts
            // with Type::string() etc. references in field definitions during extractTypes.
            /** @var array<string, ScalarType> $scalarOverrides */
            $scalar_overrides = [];
            $built_in_scalars = Type::built_in_scalars();
            foreach ($types as $type_or_lazy_type) {
                /** @var Type|callable(): Type $typeOrLazyType */
                $type = self::resolve_type($type_or_lazy_type);
                assert($type instanceof Named_Type);
                /** @var string $typeName Necessary assertion for PHPStan + PHP 8.2 */
                $type_name = $type->name;
                if ($type instanceof Scalar_Type && isset($built_in_scalars[$type_name]) && $type !== $built_in_scalars[$type_name]) {
                    $scalar_overrides[$type_name] = $type;
                    continue;
                }
                assert(!isset($this->resolved_types[$type_name]) || $type === $this->resolved_types[$type_name], "Schema must contain unique named types but contains multiple types named \"{$type}\" (see https://webonyx.github.io/graphql-php/type-definitions/#type-registry).");
                $this->resolved_types[$type_name] = $type;
            }
            // To preserve order of user-provided types, we add first to add them to
            // the set of "collected" types, so `collectReferencedTypes` ignore them.
            /** @var array<string, Type&NamedType> $allReferencedTypes */
            $all_referenced_types = [];
            foreach ($this->resolved_types as $type) {
                // When we ready to process this type, we remove it from "collected" types
                // and then add it together with all dependent types in the correct position.
                unset($all_referenced_types[$type->name]);
                Type_Info::extract_types($type, $all_referenced_types);
            }
            foreach ([$this->get_query_type(), $this->get_mutation_type(), $this->get_subscription_type()] as $root_type) {
                if ($root_type instanceof Object_Type) {
                    Type_Info::extract_types($root_type, $all_referenced_types);
                }
            }
            foreach ($this->get_directives() as $directive) {
                // @phpstan-ignore-next-line generics are not strictly enforceable, error will be caught during schema validation
                if ($directive instanceof Directive) {
                    Type_Info::extract_types_from_directives($directive, $all_referenced_types);
                }
            }
            Type_Info::extract_types(Introspection::_schema(), $all_referenced_types);
            // Apply scalar overrides after all extractions, replacing the
            // global singletons with user-provided instances.
            foreach ($scalar_overrides as $name => $override) {
                $all_referenced_types[$name] = $override;
            }
            if (isset($this->config->type_loader)) {
                foreach (Type::BUILT_IN_SCALAR_NAMES as $scalar_name) {
                    if (isset($scalar_overrides[$scalar_name])) {
                        continue;
                    }
                    $type = ($this->config->type_loader)($scalar_name);
                    if ($type instanceof Scalar_Type && $type->name === $scalar_name && $type !== $built_in_scalars[$scalar_name]) {
                        $all_referenced_types[$scalar_name] = $type;
                    }
                }
            }
            $this->resolved_types = $all_referenced_types;
            $this->fully_loaded = true;
        }
        return $this->resolved_types;
    }
    /**
     * Returns a list of directives supported by this schema.
     *
     * @throws InvariantViolation
     *
     * @return array<Directive>
     *
     * @api
     */
    public function get_directives(): array
    {
        return $this->config->directives ?? Graph_Ql::get_standard_directives();
    }
    /** @param mixed $typeLoaderReturn could be anything */
    public static function type_loader_not_type($type_loader_return): string
    {
        $type_class = Type::class;
        $not_type = Utils::print_safe($type_loader_return);
        return "Type loader is expected to return an instanceof {$type_class}, but it returned {$not_type}";
    }
    public static function type_loader_wrong_type_name(string $expected_type_name, string $actual_type_name): string
    {
        return "Type loader is expected to return type {$expected_type_name}, but it returned type {$actual_type_name}.";
    }
    /** Returns root type by operation name. */
    public function get_operation_type(string $operation): ?Object_Type
    {
        switch ($operation) {
            case 'query':
                return $this->get_query_type();
            case 'mutation':
                return $this->get_mutation_type();
            case 'subscription':
                return $this->get_subscription_type();
            default:
                return null;
        }
    }
    /**
     * Returns root query type.
     *
     * @api
     */
    public function get_query_type(): ?Object_Type
    {
        $query = $this->config->query;
        if ($query === null) {
            return null;
        }
        if (is_callable($query)) {
            return $this->config->query = $query();
        }
        return $query;
    }
    /**
     * Returns root mutation type.
     *
     * @api
     */
    public function get_mutation_type(): ?Object_Type
    {
        $mutation = $this->config->mutation;
        if ($mutation === null) {
            return null;
        }
        if (is_callable($mutation)) {
            return $this->config->mutation = $mutation();
        }
        return $mutation;
    }
    /**
     * Returns schema subscription.
     *
     * @api
     */
    public function get_subscription_type(): ?Object_Type
    {
        $subscription = $this->config->subscription;
        if ($subscription === null) {
            return null;
        }
        if (is_callable($subscription)) {
            return $this->config->subscription = $subscription();
        }
        return $subscription;
    }
    /** @api */
    public function get_config(): Schema_Config
    {
        return $this->config;
    }
    /**
     * Returns a type by name.
     *
     * @throws InvariantViolation
     *
     * @return (Type&NamedType)|null
     *
     * @api
     */
    public function get_type(string $name): ?Type
    {
        if (isset($this->resolved_types[$name])) {
            return $this->resolved_types[$name];
        }
        $introspection_types = Introspection::get_types();
        if (isset($introspection_types[$name])) {
            return $introspection_types[$name];
        }
        $type = $this->load_type($name);
        if ($type !== null) {
            return $this->resolved_types[$name] = self::resolve_type($type);
        }
        $built_in_scalars = Type::built_in_scalars();
        if (isset($built_in_scalars[$name])) {
            return $this->resolved_types[$name] = $built_in_scalars[$name];
        }
        return null;
    }
    /** @throws InvariantViolation */
    public function has_type(string $name): bool
    {
        return $this->get_type($name) !== null;
    }
    /**
     * @throws InvariantViolation
     *
     * @return (Type&NamedType)|null
     */
    private function load_type(string $type_name): ?Type
    {
        if (!isset($this->config->type_loader)) {
            return $this->get_type_map()[$type_name] ?? null;
        }
        $type = ($this->config->type_loader)($type_name);
        if ($type === null) {
            return null;
        }
        // @phpstan-ignore-next-line not strictly enforceable unless PHP gets function types
        if (!$type instanceof Type) {
            throw new Invariant_Violation(self::type_loader_not_type($type));
        }
        if ($type_name !== $type->name) {
            throw new Invariant_Violation(self::type_loader_wrong_type_name($type_name, $type->name));
        }
        return $type;
    }
    /**
     * @template T of Type
     *
     * @param Type|callable $type
     *
     * @phpstan-param T|callable():T $type
     *
     * @phpstan-return T
     */
    public static function resolve_type($type): Type
    {
        if ($type instanceof Type) {
            return $type;
        }
        return $type();
    }
    /**
     * Returns all possible concrete types for given abstract type
     * (implementations for interfaces and members of union type for unions).
     *
     * This operation requires full schema scan. Do not use in production environment.
     *
     * @param AbstractType&Type $abstractType
     *
     * @throws InvariantViolation
     *
     * @return array<ObjectType>
     *
     * @api
     */
    public function get_possible_types(Abstract_Type $abstract_type): array
    {
        if ($abstract_type instanceof Union_Type) {
            return $abstract_type->get_types();
        }
        assert($abstract_type instanceof Interface_Type, 'only other option');
        return $this->get_implementations($abstract_type)->objects();
    }
    /**
     * Returns all types that implement a given interface type.
     *
     * This operation requires full schema scan. Do not use in production environment.
     *
     * @api
     *
     * @throws InvariantViolation
     */
    public function get_implementations(Interface_Type $abstract_type): Interface_Implementations
    {
        return $this->collect_implementations()[$abstract_type->name];
    }
    /**
     * @throws InvariantViolation
     *
     * @return array<string, InterfaceImplementations>
     */
    private function collect_implementations(): array
    {
        if (!isset($this->implementations_map)) {
            $this->implementations_map = [];
            /**
             * @var array<
             *     string,
             *     array{
             *         objects: array<int, ObjectType>,
             *         interfaces: array<int, InterfaceType>,
             *     }
             * > $foundImplementations
             */
            $found_implementations = [];
            foreach ($this->get_type_map() as $type) {
                if ($type instanceof Interface_Type) {
                    if (!isset($found_implementations[$type->name])) {
                        $found_implementations[$type->name] = ['objects' => [], 'interfaces' => []];
                    }
                    foreach ($type->get_interfaces() as $iface) {
                        if (!isset($found_implementations[$iface->name])) {
                            $found_implementations[$iface->name] = ['objects' => [], 'interfaces' => []];
                        }
                        $found_implementations[$iface->name]['interfaces'][] = $type;
                    }
                } elseif ($type instanceof Object_Type) {
                    foreach ($type->get_interfaces() as $iface) {
                        if (!isset($found_implementations[$iface->name])) {
                            $found_implementations[$iface->name] = ['objects' => [], 'interfaces' => []];
                        }
                        $found_implementations[$iface->name]['objects'][] = $type;
                    }
                }
            }
            foreach ($found_implementations as $name => $implementations) {
                $this->implementations_map[$name] = new Interface_Implementations($implementations['objects'], $implementations['interfaces']);
            }
        }
        return $this->implementations_map;
    }
    /**
     * Returns true if the given type is a sub type of the given abstract type.
     *
     * @param AbstractType&Type $abstractType
     * @param ImplementingType&Type $maybeSubType
     *
     * @api
     *
     * @throws InvariantViolation
     */
    public function is_sub_type(Abstract_Type $abstract_type, Implementing_Type $maybe_sub_type): bool
    {
        if ($abstract_type instanceof Interface_Type) {
            return $maybe_sub_type->implements_interface($abstract_type);
        }
        assert($abstract_type instanceof Union_Type, 'only other option');
        return $abstract_type->is_possible_type($maybe_sub_type);
    }
    /**
     * Returns instance of directive by name.
     *
     * @api
     *
     * @throws InvariantViolation
     */
    public function get_directive(string $name): ?Directive
    {
        foreach ($this->get_directives() as $directive) {
            if ($directive->name === $name) {
                return $directive;
            }
        }
        return null;
    }
    /**
     * Throws if the schema is not valid.
     *
     * This operation requires a full schema scan. Do not use in production environment.
     *
     * @throws Error
     * @throws InvariantViolation
     *
     * @api
     */
    public function assert_valid(): void
    {
        $errors = $this->validate();
        if ($errors !== []) {
            throw new Invariant_Violation(implode("\n\n", $this->validation_errors));
        }
        $internal_types = Type::built_in_scalars() + Introspection::get_types();
        foreach ($this->get_type_map() as $name => $type) {
            if (isset($internal_types[$name])) {
                continue;
            }
            $type->assert_valid();
            // Make sure type loader returns the same instance as registered in other places of schema
            if (isset($this->config->type_loader) && $this->load_type($name) !== $type) {
                throw new Invariant_Violation("Type loader returns different instance for {$name} than field/argument definitions. Make sure you always return the same instance for the same type name.");
            }
        }
    }
    /**
     * Validate the schema and return any errors.
     *
     * This operation requires a full schema scan. Do not use in production environment.
     *
     * @throws InvariantViolation
     *
     * @return array<int, Error>
     *
     * @api
     */
    public function validate(): array
    {
        // If this Schema has already been validated, return the previous results.
        if (isset($this->validation_errors)) {
            return $this->validation_errors;
        }
        // Validate the schema, producing a list of errors.
        $context = new Schema_Validation_Context($this);
        $context->validate_root_types();
        $context->validate_directives();
        $context->validate_types();
        // Persist the results of validation before returning to ensure validation
        // does not run multiple times for this schema.
        $this->validation_errors = $context->get_errors();
        return $this->validation_errors;
    }
}
<?php

declare (strict_types=1);
namespace Graph_Ql\Type;

use Graph_Ql\Error\Invariant_Violation;
use Graph_Ql\Language\AST\Schema_Definition_Node;
use Graph_Ql\Language\AST\Schema_Extension_Node;
use Graph_Ql\Type\Definition\Directive;
use Graph_Ql\Type\Definition\Named_Type;
use Graph_Ql\Type\Definition\Object_Type;
use Graph_Ql\Type\Definition\Type;
/**
 * Configuration options for schema construction.
 *
 * The options accepted by the **create** method are described
 * in the [schema definition docs](schema-definition.md#configuration-options).
 *
 * Usage example:
 *
 *     $config = SchemaConfig::create()
 *         ->setQuery($myQueryType)
 *         ->setTypeLoader($myTypeLoader);
 *
 *     $schema = new Schema($config);
 *
 * @see Type, NamedType
 *
 * @phpstan-type MaybeLazyObjectType ObjectType|(callable(): (ObjectType|null))|null
 * @phpstan-type TypeLoader callable(string $typeName): ((Type&NamedType)|null)
 * @phpstan-type Types iterable<Type&NamedType>|(callable(): iterable<Type&NamedType>)|iterable<(callable(): Type&NamedType)>|(callable(): iterable<(callable(): Type&NamedType)>)
 * @phpstan-type SchemaConfigOptions array{
 *   description?: string|null,
 *   query?: MaybeLazyObjectType,
 *   mutation?: MaybeLazyObjectType,
 *   subscription?: MaybeLazyObjectType,
 *   types?: Types|null,
 *   directives?: array<Directive>|null,
 *   typeLoader?: TypeLoader|null,
 *   assumeValid?: bool|null,
 *   astNode?: SchemaDefinitionNode|null,
 *   extensionASTNodes?: array<SchemaExtensionNode>|null,
 * }
 */
class Schema_Config
{
    public ?string $description = null;
    /** @var MaybeLazyObjectType */
    public $query;
    /** @var MaybeLazyObjectType */
    public $mutation;
    /** @var MaybeLazyObjectType */
    public $subscription;
    /**
     * @var iterable|callable
     *
     * @phpstan-var Types
     */
    public $types = [];
    /** @var array<Directive>|null */
    public ?array $directives = null;
    /**
     * @var callable|null
     *
     * @phpstan-var TypeLoader|null
     */
    public $type_loader;
    public bool $assume_valid = false;
    public ?Schema_Definition_Node $ast_node = null;
    /** @var array<SchemaExtensionNode> */
    public array $extension_ast_nodes = [];
    /**
     * Converts an array of options to instance of SchemaConfig
     * (or just returns empty config when array is not passed).
     *
     * @phpstan-param SchemaConfigOptions $options
     *
     * @throws InvariantViolation
     *
     * @api
     */
    public static function create(array $options = []): self
    {
        $config = new static();
        if ($options !== []) {
            if (isset($options['description'])) {
                $config->set_description($options['description']);
            }
            if (isset($options['query'])) {
                $config->set_query($options['query']);
            }
            if (isset($options['mutation'])) {
                $config->set_mutation($options['mutation']);
            }
            if (isset($options['subscription'])) {
                $config->set_subscription($options['subscription']);
            }
            if (isset($options['types'])) {
                $config->set_types($options['types']);
            }
            if (isset($options['directives'])) {
                $config->set_directives($options['directives']);
            }
            if (isset($options['typeLoader'])) {
                $config->set_type_loader($options['typeLoader']);
            }
            if (isset($options['assumeValid'])) {
                $config->set_assume_valid($options['assumeValid']);
            }
            if (isset($options['astNode'])) {
                $config->set_ast_node($options['astNode']);
            }
            if (isset($options['extensionASTNodes'])) {
                $config->set_extension_ast_nodes($options['extensionASTNodes']);
            }
        }
        return $config;
    }
    /** @api */
    public function get_description(): ?string
    {
        return $this->description;
    }
    /** @api */
    public function set_description(?string $description): self
    {
        $this->description = $description;
        return $this;
    }
    /**
     * @return MaybeLazyObjectType
     *
     * @api
     */
    public function get_query()
    {
        return $this->query;
    }
    /**
     * @param MaybeLazyObjectType $query
     *
     * @throws InvariantViolation
     *
     * @api
     */
    public function set_query($query): self
    {
        $this->assert_maybe_lazy_object_type($query);
        $this->query = $query;
        return $this;
    }
    /**
     * @return MaybeLazyObjectType
     *
     * @api
     */
    public function get_mutation()
    {
        return $this->mutation;
    }
    /**
     * @param MaybeLazyObjectType $mutation
     *
     * @throws InvariantViolation
     *
     * @api
     */
    public function set_mutation($mutation): self
    {
        $this->assert_maybe_lazy_object_type($mutation);
        $this->mutation = $mutation;
        return $this;
    }
    /**
     * @return MaybeLazyObjectType
     *
     * @api
     */
    public function get_subscription()
    {
        return $this->subscription;
    }
    /**
     * @param MaybeLazyObjectType $subscription
     *
     * @throws InvariantViolation
     *
     * @api
     */
    public function set_subscription($subscription): self
    {
        $this->assert_maybe_lazy_object_type($subscription);
        $this->subscription = $subscription;
        return $this;
    }
    /**
     * @return array|callable
     *
     * @phpstan-return Types
     *
     * @api
     */
    public function get_types()
    {
        return $this->types;
    }
    /**
     * @param array|callable $types
     *
     * @phpstan-param Types $types
     *
     * @api
     */
    public function set_types($types): self
    {
        $this->types = $types;
        return $this;
    }
    /**
     * @return array<Directive>|null
     *
     * @api
     */
    public function get_directives(): ?array
    {
        return $this->directives;
    }
    /**
     * @param array<Directive>|null $directives
     *
     * @api
     */
    public function set_directives(?array $directives): self
    {
        $this->directives = $directives;
        return $this;
    }
    /**
     * @return callable|null $typeLoader
     *
     * @phpstan-return TypeLoader|null $typeLoader
     *
     * @api
     */
    public function get_type_loader(): ?callable
    {
        return $this->type_loader;
    }
    /**
     * @phpstan-param TypeLoader|null $typeLoader
     *
     * @api
     */
    public function set_type_loader(?callable $type_loader): self
    {
        $this->type_loader = $type_loader;
        return $this;
    }
    public function get_assume_valid(): bool
    {
        return $this->assume_valid;
    }
    public function set_assume_valid(bool $assume_valid): self
    {
        $this->assume_valid = $assume_valid;
        return $this;
    }
    public function get_ast_node(): ?Schema_Definition_Node
    {
        return $this->ast_node;
    }
    public function set_ast_node(?Schema_Definition_Node $ast_node): self
    {
        $this->ast_node = $ast_node;
        return $this;
    }
    /** @return array<SchemaExtensionNode> */
    public function get_extension_ast_nodes(): array
    {
        return $this->extension_ast_nodes;
    }
    /** @param array<SchemaExtensionNode> $extensionASTNodes */
    public function set_extension_ast_nodes(array $extension_ast_nodes): self
    {
        $this->extension_ast_nodes = $extension_ast_nodes;
        return $this;
    }
    /**
     * @param mixed $maybeLazyObjectType Should be MaybeLazyObjectType
     *
     * @throws InvariantViolation
     */
    protected function assert_maybe_lazy_object_type($maybe_lazy_object_type): void
    {
        if ($maybe_lazy_object_type instanceof Object_Type || is_callable($maybe_lazy_object_type) || is_null($maybe_lazy_object_type)) {
            return;
        }
        $not_maybe_lazy_object_type = is_object($maybe_lazy_object_type) ? get_class($maybe_lazy_object_type) : gettype($maybe_lazy_object_type);
        $object_type_class = Object_Type::class;
        throw new Invariant_Violation("Expected instanceof {$object_type_class}, a callable that returns such an instance, or null, got: {$not_maybe_lazy_object_type}.");
    }
}
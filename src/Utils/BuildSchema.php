<?php

declare (strict_types=1);
namespace Graph_Ql\Utils;

use Graph_Ql\Error\Error;
use Graph_Ql\Error\Invariant_Violation;
use Graph_Ql\Error\Syntax_Error;
use Graph_Ql\Language\AST\Directive_Definition_Node;
use Graph_Ql\Language\AST\Document_Node;
use Graph_Ql\Language\AST\Node;
use Graph_Ql\Language\AST\Schema_Definition_Node;
use Graph_Ql\Language\AST\Type_Definition_Node;
use Graph_Ql\Language\AST\Type_Extension_Node;
use Graph_Ql\Language\Parser;
use Graph_Ql\Language\Source;
use Graph_Ql\Type\Definition\Directive;
use Graph_Ql\Type\Definition\Type;
use Graph_Ql\Type\Schema;
use Graph_Ql\Type\Schema_Config;
use Graph_Ql\Validator\Document_Validator;
/**
 * Build instance of @see \GraphQL\Type\Schema out of schema language definition (string or parsed AST).
 *
 * See [schema definition language docs](schema-definition-language.md) for details.
 *
 * @phpstan-import-type TypeConfigDecorator from ASTDefinitionBuilder
 * @phpstan-import-type FieldConfigDecorator from ASTDefinitionBuilder
 *
 * @phpstan-type BuildSchemaOptions array{
 *   assumeValid?: bool,
 *   assumeValidSDL?: bool
 * }
 *
 * - assumeValid:
 *   When building a schema from a GraphQL service's introspection result, it might be safe to assume the schema is valid.
 *   Set to true to assume the produced schema is valid.
 *   Default: false
 *
 * - assumeValidSDL:
 *   Set to true to assume the SDL is valid.
 *   Default: false
 *
 * @see \GraphQL\Tests\Utils\BuildSchemaTest
 */
class Build_Schema
{
    private Document_Node $ast;
    /**
     * @var callable|null
     *
     * @phpstan-var TypeConfigDecorator|null
     */
    private $type_config_decorator;
    /**
     * @var callable|null
     *
     * @phpstan-var FieldConfigDecorator|null
     */
    private $field_config_decorator;
    /**
     * @var array<string, bool>
     *
     * @phpstan-var BuildSchemaOptions
     */
    private array $options;
    /**
     * @param array<string, bool> $options
     *
     * @phpstan-param TypeConfigDecorator|null $typeConfigDecorator
     * @phpstan-param BuildSchemaOptions $options
     */
    public function __construct(Document_Node $ast, ?callable $type_config_decorator = null, array $options = [], ?callable $field_config_decorator = null)
    {
        $this->ast = $ast;
        $this->type_config_decorator = $type_config_decorator;
        $this->options = $options;
        $this->field_config_decorator = $field_config_decorator;
    }
    /**
     * A helper function to build a GraphQLSchema directly from a source
     * document.
     *
     * @param DocumentNode|Source|string $source
     * @param array<string, bool> $options
     *
     * @phpstan-param TypeConfigDecorator|null $typeConfigDecorator
     * @phpstan-param FieldConfigDecorator|null $fieldConfigDecorator
     * @phpstan-param BuildSchemaOptions $options
     *
     * @api
     *
     * @throws \Exception
     * @throws \ReflectionException
     * @throws Error
     * @throws InvariantViolation
     * @throws SyntaxError
     */
    public static function build($source, ?callable $type_config_decorator = null, array $options = [], ?callable $field_config_decorator = null): Schema
    {
        $doc = $source instanceof Document_Node ? $source : Parser::parse($source);
        return self::build_ast($doc, $type_config_decorator, $options, $field_config_decorator);
    }
    /**
     * This takes the AST of a schema from @see \GraphQL\Language\Parser::parse().
     *
     * If no schema definition is provided, then it will look for types named Query and Mutation.
     *
     * Given that AST it constructs a @see \GraphQL\Type\Schema. The resulting schema
     * has no resolve methods, so execution will use default resolvers.
     *
     * @param array<string, bool> $options
     *
     * @phpstan-param TypeConfigDecorator|null $typeConfigDecorator
     * @phpstan-param FieldConfigDecorator|null $fieldConfigDecorator
     * @phpstan-param BuildSchemaOptions $options
     *
     * @api
     *
     * @throws \Exception
     * @throws \ReflectionException
     * @throws Error
     * @throws InvariantViolation
     */
    public static function build_ast(Document_Node $ast, ?callable $type_config_decorator = null, array $options = [], ?callable $field_config_decorator = null): Schema
    {
        return (new self($ast, $type_config_decorator, $options, $field_config_decorator))->build_schema();
    }
    /**
     * @throws \Exception
     * @throws \ReflectionException
     * @throws Error
     * @throws InvariantViolation
     */
    public function build_schema(): Schema
    {
        if (!($this->options['assumeValid'] ?? false) && !($this->options['assumeValidSDL'] ?? false)) {
            Document_Validator::assert_valid_sdl($this->ast);
        }
        $schema_def = null;
        /** @var array<string, Node&TypeDefinitionNode> */
        $type_definitions_map = [];
        /** @var array<string, array<int, Node&TypeExtensionNode>> $typeExtensionsMap */
        $type_extensions_map = [];
        /** @var array<int, DirectiveDefinitionNode> $directiveDefs */
        $directive_defs = [];
        foreach ($this->ast->definitions as $definition) {
            switch (true) {
                case $definition instanceof Schema_Definition_Node:
                    $schema_def = $definition;
                    break;
                case $definition instanceof Type_Definition_Node:
                    $name = $definition->get_name()->value;
                    $type_definitions_map[$name] = $definition;
                    break;
                case $definition instanceof Type_Extension_Node:
                    $name = $definition->get_name()->value;
                    $type_extensions_map[$name][] = $definition;
                    break;
                case $definition instanceof Directive_Definition_Node:
                    $directive_defs[] = $definition;
                    break;
            }
        }
        $operation_types = $schema_def !== null ? $this->get_operation_types($schema_def) : ['query' => 'Query', 'mutation' => 'Mutation', 'subscription' => 'Subscription'];
        $definition_builder = new Ast_Definition_Builder(
            $type_definitions_map,
            $type_extensions_map,
            // @phpstan-ignore-next-line TODO add union type when available
            static function (string $type_name): Type {
                throw self::unknown_type($type_name);
            },
            $this->type_config_decorator,
            $this->field_config_decorator
        );
        $directives = array_map([$definition_builder, 'buildDirective'], $directive_defs);
        $directives_by_name = [];
        foreach ($directives as $directive) {
            $directives_by_name[$directive->name][] = $directive;
        }
        // If specified directives were not explicitly declared, add them.
        if (!isset($directives_by_name['include'])) {
            $directives[] = Directive::include_directive();
        }
        if (!isset($directives_by_name['skip'])) {
            $directives[] = Directive::skip_directive();
        }
        if (!isset($directives_by_name['deprecated'])) {
            $directives[] = Directive::deprecated_directive();
        }
        if (!isset($directives_by_name['oneOf'])) {
            $directives[] = Directive::one_of_directive();
        }
        // Note: While this could make early assertions to get the correctly
        // typed values below, that would throw immediately while type system
        // validation with validateSchema() will produce more actionable results.
        return new Schema((new Schema_Config())->set_description($schema_def->description->value ?? null)->set_query(isset($operation_types['query']) ? $definition_builder->maybe_build_type($operation_types['query']) : null)->set_mutation(isset($operation_types['mutation']) ? $definition_builder->maybe_build_type($operation_types['mutation']) : null)->set_subscription(isset($operation_types['subscription']) ? $definition_builder->maybe_build_type($operation_types['subscription']) : null)->set_type_loader(static fn(string $name): ?Type => $definition_builder->maybe_build_type($name))->set_directives($directives)->set_ast_node($schema_def)->set_types(fn(): array => array_map(static fn(Type_Definition_Node $def): Type => $definition_builder->build_type($def->get_name()->value), $type_definitions_map)));
    }
    /** @return array<string, string> */
    private function get_operation_types(Schema_Definition_Node $schema_def): array
    {
        /** @var array<string, string> $operationTypes */
        $operation_types = [];
        foreach ($schema_def->operation_types as $operation_type) {
            $operation_types[$operation_type->operation] = $operation_type->type->name->value;
        }
        return $operation_types;
    }
    public static function unknown_type(string $type_name): Error
    {
        return new Error("Unknown type: \"{$type_name}\".");
    }
}
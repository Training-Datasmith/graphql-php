<?php

declare (strict_types=1);
namespace Graph_Ql\Type\Definition;

use Graph_Ql\Language\AST\Directive_Definition_Node;
use Graph_Ql\Language\Directive_Location;
/**
 * @phpstan-import-type ArgumentListConfig from Argument
 *
 * @phpstan-type DirectiveConfig array{
 *   name: string,
 *   description?: string|null,
 *   args?: ArgumentListConfig|null,
 *   locations: array<string>,
 *   isRepeatable?: bool|null,
 *   astNode?: DirectiveDefinitionNode|null
 * }
 */
class Directive
{
    public const DEFAULT_DEPRECATION_REASON = 'No longer supported';
    public const INCLUDE_NAME = 'include';
    public const IF_ARGUMENT_NAME = 'if';
    public const SKIP_NAME = 'skip';
    public const DEPRECATED_NAME = 'deprecated';
    public const REASON_ARGUMENT_NAME = 'reason';
    public const ONE_OF_NAME = 'oneOf';
    /**
     * Lazily initialized.
     *
     * @var array<string, Directive>|null
     */
    protected static ?array $internal_directives = null;
    public string $name;
    public ?string $description;
    /** @var array<int, Argument> */
    public array $args;
    public bool $is_repeatable;
    /** @var array<string> */
    public array $locations;
    public ?Directive_Definition_Node $ast_node;
    /**
     * @var array<string, mixed>
     *
     * @phpstan-var DirectiveConfig
     */
    public array $config;
    /**
     * @param array<string, mixed> $config
     *
     * @phpstan-param DirectiveConfig $config
     */
    public function __construct(array $config)
    {
        $this->name = $config['name'];
        $this->description = $config['description'] ?? null;
        $this->args = isset($config['args']) ? Argument::list_from_config($config['args']) : [];
        $this->is_repeatable = $config['isRepeatable'] ?? false;
        $this->locations = $config['locations'];
        $this->ast_node = $config['astNode'] ?? null;
        $this->config = $config;
    }
    /** @return array<string, Directive> */
    public static function built_in_directives(): array
    {
        return [self::INCLUDE_NAME => self::include_directive(), self::SKIP_NAME => self::skip_directive(), self::DEPRECATED_NAME => self::deprecated_directive(), self::ONE_OF_NAME => self::one_of_directive()];
    }
    /**
     * @deprecated use {@see Directive::builtInDirectives()}
     *
     * @return array<string, Directive>
     */
    public static function get_internal_directives(): array
    {
        return self::built_in_directives();
    }
    public static function include_directive(): Directive
    {
        return self::$internal_directives[self::INCLUDE_NAME] ??= new self(['name' => self::INCLUDE_NAME, 'description' => 'Directs the executor to include this field or fragment only when the `if` argument is true.', 'locations' => [Directive_Location::FIELD, Directive_Location::FRAGMENT_SPREAD, Directive_Location::INLINE_FRAGMENT], 'args' => [self::IF_ARGUMENT_NAME => ['type' => Type::non_null(Type::boolean()), 'description' => 'Included when true.']]]);
    }
    public static function skip_directive(): Directive
    {
        return self::$internal_directives[self::SKIP_NAME] ??= new self(['name' => self::SKIP_NAME, 'description' => 'Directs the executor to skip this field or fragment when the `if` argument is true.', 'locations' => [Directive_Location::FIELD, Directive_Location::FRAGMENT_SPREAD, Directive_Location::INLINE_FRAGMENT], 'args' => [self::IF_ARGUMENT_NAME => ['type' => Type::non_null(Type::boolean()), 'description' => 'Skipped when true.']]]);
    }
    public static function deprecated_directive(): Directive
    {
        return self::$internal_directives[self::DEPRECATED_NAME] ??= new self(['name' => self::DEPRECATED_NAME, 'description' => 'Marks an element of a GraphQL schema as no longer supported.', 'locations' => [Directive_Location::FIELD_DEFINITION, Directive_Location::ENUM_VALUE, Directive_Location::ARGUMENT_DEFINITION, Directive_Location::INPUT_FIELD_DEFINITION], 'args' => [self::REASON_ARGUMENT_NAME => ['type' => Type::string(), 'description' => 'Explains why this element was deprecated, usually also including a suggestion for how to access supported similar data. Formatted using the Markdown syntax, as specified by [CommonMark](https://commonmark.org/).', 'defaultValue' => self::DEFAULT_DEPRECATION_REASON]]]);
    }
    public static function one_of_directive(): Directive
    {
        return self::$internal_directives[self::ONE_OF_NAME] ??= new self(['name' => self::ONE_OF_NAME, 'description' => 'Indicates that an Input Object is a OneOf Input Object (and thus requires exactly one of its fields be provided).', 'locations' => [Directive_Location::INPUT_OBJECT], 'args' => []]);
    }
    public static function is_built_in_directive(self $directive): bool
    {
        return array_key_exists($directive->name, self::built_in_directives());
    }
    /** @deprecated use {@see Directive::isBuiltInDirective()} */
    public static function is_specified_directive(Directive $directive): bool
    {
        return self::is_built_in_directive($directive);
    }
    public static function reset_cached_instances(): void
    {
        self::$internal_directives = null;
    }
}
<?php

declare (strict_types=1);
namespace Graph_Ql\Server;

use Graph_Ql\Error\Debug_Flag;
use Graph_Ql\Error\Invariant_Violation;
use Graph_Ql\Executor\Execution_Result;
use Graph_Ql\Executor\Promise\Promise_Adapter;
use Graph_Ql\Language\AST\Document_Node;
use Graph_Ql\Type\Schema;
use Graph_Ql\Utils\Utils;
use Graph_Ql\Validator\Rules\Validation_Rule;
/**
 * Server configuration class.
 * Could be passed directly to server constructor. List of options accepted by **create** method is
 * [described in docs](executing-queries.md#server-configuration-options).
 *
 * Usage example:
 *
 *     $config = GraphQL\Server\ServerConfig::create()
 *         ->setSchema($mySchema)
 *         ->setContext($myContext);
 *
 *     $server = new GraphQL\Server\StandardServer($config);
 *
 * @see ExecutionResult
 *
 * @phpstan-type PersistedQueryLoader callable(string $queryId, OperationParams $operation): (string|DocumentNode)
 * @phpstan-type RootValueResolver callable(OperationParams $operation, DocumentNode $doc, string $operationType): mixed
 * @phpstan-type ValidationRulesOption array<ValidationRule>|null|callable(OperationParams $operation, DocumentNode $doc, string $operationType): array<ValidationRule>
 *
 * @phpstan-import-type ErrorsHandler from ExecutionResult
 * @phpstan-import-type ErrorFormatter from ExecutionResult
 *
 * @see \GraphQL\Tests\Server\ServerConfigTest
 */
class Server_Config
{
    /**
     * Converts an array of options to instance of ServerConfig
     * (or just returns empty config when array is not passed).
     *
     * @param array<string, mixed> $config
     *
     * @api
     *
     * @throws InvariantViolation
     */
    public static function create(array $config = []): self
    {
        $instance = new static();
        foreach ($config as $key => $value) {
            switch ($key) {
                case 'schema':
                    $instance->set_schema($value);
                    break;
                case 'rootValue':
                    $instance->set_root_value($value);
                    break;
                case 'context':
                    $instance->set_context($value);
                    break;
                case 'fieldResolver':
                    $instance->set_field_resolver($value);
                    break;
                case 'validationRules':
                    $instance->set_validation_rules($value);
                    break;
                case 'queryBatching':
                    $instance->set_query_batching($value);
                    break;
                case 'debugFlag':
                    $instance->set_debug_flag($value);
                    break;
                case 'persistedQueryLoader':
                    $instance->set_persisted_query_loader($value);
                    break;
                case 'errorFormatter':
                    $instance->set_error_formatter($value);
                    break;
                case 'errorsHandler':
                    $instance->set_errors_handler($value);
                    break;
                case 'promiseAdapter':
                    $instance->set_promise_adapter($value);
                    break;
                default:
                    throw new Invariant_Violation("Unknown server config option: {$key}");
            }
        }
        return $instance;
    }
    private ?Schema $schema = null;
    /** @var mixed|callable(self, OperationParams, DocumentNode): mixed|null */
    private $context;
    /**
     * @var mixed|callable
     *
     * @phpstan-var mixed|RootValueResolver
     */
    private $root_value;
    /**
     * @var callable|null
     *
     * @phpstan-var ErrorFormatter|null
     */
    private $error_formatter;
    /**
     * @var callable|null
     *
     * @phpstan-var ErrorsHandler|null
     */
    private $errors_handler;
    private int $debug_flag = Debug_Flag::NONE;
    private bool $query_batching = false;
    /**
     * @var array<ValidationRule>|callable|null
     *
     * @phpstan-var ValidationRulesOption
     */
    private $validation_rules;
    /** @var callable|null */
    private $field_resolver;
    private ?Promise_Adapter $promise_adapter = null;
    /**
     * @var callable|null
     *
     * @phpstan-var PersistedQueryLoader|null
     */
    private $persisted_query_loader;
    /** @api */
    public function set_schema(Schema $schema): self
    {
        $this->schema = $schema;
        return $this;
    }
    /**
     * @param mixed|callable $context
     *
     * @api
     */
    public function set_context($context): self
    {
        $this->context = $context;
        return $this;
    }
    /**
     * @param mixed|callable $rootValue
     *
     * @phpstan-param mixed|RootValueResolver $rootValue
     *
     * @api
     */
    public function set_root_value($root_value): self
    {
        $this->root_value = $root_value;
        return $this;
    }
    /**
     * @phpstan-param ErrorFormatter $errorFormatter
     *
     * @api
     */
    public function set_error_formatter(callable $error_formatter): self
    {
        $this->error_formatter = $error_formatter;
        return $this;
    }
    /**
     * @phpstan-param ErrorsHandler $handler
     *
     * @api
     */
    public function set_errors_handler(callable $handler): self
    {
        $this->errors_handler = $handler;
        return $this;
    }
    /**
     * Set validation rules for this server.
     *
     * @param array<ValidationRule>|callable|null $validationRules
     *
     * @phpstan-param ValidationRulesOption $validationRules
     *
     * @api
     */
    public function set_validation_rules($validation_rules): self
    {
        // @phpstan-ignore-next-line necessary until we can use proper union types
        if (!is_array($validation_rules) && !is_callable($validation_rules) && $validation_rules !== null) {
            $invalid_validation_rules = Utils::print_safe($validation_rules);
            throw new Invariant_Violation("Server config expects array of validation rules or callable returning such array, but got {$invalid_validation_rules}");
        }
        $this->validation_rules = $validation_rules;
        return $this;
    }
    /** @api */
    public function set_field_resolver(callable $field_resolver): self
    {
        $this->field_resolver = $field_resolver;
        return $this;
    }
    /**
     * @phpstan-param PersistedQueryLoader|null $persistedQueryLoader
     *
     * @api
     */
    public function set_persisted_query_loader(?callable $persisted_query_loader): self
    {
        $this->persisted_query_loader = $persisted_query_loader;
        return $this;
    }
    /**
     * Set response debug flags.
     *
     * @see \GraphQL\Error\DebugFlag class for a list of all available flags
     *
     * @api
     */
    public function set_debug_flag(int $debug_flag = Debug_Flag::INCLUDE_DEBUG_MESSAGE): self
    {
        $this->debug_flag = $debug_flag;
        return $this;
    }
    /**
     * Allow batching queries (disabled by default).
     *
     * @api
     */
    public function set_query_batching(bool $enable_batching): self
    {
        $this->query_batching = $enable_batching;
        return $this;
    }
    /** @api */
    public function set_promise_adapter(Promise_Adapter $promise_adapter): self
    {
        $this->promise_adapter = $promise_adapter;
        return $this;
    }
    /** @return mixed|callable */
    public function get_context()
    {
        return $this->context;
    }
    /**
     * @return mixed|callable
     *
     * @phpstan-return mixed|RootValueResolver
     */
    public function get_root_value()
    {
        return $this->root_value;
    }
    public function get_schema(): ?Schema
    {
        return $this->schema;
    }
    /** @phpstan-return ErrorFormatter|null */
    public function get_error_formatter(): ?callable
    {
        return $this->error_formatter;
    }
    /** @phpstan-return ErrorsHandler|null */
    public function get_errors_handler(): ?callable
    {
        return $this->errors_handler;
    }
    public function get_promise_adapter(): ?Promise_Adapter
    {
        return $this->promise_adapter;
    }
    /**
     * @return array<ValidationRule>|callable|null
     *
     * @phpstan-return ValidationRulesOption
     */
    public function get_validation_rules()
    {
        return $this->validation_rules;
    }
    public function get_field_resolver(): ?callable
    {
        return $this->field_resolver;
    }
    /** @phpstan-return PersistedQueryLoader|null */
    public function get_persisted_query_loader(): ?callable
    {
        return $this->persisted_query_loader;
    }
    public function get_debug_flag(): int
    {
        return $this->debug_flag;
    }
    public function get_query_batching(): bool
    {
        return $this->query_batching;
    }
}
<?php

declare (strict_types=1);
namespace Graph_Ql\Executor;

use Graph_Ql\Error\Debug_Flag;
use Graph_Ql\Error\Error;
use Graph_Ql\Error\Formatted_Error;
/**
 * Returned after [query execution](executing-queries.md).
 * Represents both - result of successful execution and of a failed one
 * (with errors collected in `errors` prop).
 *
 * Could be converted to [spec-compliant](https://facebook.github.io/graphql/#sec-Response-Format)
 * serializable array using `toArray()`.
 *
 * @phpstan-type SerializableError array{
 *   message: string,
 *   locations?: array<int, array{line: int, column: int}>,
 *   path?: array<int, int|string>,
 *   extensions?: array<string, mixed>
 * }
 * @phpstan-type SerializableErrors list<SerializableError>
 * @phpstan-type SerializableResult array{
 *     data?: array<string, mixed>,
 *     errors?: SerializableErrors,
 *     extensions?: array<string, mixed>
 * }
 * @phpstan-type ErrorFormatter callable(\Throwable): SerializableError
 * @phpstan-type ErrorsHandler callable(list<Error> $errors, ErrorFormatter $formatter): SerializableErrors
 *
 * @see \GraphQL\Tests\Executor\ExecutionResultTest
 */
class Execution_Result implements \JsonSerializable
{
    /**
     * Data collected from resolvers during query execution.
     *
     * @api
     *
     * @var array<string, mixed>|null
     */
    public ?array $data = null;
    /**
     * Errors registered during query execution.
     *
     * If an error was caused by exception thrown in resolver, $error->getPrevious() would
     * contain original exception.
     *
     * @api
     *
     * @var list<Error>
     */
    public array $errors = [];
    /**
     * User-defined serializable array of extensions included in serialized result.
     *
     * @api
     *
     * @var array<string, mixed>|null
     */
    public ?array $extensions = null;
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
    /**
     * @param array<string, mixed>|null $data
     * @param list<Error> $errors
     * @param array<string, mixed> $extensions
     */
    public function __construct(?array $data = null, array $errors = [], array $extensions = [])
    {
        $this->data = $data;
        $this->errors = $errors;
        $this->extensions = $extensions;
    }
    /**
     * Define custom error formatting (must conform to http://facebook.github.io/graphql/#sec-Errors).
     *
     * Expected signature is: function (GraphQL\Error\Error $error): array
     *
     * Default formatter is "GraphQL\Error\FormattedError::createFromException"
     *
     * Expected returned value must be an array:
     * array(
     *    'message' => 'errorMessage',
     *    // ... other keys
     * );
     *
     * @phpstan-param ErrorFormatter|null $errorFormatter
     *
     * @api
     */
    public function set_error_formatter(?callable $error_formatter): self
    {
        $this->error_formatter = $error_formatter;
        return $this;
    }
    /**
     * Define custom logic for error handling (filtering, logging, etc).
     *
     * Expected handler signature is:
     * fn (array $errors, callable $formatter): array
     *
     * Default handler is:
     * fn (array $errors, callable $formatter): array => array_map($formatter, $errors)
     *
     * @phpstan-param ErrorsHandler|null $errorsHandler
     *
     * @api
     */
    public function set_errors_handler(?callable $errors_handler): self
    {
        $this->errors_handler = $errors_handler;
        return $this;
    }
    /** @phpstan-return SerializableResult */
    #[\Return_Type_Will_Change]
    public function jsonSerialize(): array
    {
        return $this->to_array();
    }
    /**
     * Converts GraphQL query result to spec-compliant serializable array using provided
     * errors handler and formatter.
     *
     * If debug argument is passed, output of error formatter is enriched which debugging information
     * ("debugMessage", "trace" keys depending on flags).
     *
     * $debug argument must sum of flags from @see \GraphQL\Error\DebugFlag
     *
     * @phpstan-return SerializableResult
     *
     * @api
     */
    public function to_array(int $debug = Debug_Flag::NONE): array
    {
        $result = [];
        if ($this->errors !== []) {
            $errors_handler = $this->errors_handler ?? static fn(array $errors, callable $formatter): array => array_map($formatter, $errors);
            /** @phpstan-var SerializableErrors */
            $handled_errors = $errors_handler($this->errors, Formatted_Error::prepare_formatter($this->error_formatter, $debug));
            // While we know that there were errors initially, they might have been discarded
            if ($handled_errors !== []) {
                $result['errors'] = $handled_errors;
            }
        }
        if ($this->data !== null) {
            $result['data'] = $this->data;
        }
        if ($this->extensions !== null && $this->extensions !== []) {
            $result['extensions'] = $this->extensions;
        }
        return $result;
    }
}
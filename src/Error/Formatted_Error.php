<?php

declare (strict_types=1);
namespace Graph_Ql\Error;

use Graph_Ql\Executor\Execution_Result;
use Graph_Ql\Language\Source;
use Graph_Ql\Language\Source_Location;
use Graph_Ql\Type\Definition\Type;
use Graph_Ql\Utils\Utils;
use Php_Unit\Framework\Test;
/**
 * This class is used for [default error formatting](error-handling.md).
 * It converts PHP exceptions to [spec-compliant errors](https://facebook.github.io/graphql/#sec-Errors)
 * and provides tools for error debugging.
 *
 * @see ExecutionResult
 *
 * @phpstan-import-type SerializableError from ExecutionResult
 * @phpstan-import-type ErrorFormatter from ExecutionResult
 *
 * @see \GraphQL\Tests\Error\FormattedErrorTest
 */
class Formatted_Error
{
    private static string $internal_error_message = 'Internal server error';
    /**
     * Set default error message for internal errors formatted using createFormattedError().
     * This value can be overridden by passing 3rd argument to `createFormattedError()`.
     *
     * @api
     */
    public static function set_internal_error_message(string $msg): void
    {
        self::$internal_error_message = $msg;
    }
    /**
     * Prints a GraphQLError to a string, representing useful location information
     * about the error's position in the source.
     */
    public static function print_error(Error $error): string
    {
        $printed_locations = [];
        $nodes = $error->nodes;
        if (isset($nodes) && $nodes !== []) {
            foreach ($nodes as $node) {
                $location = $node->loc;
                if (isset($location)) {
                    $source = $location->source;
                    if (isset($source)) {
                        $printed_locations[] = self::highlight_source_at_location($source, $source->get_location($location->start));
                    }
                }
            }
        } elseif ($error->get_source() !== null && $error->get_locations() !== []) {
            $source = $error->get_source();
            foreach ($error->get_locations() as $location) {
                $printed_locations[] = self::highlight_source_at_location($source, $location);
            }
        }
        return $printed_locations === [] ? $error->get_message() : implode("\n\n", array_merge([$error->get_message()], $printed_locations)) . "\n";
    }
    /**
     * Render a helpful description of the location of the error in the GraphQL
     * Source document.
     */
    private static function highlight_source_at_location(Source $source, Source_Location $location): string
    {
        $line = $location->line;
        $line_offset = $source->location_offset->line - 1;
        $column_offset = self::get_column_offset($source, $location);
        $context_line = $line + $line_offset;
        $context_column = $location->column + $column_offset;
        $prev_line_num = (string) ($context_line - 1);
        $line_num = (string) $context_line;
        $next_line_num = (string) ($context_line + 1);
        $pad_len = strlen($next_line_num);
        $lines = Utils::split_lines($source->body);
        $lines[0] = self::spaces($source->location_offset->column - 1) . $lines[0];
        $output_lines = ["{$source->name} ({$context_line}:{$context_column})", $line >= 2 ? self::left_pad($pad_len, $prev_line_num) . ': ' . $lines[$line - 2] : null, self::left_pad($pad_len, $line_num) . ': ' . $lines[$line - 1], self::spaces(2 + $pad_len + $context_column - 1) . '^', $line < count($lines) ? self::left_pad($pad_len, $next_line_num) . ': ' . $lines[$line] : null];
        return implode("\n", array_filter($output_lines));
    }
    private static function get_column_offset(Source $source, Source_Location $location): int
    {
        return $location->line === 1 ? $source->location_offset->column - 1 : 0;
    }
    private static function spaces(int $length): string
    {
        return str_repeat(' ', $length);
    }
    private static function left_pad(int $length, string $str): string
    {
        return self::spaces($length - mb_strlen($str)) . $str;
    }
    /**
     * Convert any exception to a GraphQL spec compliant array.
     *
     * This method only exposes the exception message when the given exception
     * implements the ClientAware interface, or when debug flags are passed.
     *
     * For a list of available debug flags @see \GraphQL\Error\DebugFlag constants.
     *
     * @return SerializableError
     *
     * @api
     */
    public static function create_from_exception(\Throwable $exception, int $debug_flag = Debug_Flag::NONE, ?string $internal_error_message = null): array
    {
        $internal_error_message ??= self::$internal_error_message;
        $message = $exception instanceof Client_Aware && $exception->is_client_safe() ? $exception->get_message() : $internal_error_message;
        $formatted_error = ['message' => $message];
        if ($exception instanceof Error) {
            $locations = array_map(static fn(Source_Location $loc): array => $loc->to_serializable_array(), $exception->get_locations());
            if ($locations !== []) {
                $formatted_error['locations'] = $locations;
            }
            if ($exception->path !== null && $exception->path !== []) {
                $formatted_error['path'] = $exception->path;
            }
        }
        if ($exception instanceof Provides_Extensions) {
            $extensions = $exception->get_extensions();
            if (is_array($extensions) && $extensions !== []) {
                $formatted_error['extensions'] = $extensions;
            }
        }
        if ($debug_flag !== Debug_Flag::NONE) {
            return self::add_debug_entries($formatted_error, $exception, $debug_flag);
        }
        return $formatted_error;
    }
    /**
     * Decorates spec-compliant $formattedError with debug entries according to $debug flags.
     *
     * @param SerializableError $formattedError
     * @param int $debugFlag For available flags @see \GraphQL\Error\DebugFlag
     *
     * @throws \Throwable
     *
     * @return SerializableError
     */
    public static function add_debug_entries(array $formatted_error, \Throwable $e, int $debug_flag): array
    {
        if ($debug_flag === Debug_Flag::NONE) {
            return $formatted_error;
        }
        if (($debug_flag & Debug_Flag::RETHROW_INTERNAL_EXCEPTIONS) !== 0) {
            if (!$e instanceof Error) {
                throw $e;
            }
            if ($e->get_previous() !== null) {
                throw $e->get_previous();
            }
        }
        $is_unsafe = !$e instanceof Client_Aware || !$e->is_client_safe();
        if (($debug_flag & Debug_Flag::RETHROW_UNSAFE_EXCEPTIONS) !== 0 && $is_unsafe && $e->get_previous() !== null) {
            throw $e->get_previous();
        }
        if (($debug_flag & Debug_Flag::INCLUDE_DEBUG_MESSAGE) !== 0 && $is_unsafe) {
            $formatted_error['extensions']['debugMessage'] = $e->get_message();
        }
        if (($debug_flag & Debug_Flag::INCLUDE_TRACE) !== 0) {
            $actual_error = $e->get_previous() ?? $e;
            if ($e instanceof \ErrorException || $e instanceof \Error) {
                $formatted_error['extensions']['file'] = $e->get_file();
                $formatted_error['extensions']['line'] = $e->get_line();
            } else {
                $formatted_error['extensions']['file'] = $actual_error->get_file();
                $formatted_error['extensions']['line'] = $actual_error->get_line();
            }
            $is_trivial = $e instanceof Error && $e->get_previous() === null;
            if (!$is_trivial) {
                $formatted_error['extensions']['trace'] = static::to_safe_trace($actual_error);
            }
        }
        return $formatted_error;
    }
    /**
     * Prepares final error formatter taking in account $debug flags.
     *
     * If initial formatter is not set, FormattedError::createFromException is used.
     *
     * @phpstan-param ErrorFormatter|null $formatter
     */
    public static function prepare_formatter(?callable $formatter, int $debug): callable
    {
        return $formatter === null ? static fn(\Throwable $e): array => static::create_from_exception($e, $debug) : static fn(\Throwable $e): array => static::add_debug_entries($formatter($e), $e, $debug);
    }
    /**
     * Returns error trace as serializable array.
     *
     * @return array<int, array{
     *     file?: string,
     *     line?: int,
     *     function?: string,
     *     call?: string,
     * }>
     *
     * @api
     */
    public static function to_safe_trace(\Throwable $error): array
    {
        $trace = $error->get_trace();
        if (isset($trace[0]['function']) && isset($trace[0]['class']) && $trace[0]['class'] . '::' . $trace[0]['function'] === 'GraphQL\Utils\Utils::invariant') {
            array_shift($trace);
        } elseif (!isset($trace[0]['file'])) {
            // Remove root call as it's likely error handler trace:
            array_shift($trace);
        }
        $formatted = [];
        foreach ($trace as $err) {
            $safe_err = [];
            if (isset($err['file'])) {
                $safe_err['file'] = $err['file'];
            }
            if (isset($err['line'])) {
                $safe_err['line'] = $err['line'];
            }
            $func = $err['function'];
            $args = array_map([self::class, 'printVar'], $err['args'] ?? []);
            $func_str = $func . '(' . implode(', ', $args) . ')';
            if (isset($err['class'])) {
                $safe_err['call'] = $err['class'] . '::' . $func_str;
            } else {
                $safe_err['function'] = $func_str;
            }
            $formatted[] = $safe_err;
        }
        return $formatted;
    }
    /** @param mixed $var */
    public static function print_var($var): string
    {
        if ($var instanceof Type) {
            return 'GraphQLType: ' . $var->to_string();
        }
        if (is_object($var)) {
            // Calling `count` on instances of `PHPUnit\Framework\Test` triggers an unintended side effect - see https://github.com/sebastianbergmann/phpunit/issues/5866#issuecomment-2172429263
            $count = !$var instanceof Test && $var instanceof \Countable ? '(' . count($var) . ')' : '';
            return 'instance of ' . get_class($var) . $count;
        }
        if (is_array($var)) {
            return 'array(' . count($var) . ')';
        }
        if ($var === '') {
            return '(empty string)';
        }
        if (is_string($var)) {
            return "'" . addcslashes($var, "'") . "'";
        }
        if (is_bool($var)) {
            return $var ? 'true' : 'false';
        }
        if (is_scalar($var)) {
            return (string) $var;
        }
        if ($var === null) {
            return 'null';
        }
        return gettype($var);
    }
}
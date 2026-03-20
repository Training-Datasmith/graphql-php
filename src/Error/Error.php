<?php

declare (strict_types=1);
namespace Graph_Ql\Error;

use Graph_Ql\Language\AST\Node;
use Graph_Ql\Language\Source;
use Graph_Ql\Language\Source_Location;
/**
 * Describes an Error found during the parse, validate, or
 * execute phases of performing a GraphQL operation. In addition to a message
 * and stack trace, it also includes information about the locations in a
 * GraphQL document and/or execution result that correspond to the Error.
 *
 * When the error was caused by an exception thrown in resolver, original exception
 * is available via `getPrevious()`.
 *
 * Also read related docs on [error handling](error-handling.md)
 *
 * Class extends standard PHP `\Exception`, so all standard methods of base `\Exception` class
 * are available in addition to those listed below.
 *
 * @see \GraphQL\Tests\Error\ErrorTest
 */
class Error extends \Exception implements \JsonSerializable, Client_Aware, Provides_Extensions
{
    /**
     * Lazily initialized.
     *
     * @var array<int, SourceLocation>
     */
    private array $locations;
    /**
     * An array describing the JSON-path into the execution response which
     * corresponds to this error. Only included for errors during execution.
     * When fields are aliased, the path includes aliases.
     *
     * @var list<int|string>|null
     */
    public ?array $path;
    /**
     * An array describing the JSON-path into the execution response which
     * corresponds to this error. Only included for errors during execution.
     * This will never include aliases.
     *
     * @var list<int|string>|null
     */
    public ?array $unaliased_path;
    /**
     * An array of GraphQL AST Nodes corresponding to this error.
     *
     * @var array<Node>|null
     */
    public ?array $nodes;
    /**
     * The source GraphQL document for the first location of this error.
     *
     * Note that if this Error represents more than one node, the source may not
     * represent nodes after the first node.
     */
    private ?Source $source;
    /** @var array<int, int>|null */
    private ?array $positions;
    private bool $is_client_safe;
    /** @var array<string, mixed>|null */
    protected ?array $extensions;
    /**
     * @param iterable<array-key, Node|null>|Node|null $nodes
     * @param array<int, int>|null $positions
     * @param list<int|string>|null $path
     * @param array<string, mixed>|null $extensions
     * @param list<int|string>|null $unaliasedPath
     */
    public function __construct(string $message = '', $nodes = null, ?Source $source = null, ?array $positions = null, ?array $path = null, ?\Throwable $previous = null, ?array $extensions = null, ?array $unaliased_path = null)
    {
        parent::__construct($message, 0, $previous);
        // Compute list of blame nodes.
        if ($nodes instanceof \Traversable) {
            /** @phpstan-ignore arrayFilter.strict */
            $this->nodes = array_filter(iterator_to_array($nodes));
        } elseif (is_array($nodes)) {
            $this->nodes = array_filter($nodes);
        } elseif ($nodes !== null) {
            $this->nodes = [$nodes];
        } else {
            $this->nodes = null;
        }
        $this->source = $source;
        $this->positions = $positions;
        $this->path = $path;
        $this->unaliased_path = $unaliased_path;
        if (is_array($extensions) && $extensions !== []) {
            $this->extensions = $extensions;
        } elseif ($previous instanceof Provides_Extensions) {
            $this->extensions = $previous->get_extensions();
        } else {
            $this->extensions = null;
        }
        $this->is_client_safe = $previous instanceof Client_Aware ? $previous->is_client_safe() : $previous === null;
    }
    /**
     * Given an arbitrary Error, presumably thrown while attempting to execute a
     * GraphQL operation, produce a new GraphQLError aware of the location in the
     * document responsible for the original Error.
     *
     * @param mixed $error
     * @param iterable<Node>|Node|null $nodes
     * @param list<int|string>|null $path
     * @param list<int|string>|null $unaliasedPath
     */
    public static function create_located_error($error, $nodes = null, ?array $path = null, ?array $unaliased_path = null): Error
    {
        if ($error instanceof self) {
            if ($error->is_located()) {
                return $error;
            }
            $nodes ??= $error->get_nodes();
            $path ??= $error->get_path();
            $unaliased_path ??= $error->get_unaliased_path();
        }
        $source = null;
        $original_error = null;
        $positions = [];
        $extensions = [];
        if ($error instanceof self) {
            $message = $error->get_message();
            $original_error = $error;
            $source = $error->get_source();
            $positions = $error->get_positions();
            $extensions = $error->get_extensions();
        } elseif ($error instanceof Invariant_Violation) {
            $message = $error->get_message();
            $original_error = $error->get_previous() ?? $error;
        } elseif ($error instanceof \Throwable) {
            $message = $error->get_message();
            $original_error = $error;
        } else {
            $message = (string) $error;
        }
        $non_empty_message = $message === '' ? 'An unknown error occurred.' : $message;
        return new static($non_empty_message, $nodes, $source, $positions, $path, $original_error, $extensions, $unaliased_path);
    }
    protected function is_located(): bool
    {
        $path = $this->get_path();
        $nodes = $this->get_nodes();
        return $path !== null && $path !== [] && $nodes !== null && $nodes !== [];
    }
    public function is_client_safe(): bool
    {
        return $this->is_client_safe;
    }
    public function get_source(): ?Source
    {
        return $this->source ??= $this->nodes[0]->loc->source ?? null;
    }
    /** @return array<int, int> */
    public function get_positions(): array
    {
        if (!isset($this->positions)) {
            $this->positions = [];
            if (isset($this->nodes)) {
                foreach ($this->nodes as $node) {
                    if (isset($node->loc->start)) {
                        $this->positions[] = $node->loc->start;
                    }
                }
            }
        }
        return $this->positions;
    }
    /**
     * An array of locations within the source GraphQL document which correspond to this error.
     *
     * Each entry has information about `line` and `column` within source GraphQL document:
     * $location->line;
     * $location->column;
     *
     * Errors during validation often contain multiple locations, for example to
     * point out to field mentioned in multiple fragments. Errors during execution include a
     * single location, the field which produced the error.
     *
     * @return array<int, SourceLocation>
     *
     * @api
     */
    public function get_locations(): array
    {
        if (!isset($this->locations)) {
            $positions = $this->get_positions();
            $source = $this->get_source();
            $nodes = $this->get_nodes();
            $this->locations = [];
            if ($source !== null && $positions !== []) {
                foreach ($positions as $position) {
                    $this->locations[] = $source->get_location($position);
                }
            } elseif ($nodes !== null && $nodes !== []) {
                foreach ($nodes as $node) {
                    if (isset($node->loc->source)) {
                        $this->locations[] = $node->loc->source->get_location($node->loc->start);
                    }
                }
            }
        }
        return $this->locations;
    }
    /** @return array<Node>|null */
    public function get_nodes(): ?array
    {
        return $this->nodes;
    }
    /**
     * Returns an array describing the path from the root value to the field which produced this error.
     * Only included for execution errors. When fields are aliased, the path includes aliases.
     *
     * @return list<int|string>|null
     *
     * @api
     */
    public function get_path(): ?array
    {
        return $this->path;
    }
    /**
     * Returns an array describing the path from the root value to the field which produced this error.
     * Only included for execution errors. This will never include aliases.
     *
     * @return list<int|string>|null
     *
     * @api
     */
    public function get_unaliased_path(): ?array
    {
        return $this->unaliased_path;
    }
    /** @return array<string, mixed>|null */
    public function get_extensions(): ?array
    {
        return $this->extensions;
    }
    /**
     * Specify data which should be serialized to JSON.
     *
     * @see http://php.net/manual/en/jsonserializable.jsonserialize.php
     *
     * @return array<string, mixed> data which can be serialized by <b>json_encode</b>,
     *                              which is a value of any type other than a resource
     */
    #[\Return_Type_Will_Change]
    public function jsonSerialize(): array
    {
        return Formatted_Error::create_from_exception($this);
    }
    public function __toString(): string
    {
        return Formatted_Error::print_error($this);
    }
}
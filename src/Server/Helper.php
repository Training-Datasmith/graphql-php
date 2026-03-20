<?php

declare (strict_types=1);
namespace Graph_Ql\Server;

use Graph_Ql\Error\Error;
use Graph_Ql\Error\Formatted_Error;
use Graph_Ql\Error\Invariant_Violation;
use Graph_Ql\Executor\Execution_Result;
use Graph_Ql\Executor\Executor;
use Graph_Ql\Executor\Promise\Adapter\Sync_Promise_Adapter;
use Graph_Ql\Executor\Promise\Promise;
use Graph_Ql\Executor\Promise\Promise_Adapter;
use Graph_Ql\Graph_Ql;
use Graph_Ql\Language\AST\Document_Node;
use Graph_Ql\Language\Parser;
use Graph_Ql\Server\Exception\Batched_Queries_Are_Not_Supported;
use Graph_Ql\Server\Exception\Cannot_Parse_Json_Body;
use Graph_Ql\Server\Exception\Cannot_Parse_Variables;
use Graph_Ql\Server\Exception\Cannot_Read_Body;
use Graph_Ql\Server\Exception\Failed_To_Determine_Operation_Type;
use Graph_Ql\Server\Exception\Get_Method_Supports_Only_Query_Operation;
use Graph_Ql\Server\Exception\Http_Method_Not_Supported;
use Graph_Ql\Server\Exception\Invalid_Operation_Parameter;
use Graph_Ql\Server\Exception\Invalid_Query_Id_Parameter;
use Graph_Ql\Server\Exception\Invalid_Query_Parameter;
use Graph_Ql\Server\Exception\Missing_Content_Type_Header;
use Graph_Ql\Server\Exception\Missing_Query_Or_Query_Id_Parameter;
use Graph_Ql\Server\Exception\Persisted_Queries_Are_Not_Supported;
use Graph_Ql\Server\Exception\Unexpected_Content_Type;
use Graph_Ql\Utils\AST;
use Graph_Ql\Utils\Utils;
use Psr\Http\Message\Request_Interface;
use Psr\Http\Message\Response_Interface;
use Psr\Http\Message\Server_Request_Interface;
use Psr\Http\Message\Stream_Interface;
/**
 * Contains functionality that could be re-used by various server implementations.
 *
 * @see \GraphQL\Tests\Server\HelperTest
 */
class Helper
{
    /**
     * Parses HTTP request using PHP globals and returns GraphQL OperationParams
     * contained in this request. For batched requests it returns an array of OperationParams.
     *
     * This function does not check validity of these params
     * (validation is performed separately in validateOperationParams() method).
     *
     * If $readRawBodyFn argument is not provided - will attempt to read raw request body
     * from `php://input` stream.
     *
     * Internally it normalizes input to $method, $bodyParams and $queryParams and
     * calls `parseRequestParams()` to produce actual return value.
     *
     * For PSR-7 request parsing use `parsePsrRequest()` instead.
     *
     * @throws RequestError
     *
     * @return OperationParams|array<int, OperationParams>
     *
     * @api
     */
    public function parse_http_request(?callable $read_raw_body_fn = null)
    {
        $method = $_SERVER['REQUEST_METHOD'] ?? null;
        $body_params = [];
        $url_params = $_GET;
        if ($method === 'POST') {
            $content_type = $_SERVER['CONTENT_TYPE'] ?? null;
            if ($content_type === null) {
                throw new Missing_Content_Type_Header('Missing "Content-Type" header');
            }
            if (stripos($content_type, 'application/graphql') !== false) {
                $raw_body = $read_raw_body_fn === null ? $this->read_raw_body() : $read_raw_body_fn();
                $body_params = ['query' => $raw_body];
            } elseif (stripos($content_type, 'application/json') !== false) {
                $raw_body = $read_raw_body_fn === null ? $this->read_raw_body() : $read_raw_body_fn();
                $body_params = $this->decode_json($raw_body);
                $this->assert_json_object_or_array($body_params);
            } elseif (stripos($content_type, 'application/x-www-form-urlencoded') !== false) {
                $body_params = $_POST;
            } elseif (stripos($content_type, 'multipart/form-data') !== false) {
                $body_params = $_POST;
            } else {
                throw new Unexpected_Content_Type('Unexpected content type: ' . Utils::print_safe_json($content_type));
            }
        }
        return $this->parse_request_params($method, $body_params, $url_params);
    }
    /**
     * Parses normalized request params and returns instance of OperationParams
     * or array of OperationParams in case of batch operation.
     *
     * Returned value is a suitable input for `executeOperation` or `executeBatch` (if array)
     *
     * @param array<mixed> $bodyParams
     * @param array<mixed> $queryParams
     *
     * @throws RequestError
     *
     * @return OperationParams|array<int, OperationParams>
     *
     * @api
     */
    public function parse_request_params(string $method, array $body_params, array $query_params)
    {
        if ($method === 'GET') {
            return Operation_Params::create($query_params, true);
        }
        if ($method === 'POST') {
            if (isset($body_params[0])) {
                $operations = [];
                foreach ($body_params as $entry) {
                    $operations[] = Operation_Params::create($entry);
                }
                return $operations;
            }
            return Operation_Params::create($body_params);
        }
        throw new Http_Method_Not_Supported("HTTP Method \"{$method}\" is not supported");
    }
    /**
     * Checks validity of OperationParams extracted from HTTP request and returns an array of errors
     * if params are invalid (or empty array when params are valid).
     *
     * @return list<RequestError>
     *
     * @api
     */
    public function validate_operation_params(Operation_Params $params): array
    {
        $errors = [];
        $query = $params->query ?? '';
        $query_id = $params->query_id ?? '';
        if ($query === '' && $query_id === '') {
            $errors[] = new Missing_Query_Or_Query_Id_Parameter('GraphQL Request must include at least one of those two parameters: "query" or "queryId"');
        }
        if (!is_string($query)) {
            $errors[] = new Invalid_Query_Parameter('GraphQL Request parameter "query" must be string, but got ' . Utils::print_safe_json($params->query));
        }
        if (!is_string($query_id)) {
            $errors[] = new Invalid_Query_Id_Parameter('GraphQL Request parameter "queryId" must be string, but got ' . Utils::print_safe_json($params->query_id));
        }
        if ($params->operation !== null && !is_string($params->operation)) {
            $errors[] = new Invalid_Operation_Parameter('GraphQL Request parameter "operation" must be string, but got ' . Utils::print_safe_json($params->operation));
        }
        if ($params->variables !== null && (!is_array($params->variables) || isset($params->variables[0]))) {
            $errors[] = new Cannot_Parse_Variables('GraphQL Request parameter "variables" must be object or JSON string parsed to object, but got ' . Utils::print_safe_json($params->original_input['variables']));
        }
        return $errors;
    }
    /**
     * Executes GraphQL operation with given server configuration and returns execution result
     * (or promise when promise adapter is different from SyncPromiseAdapter).
     *
     * @throws \Exception
     * @throws InvariantViolation
     *
     * @return ExecutionResult|Promise
     *
     * @api
     */
    public function execute_operation(Server_Config $config, Operation_Params $op)
    {
        $promise_adapter = $config->get_promise_adapter() ?? Executor::get_default_promise_adapter();
        $result = $this->promise_to_execute_operation($promise_adapter, $config, $op);
        if ($promise_adapter instanceof Sync_Promise_Adapter) {
            return $promise_adapter->wait($result);
        }
        return $result;
    }
    /**
     * Executes batched GraphQL operations with shared promise queue
     * (thus, effectively batching deferreds|promises of all queries at once).
     *
     * @param array<OperationParams> $operations
     *
     * @throws \Exception
     * @throws InvariantViolation
     *
     * @return array<int, ExecutionResult>|Promise
     *
     * @api
     */
    public function execute_batch(Server_Config $config, array $operations)
    {
        $promise_adapter = $config->get_promise_adapter() ?? Executor::get_default_promise_adapter();
        $result = [];
        foreach ($operations as $operation) {
            $result[] = $this->promise_to_execute_operation($promise_adapter, $config, $operation, true);
        }
        $result = $promise_adapter->all($result);
        // Wait for promised results when using sync promises
        if ($promise_adapter instanceof Sync_Promise_Adapter) {
            return $promise_adapter->wait($result);
        }
        return $result;
    }
    /**
     * @throws \Exception
     * @throws InvariantViolation
     */
    protected function promise_to_execute_operation(Promise_Adapter $promise_adapter, Server_Config $config, Operation_Params $op, bool $is_batch = false): Promise
    {
        try {
            if ($config->get_schema() === null) {
                throw new Invariant_Violation('Schema is required for the server');
            }
            if ($is_batch && !$config->get_query_batching()) {
                throw new Batched_Queries_Are_Not_Supported('Batched queries are not supported by this server');
            }
            $errors = $this->validate_operation_params($op);
            if ($errors !== []) {
                $located_errors = array_map([Error::class, 'createLocatedError'], $errors);
                return $promise_adapter->create_fulfilled(new Execution_Result(null, $located_errors));
            }
            $doc = $op->query_id !== null ? $this->load_persisted_query($config, $op) : $op->query;
            if (!$doc instanceof Document_Node) {
                $doc = Parser::parse($doc);
            }
            $operation_ast = AST::get_operation_ast($doc, $op->operation);
            if ($operation_ast === null) {
                throw new Failed_To_Determine_Operation_Type('Failed to determine operation type');
            }
            $operation_type = $operation_ast->operation;
            if ($operation_type !== 'query' && $op->read_only) {
                throw new Get_Method_Supports_Only_Query_Operation('GET supports only query operation');
            }
            $result = Graph_Ql::promise_to_execute($promise_adapter, $config->get_schema(), $doc, $this->resolve_root_value($config, $op, $doc, $operation_type), $this->resolve_context_value($config, $op, $doc, $operation_type), $op->variables, $op->operation, $config->get_field_resolver(), $this->resolve_validation_rules($config, $op, $doc, $operation_type));
        } catch (Request_Error $e) {
            $result = $promise_adapter->create_fulfilled(new Execution_Result(null, [Error::create_located_error($e)]));
        } catch (Error $e) {
            $result = $promise_adapter->create_fulfilled(new Execution_Result(null, [$e]));
        }
        $apply_error_handling = static function (Execution_Result $result) use ($config): Execution_Result {
            $result->set_errors_handler($config->get_errors_handler());
            $result->set_error_formatter(Formatted_Error::prepare_formatter($config->get_error_formatter(), $config->get_debug_flag()));
            return $result;
        };
        return $result->then($apply_error_handling);
    }
    /**
     * @throws RequestError
     *
     * @return mixed
     */
    protected function load_persisted_query(Server_Config $config, Operation_Params $operation_params)
    {
        $loader = $config->get_persisted_query_loader();
        if ($loader === null) {
            throw new Persisted_Queries_Are_Not_Supported('Persisted queries are not supported by this server');
        }
        $source = $loader($operation_params->query_id, $operation_params);
        // @phpstan-ignore-next-line Necessary until PHP gains function types
        if (!is_string($source) && !$source instanceof Document_Node) {
            $document_node = Document_Node::class;
            $safe_source = Utils::print_safe($source);
            throw new Invariant_Violation("Persisted query loader must return query string or instance of {$document_node} but got: {$safe_source}");
        }
        return $source;
    }
    /** @return array<mixed>|null */
    protected function resolve_validation_rules(Server_Config $config, Operation_Params $params, Document_Node $doc, string $operation_type): ?array
    {
        $validation_rules = $config->get_validation_rules();
        if (is_callable($validation_rules)) {
            $validation_rules = $validation_rules($params, $doc, $operation_type);
        }
        // @phpstan-ignore-next-line unless PHP gains function types, we have to check this at runtime
        if ($validation_rules !== null && !is_array($validation_rules)) {
            $safe_validation_rules = Utils::print_safe($validation_rules);
            throw new Invariant_Violation("Expecting validation rules to be array or callable returning array, but got: {$safe_validation_rules}");
        }
        return $validation_rules;
    }
    /** @return mixed */
    protected function resolve_root_value(Server_Config $config, Operation_Params $params, Document_Node $doc, string $operation_type)
    {
        $root_value = $config->get_root_value();
        if (is_callable($root_value)) {
            return $root_value($params, $doc, $operation_type);
        }
        return $root_value;
    }
    /** @return mixed user defined */
    protected function resolve_context_value(Server_Config $config, Operation_Params $params, Document_Node $doc, string $operation_type)
    {
        $context = $config->get_context();
        if (is_callable($context)) {
            return $context($params, $doc, $operation_type);
        }
        return $context;
    }
    /**
     * Send response using standard PHP `header()` and `echo`.
     *
     * @param Promise|ExecutionResult|array<ExecutionResult> $result
     *
     * @api
     *
     * @throws \JsonException
     */
    public function send_response($result): void
    {
        if ($result instanceof Promise) {
            $result->then(function ($actual_result): void {
                $this->emit_response($actual_result);
            });
        } else {
            $this->emit_response($result);
        }
    }
    /**
     * @param array<mixed>|\JsonSerializable $jsonSerializable
     *
     * @throws \JsonException
     */
    protected function emit_response($json_serializable): void
    {
        header('Content-Type: application/json;charset=utf-8');
        echo json_encode($json_serializable, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }
    /** @throws RequestError */
    protected function read_raw_body(): string
    {
        $body = file_get_contents('php://input');
        if ($body === false) {
            throw new Cannot_Read_Body('Cannot not read body.');
        }
        return $body;
    }
    /**
     * Converts PSR-7 request to OperationParams or an array thereof.
     *
     * @throws RequestError
     *
     * @return OperationParams|array<OperationParams>
     *
     * @api
     */
    public function parse_psr_request(Request_Interface $request)
    {
        if ($request->get_method() === 'GET') {
            $body_params = [];
        } else {
            $content_type = $request->get_header('content-type');
            if (!isset($content_type[0])) {
                throw new Missing_Content_Type_Header('Missing "Content-Type" header');
            }
            if (stripos($content_type[0], 'application/graphql') !== false) {
                $body_params = ['query' => (string) $request->get_body()];
            } elseif (stripos($content_type[0], 'application/json') !== false) {
                $body_params = $request instanceof Server_Request_Interface ? $request->get_parsed_body() : $this->decode_json((string) $request->get_body());
                $this->assert_json_object_or_array($body_params);
            } else {
                if ($request instanceof Server_Request_Interface) {
                    $body_params = $request->get_parsed_body();
                }
                $body_params ??= $this->decode_content((string) $request->get_body());
            }
        }
        parse_str(html_entity_decode($request->get_uri()->get_query()), $query_params);
        return $this->parse_request_params($request->get_method(), $body_params, $query_params);
    }
    /**
     * @throws RequestError
     *
     * @return mixed
     */
    protected function decode_json(string $raw_body)
    {
        $body_params = json_decode($raw_body, true);
        if (json_last_error() !== \JSON_ERROR_NONE) {
            throw new Cannot_Parse_Json_Body('Expected JSON object or array for "application/json" request, but failed to parse because: ' . json_last_error_msg());
        }
        return $body_params;
    }
    /** @return array<mixed> */
    protected function decode_content(string $raw_body): array
    {
        parse_str($raw_body, $body_params);
        return $body_params;
    }
    /**
     * @param mixed $bodyParams
     *
     * @throws RequestError
     */
    protected function assert_json_object_or_array($body_params): void
    {
        if (!is_array($body_params)) {
            $not_array = Utils::print_safe_json($body_params);
            throw new Cannot_Parse_Json_Body("Expected JSON object or array for \"application/json\" request, got: {$not_array}");
        }
    }
    /**
     * Converts query execution result to PSR-7 response.
     *
     * @param Promise|ExecutionResult|array<ExecutionResult> $result
     *
     * @throws \InvalidArgumentException
     * @throws \JsonException
     * @throws \RuntimeException
     *
     * @return Promise|ResponseInterface
     *
     * @api
     */
    public function to_psr_response($result, Response_Interface $response, Stream_Interface $writable_body_stream)
    {
        if ($result instanceof Promise) {
            return $result->then(fn($actual_result): Response_Interface => $this->do_convert_to_psr_response($actual_result, $response, $writable_body_stream));
        }
        return $this->do_convert_to_psr_response($result, $response, $writable_body_stream);
    }
    /**
     * @param ExecutionResult|array<ExecutionResult> $result
     *
     * @throws \InvalidArgumentException
     * @throws \JsonException
     * @throws \RuntimeException
     */
    protected function do_convert_to_psr_response($result, Response_Interface $response, Stream_Interface $writable_body_stream): Response_Interface
    {
        $writable_body_stream->write(json_encode($result, JSON_THROW_ON_ERROR));
        return $response->with_header('Content-Type', 'application/json')->with_body($writable_body_stream);
    }
}
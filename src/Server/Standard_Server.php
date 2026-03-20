<?php

declare (strict_types=1);
namespace Graph_Ql\Server;

use Graph_Ql\Error\Invariant_Violation;
use Graph_Ql\Executor\Execution_Result;
use Graph_Ql\Executor\Promise\Promise;
use Graph_Ql\Utils\Utils;
use Psr\Http\Message\Request_Interface;
use Psr\Http\Message\Response_Interface;
use Psr\Http\Message\Stream_Interface;
/**
 * GraphQL server compatible with both: [express-graphql](https://github.com/graphql/express-graphql)
 * and [Apollo Server](https://github.com/apollographql/graphql-server).
 * Usage Example:.
 *
 *     $server = new StandardServer([
 *       'schema' => $mySchema
 *     ]);
 *     $server->handleRequest();
 *
 * Or using [ServerConfig](class-reference.md#graphqlserverserverconfig) instance:
 *
 *     $config = GraphQL\Server\ServerConfig::create()
 *         ->setSchema($mySchema)
 *         ->setContext($myContext);
 *
 *     $server = new GraphQL\Server\StandardServer($config);
 *     $server->handleRequest();
 *
 * See [dedicated section in docs](executing-queries.md#using-server) for details.
 *
 * @see \GraphQL\Tests\Server\StandardServerTest
 */
class Standard_Server
{
    protected Server_Config $config;
    protected Helper $helper;
    /**
     * @param ServerConfig|array<string, mixed> $config
     *
     * @api
     *
     * @throws InvariantViolation
     */
    public function __construct($config)
    {
        if (is_array($config)) {
            $config = Server_Config::create($config);
        }
        // @phpstan-ignore-next-line necessary until we can use proper union types
        if (!$config instanceof Server_Config) {
            $safe_config = Utils::print_safe($config);
            throw new Invariant_Violation("Expecting valid server config, but got {$safe_config}");
        }
        $this->config = $config;
        $this->helper = new Helper();
    }
    /**
     * Parses HTTP request, executes and emits response (using standard PHP `header` function and `echo`).
     *
     * When $parsedBody is not set, it uses PHP globals to parse a request.
     * It is possible to implement request parsing elsewhere (e.g. using framework Request instance)
     * and then pass it to the server.
     *
     * See `executeRequest()` if you prefer to emit the response yourself
     * (e.g. using the Response object of some framework).
     *
     * @param OperationParams|array<OperationParams> $parsedBody
     *
     * @api
     *
     * @throws \Exception
     * @throws InvariantViolation
     * @throws RequestError
     */
    public function handle_request($parsed_body = null): void
    {
        $result = $this->execute_request($parsed_body);
        $this->helper->send_response($result);
    }
    /**
     * Executes a GraphQL operation and returns an execution result
     * (or promise when promise adapter is different from SyncPromiseAdapter).
     *
     * When $parsedBody is not set, it uses PHP globals to parse a request.
     * It is possible to implement request parsing elsewhere (e.g. using framework Request instance)
     * and then pass it to the server.
     *
     * PSR-7 compatible method executePsrRequest() does exactly this.
     *
     * @param OperationParams|array<OperationParams> $parsedBody
     *
     * @throws \Exception
     * @throws InvariantViolation
     * @throws RequestError
     *
     * @return ExecutionResult|array<int, ExecutionResult>|Promise
     *
     * @api
     */
    public function execute_request($parsed_body = null)
    {
        if ($parsed_body === null) {
            $parsed_body = $this->helper->parse_http_request();
        }
        if (is_array($parsed_body)) {
            return $this->helper->execute_batch($this->config, $parsed_body);
        }
        return $this->helper->execute_operation($this->config, $parsed_body);
    }
    /**
     * Executes PSR-7 request and fulfills PSR-7 response.
     *
     * See `executePsrRequest()` if you prefer to create response yourself
     * (e.g. using specific JsonResponse instance of some framework).
     *
     * @throws \Exception
     * @throws \InvalidArgumentException
     * @throws \JsonException
     * @throws \RuntimeException
     * @throws InvariantViolation
     * @throws RequestError
     *
     * @return ResponseInterface|Promise
     *
     * @api
     */
    public function process_psr_request(Request_Interface $request, Response_Interface $response, Stream_Interface $writable_body_stream)
    {
        $result = $this->execute_psr_request($request);
        return $this->helper->to_psr_response($result, $response, $writable_body_stream);
    }
    /**
     * Executes GraphQL operation and returns execution result
     * (or promise when promise adapter is different from SyncPromiseAdapter).
     *
     * @throws \Exception
     * @throws \JsonException
     * @throws InvariantViolation
     * @throws RequestError
     *
     * @return ExecutionResult|array<int, ExecutionResult>|Promise
     *
     * @api
     */
    public function execute_psr_request(Request_Interface $request)
    {
        $parsed_body = $this->helper->parse_psr_request($request);
        return $this->execute_request($parsed_body);
    }
}
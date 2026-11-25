<?php

/**
 * Unit tests for the MCP target of an endpoint.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Service\Endpoint
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 *
 * @spec openspec/changes/archive/2026-11-25-gateway-mcp-proxy/specs/endpoint-runtime/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Service\Endpoint;

use GuzzleHttp\Psr7\Response as PsrResponse;
use OCA\Integriq\Service\CallService;
use OCA\Integriq\Service\Endpoint\McpProxyCall;
use OCA\Integriq\Service\Endpoint\McpProxyHandler;
use OCA\Integriq\Service\Endpoint\McpProxyResponse;
use OCA\Integriq\Service\Endpoint\McpRelayStream;
use OCA\Integriq\Service\RateLimit\InboundRateLimitService;
use OCA\Integriq\Service\RateLimit\RateLimitDecision;
use OCA\Integriq\Tests\Helpers\ObjectServiceMockBuilder;
use OCP\AppFramework\Http\IOutput;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Session headers pass, the answer is relayed as it arrives, the log names the tool, a tool keeps its own limit.
 *
 * @spec openspec/changes/archive/2026-11-25-gateway-mcp-proxy/specs/endpoint-runtime/spec.md
 */
#[CoversClass(McpProxyHandler::class)]
#[CoversClass(McpProxyResponse::class)]
#[UsesClass(McpProxyCall::class)]
#[UsesClass(McpRelayStream::class)]
#[UsesClass(RateLimitDecision::class)]
class McpProxyHandlerTest extends TestCase {

	/**
	 * The relay calls made, as their named arguments.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $relayed = [];

	/**
	 * The call logs saved.
	 *
	 * @var array<int, array{0: array<string, mixed>, 1: string|null}>
	 */
	/**
	 * The limiter keys counted.
	 *
	 * @var array<int, string>
	 */
	private array $limited = [];

	/**
	 * A handler over a tool server that answers the given status, headers and chunks.
	 *
	 * @param int                   $status  The upstream status.
	 * @param array<string, string> $headers The upstream headers.
	 * @param array<int, string>    $chunks  The body, as it arrives.
	 * @param int                   $allowed How many calls the limiter lets through.
	 *
	 * @return McpProxyHandler The handler.
	 */
	private function handler(int $status = 200, array $headers = [], array $chunks = [], int $allowed = PHP_INT_MAX): McpProxyHandler {
		$calls = $this->createMock(CallService::class);
		$calls->method('relay')->willReturnCallback(
			function ($source, string $endpoint, string $method, array $config, $sink, callable $onHeaders, bool $withholdBodies = true) use ($status, $headers, $chunks) {
				$this->relayed[] = compact('source', 'endpoint', 'method', 'config', 'withholdBodies');
				$onHeaders(new PsrResponse($status, $headers));
				foreach ($chunks as $chunk) {
					$sink->write($chunk);
				}

				return ObjectServiceMockBuilder::objectEntity($this, ['statusCode' => $status, 'endpoint' => 'ep-1'], 'log-1');
			}
		);

		$limits = $this->createMock(InboundRateLimitService::class);
		$limits->method('enforce')->willReturnCallback(
			function (string $consumerKey) use ($allowed): RateLimitDecision {
				$this->limited[] = $consumerKey;
				$count = count(array_filter($this->limited, static fn (string $key): bool => $key === $consumerKey));
				return new RateLimitDecision(allowed: $count <= $allowed, hasRateLimit: true, limit: $allowed, remaining: max(0, $allowed - $count), retryAfter: 60);
			}
		);

		return new McpProxyHandler(calls: $calls, limits: $limits, logger: $this->createMock(originalClassName: LoggerInterface::class));
	}//end handler()

	/**
	 * A call of an mcp endpoint.
	 *
	 * @param string                $body          The JSON-RPC body.
	 * @param array<string, string> $headers       The request headers, by lower-case name.
	 * @param array<string, mixed>  $endpointExtra Endpoint fields over the defaults.
	 * @param string                $consumerKey   The consumer.
	 *
	 * @return McpProxyCall The call.
	 */
	private function call(string $body, array $headers = [], array $endpointExtra = [], string $consumerKey = 'consumer:c1'): McpProxyCall {
		$request = $this->createMock(IRequest::class);
		$request->method('getMethod')->willReturn('POST');
		$request->method('getHeader')->willReturnCallback(static fn (string $name): string => ($headers[strtolower($name)] ?? ''));

		return new McpProxyCall(
			endpoint: ObjectServiceMockBuilder::objectEntity($this, ['targetType' => 'mcp', 'endpoint' => '/mcp'] + $endpointExtra, 'ep-1'),
			request: $request,
			source: ObjectServiceMockBuilder::objectEntity($this, ['location' => 'https://tools.internal'], 'src-1'),
			upstreamPath: '/mcp',
			rawBody: $body,
			consumerKey: $consumerKey
		);
	}//end call()

	/**
	 * An output that records what it was given.
	 *
	 * @param array<int, string> $log Filled with "status 200", "header X: y" and "body ..." lines.
	 *
	 * @return IOutput The output.
	 */
	private function recordingOutput(array &$log): IOutput {
		$output = $this->createMock(IOutput::class);
		$output->method('setHttpResponseCode')->willReturnCallback(static function (int $code) use (&$log): void {
			$log[] = 'status ' . $code;
		});
		$output->method('setHeader')->willReturnCallback(static function (string $header) use (&$log): void {
			$log[] = 'header ' . $header;
		});
		$output->method('setOutput')->willReturnCallback(static function (string $out) use (&$log): void {
			$log[] = 'body ' . $out;
		});
		return $output;
	}//end output()

	/**
	 * The JSON-RPC method, tool and id are read from a single message and the first of a batch.
	 *
	 * @return void
	 */
	public function testTheMethodAndToolAreReadFromTheBody(): void {
		$this->assertSame(
			['method' => 'tools/call', 'tool' => 'search', 'id' => 7],
			McpProxyHandler::rpcOf(rawBody: '{"jsonrpc":"2.0","id":7,"method":"tools/call","params":{"name":"search","arguments":{"q":"x"}}}')
		);
		$this->assertSame(['method' => 'initialize', 'tool' => null, 'id' => 1], McpProxyHandler::rpcOf(rawBody: '[{"jsonrpc":"2.0","id":1,"method":"initialize"}]'));
		$this->assertSame(['method' => null, 'tool' => null, 'id' => null], McpProxyHandler::rpcOf(rawBody: 'not json'));
		$this->assertSame(['method' => null, 'tool' => null, 'id' => null], McpProxyHandler::rpcOf(rawBody: ''));
	}//end testTheMethodAndToolAreReadFromTheBody()

	/**
	 * The session headers pass; the consumer's own key and cookies never do.
	 *
	 * @return void
	 */
	public function testOnlyTheSessionHeadersAreForwarded(): void {
		$request = $this->createMock(IRequest::class);
		$headers = [
			'mcp-session-id' => 'sess-42',
			'mcp-protocol-version' => '2025-06-18',
			'accept' => 'application/json, text/event-stream',
			'content-type' => 'application/json',
			'authorization' => 'Bearer consumer-key',
			'cookie' => 'nc_session=1',
		];
		$request->method('getHeader')->willReturnCallback(static fn (string $name): string => ($headers[strtolower($name)] ?? ''));

		$this->assertSame(
			[
				'accept' => 'application/json, text/event-stream',
				'content-type' => 'application/json',
				'mcp-session-id' => 'sess-42',
				'mcp-protocol-version' => '2025-06-18',
			],
			McpProxyHandler::forwardedHeaders(request: $request)
		);
	}//end testOnlyTheSessionHeadersAreForwarded()

	/**
	 * Nothing reaches the tool server until Nextcloud sends the answer; then the call carries the session.
	 *
	 * @return void
	 */
	public function testTheCallCarriesTheSessionAndTheUpstreamSessionComesBack(): void {
		$handler = $this->handler(status: 200, headers: ['Content-Type' => 'application/json', 'Mcp-Session-Id' => 'sess-42'], chunks: ['{"jsonrpc":"2.0","id":2,"result":{}}']);
		$response = $handler->handle(call: $this->call(body: '{"jsonrpc":"2.0","id":2,"method":"tools/list"}', headers: ['mcp-session-id' => 'sess-42', 'authorization' => 'Bearer k']));

		$this->assertInstanceOf(McpProxyResponse::class, $response);
		$this->assertSame(502, $response->getStatus(), 'Until the upstream answers, the answer is a bad gateway.');
		$this->assertSame([], $this->relayed);

		$log = [];
		$response->callback($this->recordingOutput(log: $log));

		$this->assertCount(1, $this->relayed);
		$this->assertSame('/mcp', $this->relayed[0]['endpoint']);
		$this->assertSame('POST', $this->relayed[0]['method']);
		$this->assertSame(['mcp-session-id' => 'sess-42'], $this->relayed[0]['config']['headers']);
		$this->assertSame('{"jsonrpc":"2.0","id":2,"method":"tools/list"}', $this->relayed[0]['config']['body']);
		$this->assertSame(60, $this->relayed[0]['config']['timeout']);
		$this->assertTrue($this->relayed[0]['withholdBodies'], 'Tool arguments are not stored by default.');
		$this->assertSame(
			[
				'status 200',
				'header HTTP/1.1 200 OK',
				'header Content-Type: application/json',
				'header Mcp-Session-Id: sess-42',
				'body {"jsonrpc":"2.0","id":2,"result":{}}',
			],
			$log
		);
	}//end testTheCallCarriesTheSessionAndTheUpstreamSessionComesBack()

	/**
	 * An event stream is relayed event by event, unbuffered, within the endpoint's maximum duration.
	 *
	 * @return void
	 */
	public function testAnEventStreamIsRelayedAsItArrives(): void {
		$events = ["event: message\ndata: {\"n\":1}\n\n", "event: message\ndata: {\"n\":2}\n\n", "event: message\ndata: {\"n\":3}\n\n"];
		$handler = $this->handler(status: 200, headers: ['Content-Type' => 'text/event-stream'], chunks: $events);

		$log = [];
		$handler->handle(call: $this->call(body: '{"jsonrpc":"2.0","id":3,"method":"tools/call","params":{"name":"search"}}', endpointExtra: ['mcpMaxStreamSeconds' => 15]))
			->callback($this->recordingOutput(log: $log));

		$this->assertSame(15, $this->relayed[0]['config']['timeout']);
		$this->assertSame(
			[
				'status 200',
				'header HTTP/1.1 200 OK',
				'header Content-Type: text/event-stream',
				'header X-Accel-Buffering: no',
				'body ' . $events[0],
				'body ' . $events[1],
				'body ' . $events[2],
			],
			$log
		);
	}//end testAnEventStreamIsRelayedAsItArrives()

	/**
	 * The call log is written naming the method and, for tools/call, the tool; without its arguments.
	 *
	 * The names go with the call, so the record is written with them: call_log
	 * is append-only and a saved record cannot be stamped afterwards.
	 *
	 * @return void
	 */
	public function testTheCallLogNamesTheMethodAndTheTool(): void {
		$handler = $this->handler(chunks: ['{}']);
		$log = [];
		$handler->handle(call: $this->call(body: '{"jsonrpc":"2.0","id":4,"method":"tools/call","params":{"name":"fetch","arguments":{"bsn":"999999990"}}}'))
			->callback($this->recordingOutput(log: $log));

		$this->assertCount(expectedCount: 1, haystack: $this->relayed);
		$this->assertSame(expected: ['mcpMethod' => 'tools/call', 'mcpTool' => 'fetch'], actual: $this->relayed[0]['config']['logFields']);
		$this->assertStringNotContainsString(needle: '999999990', haystack: (string)json_encode($this->relayed[0]['config']['logFields']));
		$this->assertTrue(condition: $this->relayed[0]['withholdBodies'], message: 'The arguments are not stored.');

		$initialize = McpProxyHandler::rpcOf(rawBody: '{"jsonrpc":"2.0","id":1,"method":"initialize"}');
		$this->assertSame(expected: ['mcpMethod' => 'initialize'], actual: McpProxyHandler::logFields(rpc: $initialize));
	}//end testTheCallLogNamesTheMethodAndTheTool()

	/**
	 * The upstream status replaces the 502 Nextcloud sent before the relay ran.
	 *
	 * Nextcloud sends a response's status as a raw status line, which PHP keeps
	 * over a later http_response_code(); the relay sends a status line of its own.
	 *
	 * @return void
	 */
	public function testTheUpstreamStatusIsSentAsAStatusLine(): void {
		$this->assertSame(expected: 'HTTP/1.1 200 OK', actual: McpProxyHandler::statusLine(upstream: new PsrResponse(200)));
		$this->assertSame(expected: 'HTTP/1.1 202 Accepted', actual: McpProxyHandler::statusLine(upstream: new PsrResponse(202)));
		$this->assertSame(expected: 'HTTP/1.1 404 Not Found', actual: McpProxyHandler::statusLine(upstream: new PsrResponse(404)));

		$log = [];
		$this->handler(status: 401, chunks: ['{}'])->handle(call: $this->call(body: '{"jsonrpc":"2.0","id":5,"method":"tools/list"}'))
			->callback($this->recordingOutput(log: $log));
		$this->assertSame(expected: ['status 401', 'header HTTP/1.1 401 Unauthorized'], actual: array_slice($log, 0, 2));
	}//end testTheUpstreamStatusIsSentAsAStatusLine()

	/**
	 * An endpoint that stores arguments says so to the call; a body that is not JSON-RPC is not stamped.
	 *
	 * @return void
	 */
	public function testStoringArgumentsIsTheEndpointsChoice(): void {
		$log = [];
		$this->handler(chunks: ['{}'])->handle(call: $this->call(body: 'plain', endpointExtra: ['mcpStoreArguments' => true]))->callback($this->recordingOutput(log: $log));

		$this->assertFalse($this->relayed[0]['withholdBodies']);
		$this->assertArrayNotHasKey(key: 'logFields', array: $this->relayed[0]['config'], message: 'A body that is not JSON-RPC names nothing.');
	}//end testStoringArgumentsIsTheEndpointsChoice()

	/**
	 * The eleventh call of a tool limited to ten gets 429 and a JSON-RPC error naming it; another tool passes.
	 *
	 * @return void
	 */
	public function testAToolOverItsOwnLimitIsRefused(): void {
		$handler = $this->handler(allowed: 10);
		$limits = ['mcpToolLimits' => ['search' => ['limit' => 10, 'window' => 60]]];
		$search = '{"jsonrpc":"2.0","id":11,"method":"tools/call","params":{"name":"search"}}';

		for ($call = 1; $call <= 10; $call++) {
			$this->assertInstanceOf(McpProxyResponse::class, $handler->handle(call: $this->call(body: $search, endpointExtra: $limits)));
		}

		$refused = $handler->handle(call: $this->call(body: $search, endpointExtra: $limits));
		$this->assertInstanceOf(JSONResponse::class, $refused);
		$this->assertSame(429, $refused->getStatus());
		$this->assertSame(
			['jsonrpc' => '2.0', 'id' => 11, 'error' => ['code' => -32000, 'message' => 'rate limit reached for tool search']],
			$refused->getData()
		);
		$this->assertSame('mcp-tool:ep-1:consumer:c1:search', $this->limited[0]);

		$fetch = $handler->handle(call: $this->call(body: '{"jsonrpc":"2.0","id":12,"method":"tools/call","params":{"name":"fetch"}}', endpointExtra: $limits));
		$this->assertInstanceOf(McpProxyResponse::class, $fetch);
		$this->assertCount(11, $this->limited, 'A tool without a limit is not counted.');
	}//end testAToolOverItsOwnLimitIsRefused()

	/**
	 * The limit counts per consumer.
	 *
	 * @return void
	 */
	public function testTheLimitCountsPerConsumer(): void {
		$handler = $this->handler(allowed: 1);
		$limits = ['mcpToolLimits' => ['search' => ['limit' => 1, 'window' => 60]]];
		$search = '{"jsonrpc":"2.0","id":1,"method":"tools/call","params":{"name":"search"}}';

		$this->assertInstanceOf(McpProxyResponse::class, $handler->handle(call: $this->call(body: $search, endpointExtra: $limits, consumerKey: 'consumer:a')));
		$this->assertInstanceOf(McpProxyResponse::class, $handler->handle(call: $this->call(body: $search, endpointExtra: $limits, consumerKey: 'consumer:b')));
		$this->assertInstanceOf(JSONResponse::class, $handler->handle(call: $this->call(body: $search, endpointExtra: $limits, consumerKey: 'consumer:a')));
	}//end testTheLimitCountsPerConsumer()

	/**
	 * The maximum duration falls back to sixty seconds.
	 *
	 * @return void
	 */
	public function testTheMaximumDurationDefaultsToSixtySeconds(): void {
		$this->assertSame(60, McpProxyHandler::maxStreamSeconds(endpointData: []));
		$this->assertSame(60, McpProxyHandler::maxStreamSeconds(endpointData: ['mcpMaxStreamSeconds' => 0]));
		$this->assertSame(300, McpProxyHandler::maxStreamSeconds(endpointData: ['mcpMaxStreamSeconds' => 300]));
	}//end testTheMaximumDurationDefaultsToSixtySeconds()
	/**
	 * The path joins the source location with exactly one slash, so `mcp/example` on
	 * `https://tools.example` reaches `https://tools.example/mcp/example`.
	 *
	 * @return void
	 */
	public function testTheUpstreamPathJoinsTheLocationWithOneSlash(): void {
		$this->assertSame(expected: '/mcp/example', actual: McpProxyHandler::upstreamPathFor(location: 'https://tools.example', path: 'mcp/example'));
		$this->assertSame(expected: '/mcp/example', actual: McpProxyHandler::upstreamPathFor(location: 'https://tools.example', path: '/mcp/example'));
		$this->assertSame(expected: 'mcp/example', actual: McpProxyHandler::upstreamPathFor(location: 'https://tools.example/', path: '/mcp/example'));
		$this->assertSame(expected: '', actual: McpProxyHandler::upstreamPathFor(location: 'https://tools.example/mcp', path: ''));
		$this->assertSame(expected: '?session=1', actual: McpProxyHandler::upstreamPathFor(location: 'https://tools.example/mcp', path: '?session=1'));
	}//end testTheUpstreamPathJoinsTheLocationWithOneSlash()
}//end class

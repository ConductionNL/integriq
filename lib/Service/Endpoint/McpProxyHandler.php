<?php

/**
 * Integriq McpProxyHandler.
 *
 * Puts an outside MCP tool server (streamable HTTP) behind an endpoint of
 * `targetType` `mcp` (gateway-mcp-proxy). The endpoint's consumer
 * authentication, rate limit and quota have run before this is reached; this
 * adds the per-tool limit, forwards the call with its session headers and
 * relays the answer, an event stream included, as it arrives. The call log
 * names the JSON-RPC method and the tool.
 *
 * @category Service
 * @package  OCA\Integriq\Service\Endpoint
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://www.integriq.nl
 *
 * @spec openspec/changes/gateway-mcp-proxy/specs/endpoint-runtime/spec.md
 */

declare(strict_types=1);

namespace OCA\Integriq\Service\Endpoint;

use OCA\Integriq\Service\CallService;
use OCA\Integriq\Service\RateLimit\InboundRateLimitService;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\IOutput;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Http\Response;
use OCP\IRequest;
use Psr\Http\Message\ResponseInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * The `mcp` target type of an endpoint.
 *
 * @spec openspec/changes/gateway-mcp-proxy/specs/endpoint-runtime/spec.md
 *
 * It wires the endpoint, the consumer check, the upstream call and the relay
 * together, so it depends on each of them.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 */
class McpProxyHandler {

	/**
	 * The endpoint target type this handler serves.
	 *
	 * @var string
	 */
	public const TARGET_TYPE = 'mcp';

	/**
	 * The request headers forwarded to the tool server. The consumer's own
	 * `Authorization` and cookies are never among them: the source's
	 * credential is used, as for an `api` endpoint (design D2).
	 *
	 * @var array<int, string>
	 */
	public const FORWARDED_HEADERS = ['accept', 'content-type', 'mcp-session-id', 'mcp-protocol-version', 'last-event-id'];

	/**
	 * The response headers relayed back to the caller.
	 *
	 * @var array<int, string>
	 */
	public const RELAYED_HEADERS = ['content-type', 'mcp-session-id', 'mcp-protocol-version', 'cache-control'];

	/**
	 * How long a relayed answer may last when the endpoint sets nothing, in seconds.
	 *
	 * @var int
	 */
	public const DEFAULT_MAX_STREAM_SECONDS = 60;

	/**
	 * The JSON-RPC error code of a refused tool call (design D4).
	 *
	 * @var int
	 */
	public const RATE_LIMIT_ERROR = -32000;

	/**
	 * Constructor.
	 *
	 * @param CallService             $calls      The outbound path, relaying the answer.
	 * @param InboundRateLimitService $limits     The limiter the consumer rate limit uses.
	 * @param LoggerInterface         $logger     The logger.
	 */
	public function __construct(
		private readonly CallService $calls,
		private readonly InboundRateLimitService $limits,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * The JSON-RPC method, the tool of a `tools/call` and the id of a request body.
	 *
	 * A batch is read by its first message. A body that is not JSON-RPC names nothing.
	 *
	 * @param string $rawBody The request body.
	 *
	 * @return array{method: string|null, tool: string|null, id: mixed} What the body asks.
	 *
	 * @spec openspec/changes/gateway-mcp-proxy/specs/endpoint-runtime/spec.md#requirement-the-call-log-names-the-mcp-tool-req-mcpx-002
	 */
	public static function rpcOf(string $rawBody): array {
		$message = json_decode($rawBody, true);
		if (is_array($message) === true && array_is_list($message) === true) {
			$message = ($message[0] ?? null);
		}

		if (is_array($message) === false || is_string($message['method'] ?? null) === false) {
			return ['method' => null, 'tool' => null, 'id' => null];
		}

		$tool = null;
		if ($message['method'] === 'tools/call' && is_string($message['params']['name'] ?? null) === true) {
			$tool = $message['params']['name'];
		}

		return ['method' => $message['method'], 'tool' => $tool, 'id' => ($message['id'] ?? null)];
	}//end rpcOf()

	/**
	 * The request headers to forward, by lower-case name.
	 *
	 * @param IRequest $request The inbound request.
	 *
	 * @return array<string, string> The headers.
	 *
	 * @spec openspec/changes/gateway-mcp-proxy/specs/endpoint-runtime/spec.md#requirement-an-outside-mcp-server-is-published-as-a-gateway-endpoint-req-mcpx-001
	 */
	public static function forwardedHeaders(IRequest $request): array {
		$headers = [];
		foreach (self::FORWARDED_HEADERS as $name) {
			$value = trim($request->getHeader($name));
			if ($value !== '') {
				$headers[$name] = $value;
			}
		}

		return $headers;
	}//end forwardedHeaders()

	/**
	 * The refusal of a tool call over its own limit, or null when it may pass.
	 *
	 * The endpoint's `mcpToolLimits` maps a tool name to `{limit, window}`
	 * (calls per window seconds). It is counted per consumer and tool with the
	 * limiter the consumer rate limit uses, after the consumer's own limits.
	 *
	 * @param array<string, mixed>                                $endpointData The endpoint.
	 * @param string                                              $endpointId   The endpoint's uuid.
	 * @param string                                              $consumerKey  The consumer, or the caller's address.
	 * @param array{method: string|null, tool: string|null, id: mixed} $rpc     What the body asks.
	 *
	 * @return JSONResponse|null A 429 with a JSON-RPC error naming the tool, or null.
	 *
	 * @spec openspec/changes/gateway-mcp-proxy/specs/endpoint-runtime/spec.md#requirement-a-tool-can-have-its-own-rate-limit-req-mcpx-003
	 */
	public function toolLimitRefusal(array $endpointData, string $endpointId, string $consumerKey, array $rpc): ?JSONResponse {
		$tool = $rpc['tool'];
		if ($tool === null) {
			return null;
		}

		$limit = ($endpointData['mcpToolLimits'][$tool] ?? null);
		if (is_array($limit) === false || (int)($limit['limit'] ?? 0) <= 0 || (int)($limit['window'] ?? 0) <= 0) {
			return null;
		}

		$decision = $this->limits->enforce(
			consumerKey: 'mcp-tool:' . $endpointId . ':' . $consumerKey . ':' . $tool,
			rateLimit: ['requestsPerWindow' => (int)$limit['limit'], 'windowSeconds' => (int)$limit['window']],
			quota: null
		);
		if ($decision->allowed === true) {
			return null;
		}

		return new JSONResponse(
			[
				'jsonrpc' => '2.0',
				'id' => $rpc['id'],
				'error' => ['code' => self::RATE_LIMIT_ERROR, 'message' => 'rate limit reached for tool ' . $tool],
			],
			Http::STATUS_TOO_MANY_REQUESTS,
			$decision->toHeaders()
		);
	}//end toolLimitRefusal()

	/**
	 * Forward the call to the tool server and relay its answer as it arrives.
	 *
	 * @param McpProxyCall $call The call.
	 *
	 * @return Response The relaying answer, or the refusal of a tool over its limit.
	 *
	 * @spec openspec/changes/gateway-mcp-proxy/specs/endpoint-runtime/spec.md#requirement-an-outside-mcp-server-is-published-as-a-gateway-endpoint-req-mcpx-001
	 */
	public function handle(McpProxyCall $call): Response {
		$endpointData = $call->endpoint->getObject();
		$rpc = self::rpcOf(rawBody: $call->rawBody);
		$refusal = $this->toolLimitRefusal(
			endpointData: $endpointData,
			endpointId: (string)$call->endpoint->getUuid(),
			consumerKey: $call->consumerKey,
			rpc: $rpc
		);
		if ($refusal !== null) {
			return $refusal;
		}

		$config = [
			'headers' => self::forwardedHeaders(request: $call->request),
			'timeout' => self::maxStreamSeconds(endpointData: $endpointData),
		];
		if ($call->rawBody !== '') {
			$config['body'] = $call->rawBody;
		}

		// Written with the call log record, which is append-only (REQ-MCPX-002).
		$logFields = self::logFields(rpc: $rpc);
		if ($logFields !== []) {
			$config['logFields'] = $logFields;
		}

		return new McpProxyResponse(
			relay: function (IOutput $output) use ($call, $config, $rpc, $endpointData): void {
				$this->relay(call: $call, config: $config, output: $output, storeArguments: (($endpointData['mcpStoreArguments'] ?? false) === true), rpc: $rpc);
			}
		);
	}//end handle()

	/**
	 * Relay the upstream's status and headers to the caller's output.
	 *
	 * @param ResponseInterface $upstream The upstream response, headers only.
	 * @param IOutput           $output   The caller's output.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/gateway-mcp-proxy/specs/endpoint-runtime/spec.md#requirement-an-outside-mcp-server-is-published-as-a-gateway-endpoint-req-mcpx-001
	 */
	public static function relayHeaders(ResponseInterface $upstream, IOutput $output): void {
		$output->setHttpResponseCode($upstream->getStatusCode());
		// Nextcloud sent this response's own status (502) as a raw status line
		// before the callback ran, and PHP keeps a raw status line over a later
		// http_response_code(); only a new status line replaces it.
		$output->setHeader(self::statusLine(upstream: $upstream));
		foreach (self::RELAYED_HEADERS as $name) {
			$value = $upstream->getHeaderLine($name);
			if ($value !== '') {
				$output->setHeader(self::headerName(name: $name) . ': ' . str_replace(["\r", "\n"], '', $value));
			}
		}

		if (stripos($upstream->getHeaderLine('content-type'), 'text/event-stream') !== false) {
			// A proxy in front of Nextcloud must not hold the events back.
			$output->setHeader('X-Accel-Buffering: no');
		}
	}//end relayHeaders()

	/**
	 * The longest an answer is relayed, in seconds: the endpoint's `mcpMaxStreamSeconds`, else 60.
	 *
	 * @param array<string, mixed> $endpointData The endpoint.
	 *
	 * @return int The seconds.
	 *
	 * @spec openspec/changes/gateway-mcp-proxy/specs/endpoint-runtime/spec.md#requirement-an-outside-mcp-server-is-published-as-a-gateway-endpoint-req-mcpx-001
	 */
	public static function maxStreamSeconds(array $endpointData): int {
		$seconds = (int)($endpointData['mcpMaxStreamSeconds'] ?? 0);
		if ($seconds <= 0) {
			return self::DEFAULT_MAX_STREAM_SECONDS;
		}

		return $seconds;
	}//end maxStreamSeconds()

	/**
	 * Make the call through CallService, relaying into the output; the call log names the method and tool.
	 *
	 * @param McpProxyCall                                             $call           The call.
	 * @param array<string, mixed>                                     $config         The request configuration.
	 * @param IOutput                                                  $output         The caller's output.
	 * @param bool                                                     $storeArguments Whether the request body is logged.
	 * @param array{method: string|null, tool: string|null, id: mixed} $rpc            What the body asks.
	 *
	 * @return void
	 */
	private function relay(McpProxyCall $call, array $config, IOutput $output, bool $storeArguments, array $rpc): void {
		try {
			$this->calls->relay(
				source: $call->source,
				endpoint: $call->upstreamPath,
				method: $call->request->getMethod(),
				config: $config,
				sink: new McpRelayStream(output: $output),
				onHeaders: static function (ResponseInterface $upstream) use ($output): void {
					self::relayHeaders(upstream: $upstream, output: $output);
				},
				withholdBodies: ($storeArguments === false)
			);
		} catch (Throwable $e) {
			$this->logger->warning('integriq: the MCP relay failed: ' . $e->getMessage());
		}
	}//end relay()

	/**
	 * The JSON-RPC method and the tool a call log names, so the log filters on them.
	 *
	 * They are written with the record itself: `call_log` is append-only, so a
	 * record cannot be stamped after it was saved.
	 *
	 * @param array{method: string|null, tool: string|null, id: mixed} $rpc What the body asked.
	 *
	 * @return array<string, string> `mcpMethod` and, for `tools/call`, `mcpTool`; nothing for a body that is not JSON-RPC.
	 *
	 * @spec openspec/changes/gateway-mcp-proxy/specs/endpoint-runtime/spec.md#requirement-the-call-log-names-the-mcp-tool-req-mcpx-002
	 */
	public static function logFields(array $rpc): array {
		if ($rpc['method'] === null) {
			return [];
		}

		$fields = ['mcpMethod' => $rpc['method']];
		if ($rpc['tool'] !== null) {
			$fields['mcpTool'] = $rpc['tool'];
		}

		return $fields;
	}//end logFields()

	/**
	 * The HTTP status line of the upstream answer.
	 *
	 * Only the code and phrase count: PHP takes the status from the line and
	 * the web server speaks its own protocol version to the caller.
	 *
	 * @param ResponseInterface $upstream The upstream response.
	 *
	 * @return string The status line, such as `HTTP/1.1 200 OK`.
	 *
	 * @spec openspec/changes/gateway-mcp-proxy/specs/endpoint-runtime/spec.md#requirement-an-outside-mcp-server-is-published-as-a-gateway-endpoint-req-mcpx-001
	 */
	public static function statusLine(ResponseInterface $upstream): string {
		return trim('HTTP/1.1 ' . $upstream->getStatusCode() . ' ' . str_replace(["\r", "\n"], '', $upstream->getReasonPhrase()));
	}//end statusLine()

	/**
	 * A header name in its usual spelling.
	 *
	 * @param string $name The lower-case name.
	 *
	 * @return string The name.
	 */
	private static function headerName(string $name): string {
		return [
			'mcp-session-id' => 'Mcp-Session-Id',
			'mcp-protocol-version' => 'MCP-Protocol-Version',
		][$name] ?? implode('-', array_map('ucfirst', explode('-', $name)));
	}//end headerName()
}//end class

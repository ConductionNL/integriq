<?php

/**
 * Unit tests for CallService::relay(), the streamed path of the MCP gateway.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 *
 * @spec openspec/changes/gateway-mcp-proxy/specs/endpoint-runtime/spec.md#requirement-an-outside-mcp-server-is-published-as-a-gateway-endpoint-req-mcpx-001
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Service;

use DateTimeImmutable;
use GuzzleHttp\Client;
use GuzzleHttp\Psr7\Response;
use OCA\Integriq\Outbound\Call\BodyCapturePolicy;
use OCA\Integriq\Service\AuthenticationService;
use OCA\Integriq\Service\BrokeredCallService;
use OCA\Integriq\Service\CallService;
use OCA\Integriq\Service\Endpoint\McpRelayStream;
use OCA\Integriq\Service\Security\SensitiveFieldRegistry;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectSaveOptions;
use OCA\OpenRegister\Service\ObjectService;
use OCP\AppFramework\Http\IOutput;
use OCP\IAppConfig;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use ReflectionProperty;
use Twig\Loader\ArrayLoader;

/**
 * The relay hands the client a sink and a headers callback, and logs the call without bodies.
 *
 * @spec openspec/changes/gateway-mcp-proxy/specs/endpoint-runtime/spec.md#requirement-an-outside-mcp-server-is-published-as-a-gateway-endpoint-req-mcpx-001
 */
class CallServiceRelayTest extends TestCase {

	/**
	 * The Guzzle options of each request.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $options = [];

	/**
	 * The objects saved, by schema.
	 *
	 * @var array<int, array{object: array<string, mixed>, schema: string|null}>
	 */
	private array $saved = [];

	/**
	 * A service whose HTTP client answers the given response and records its options.
	 *
	 * @param Response $answer The answer.
	 *
	 * @return CallService The service.
	 */
	private function service(Response $answer): CallService {
		$objectService = $this->createMock(ObjectService::class);
		$objectService->method('saveObject')->willReturnCallback(
			function ($object = [], ObjectSaveOptions $options = new ObjectSaveOptions()) {
				$this->saved[] = ['object' => $object, 'schema' => $options->target->schema];
				$entity = new ObjectEntity();
				$entity->setUuid('saved-' . count($this->saved));
				$entity->setObject($object);
				return $entity;
			}
		);
		$objectService->method('saveObjects')->willReturn([]);

		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('hasKey')->willReturn(false);
		$appConfig->method('getValueInt')->willReturnArgument(2);
		$clock = $this->createMock(ClockInterface::class);
		$clock->method('now')->willReturn(new DateTimeImmutable('2026-11-17T12:00:00+00:00'));
		$brokered = $this->createMock(BrokeredCallService::class);
		$brokered->method('hasCredentialRef')->willReturn(false);

		$service = new CallService(
			$objectService,
			new ArrayLoader([]),
			$this->createMock(AuthenticationService::class),
			$appConfig,
			$this->createMock(LoggerInterface::class),
			$brokered,
			new SensitiveFieldRegistry(),
			new BodyCapturePolicy($appConfig, $clock, $this->createMock(IUserSession::class)),
		);

		$client = $this->createMock(Client::class);
		$client->method('request')->willReturnCallback(
			function (string $method, string $url, array $options) use ($answer): Response {
				$this->options[] = $options;
				$options['on_headers']($answer);
				$options['sink']->write('data: {"n":1}' . "\n\n");
				return $answer;
			}
		);
		(new ReflectionProperty(CallService::class, 'client'))->setValue($service, $client);

		return $service;
	}//end service()

	/**
	 * The tool server's source.
	 *
	 * @return ObjectEntity The source.
	 */
	private function source(): ObjectEntity {
		$source = new ObjectEntity();
		$source->setUuid('src-mcp');
		$source->setObject(['name' => 'tools', 'isEnabled' => true, 'location' => 'https://tools.example.invalid', 'configuration' => []]);
		return $source;
	}//end source()

	/**
	 * The client gets the sink and the headers callback; the call log holds no body.
	 *
	 * @return void
	 */
	public function testTheRelayStreamsIntoTheSinkAndLogsNoBody(): void {
		$service = $this->service(new Response(200, ['Content-Type' => 'text/event-stream', 'Mcp-Session-Id' => 'sess-1']));
		$written = [];
		$output = $this->createMock(IOutput::class);
		$output->method('setOutput')->willReturnCallback(static function (string $chunk) use (&$written): void {
			$written[] = $chunk;
		});
		$sink = new McpRelayStream(output: $output, flush: static function (): void {
		});
		$headersSeen = [];

		$log = $service->relay(
			source: $this->source(),
			endpoint: '/mcp',
			method: 'POST',
			config: ['headers' => ['mcp-session-id' => 'sess-1'], 'body' => '{"jsonrpc":"2.0","id":1,"method":"tools/list"}', 'timeout' => 60],
			sink: $sink,
			onHeaders: static function ($upstream) use (&$headersSeen): void {
				$headersSeen[] = $upstream->getHeaderLine('Mcp-Session-Id');
			}
		);

		$this->assertCount(1, $this->options);
		$this->assertSame($sink, $this->options[0]['sink']);
		$this->assertSame(60, $this->options[0]['timeout']);
		$this->assertSame('sess-1', $this->options[0]['headers']['mcp-session-id']);
		$this->assertSame(['sess-1'], $headersSeen);
		$this->assertSame(['data: {"n":1}' . "\n\n"], $written);

		$callLogs = array_values(array_filter($this->saved, static fn (array $row): bool => $row['schema'] === 'call_log'));
		$this->assertCount(1, $callLogs);
		$this->assertStringNotContainsString('tools/list', (string)json_encode($callLogs[0]['object']), 'Withheld by default: no tool arguments in the log.');
		$this->assertSame(200, $callLogs[0]['object']['statusCode']);
		$this->assertInstanceOf(ObjectEntity::class, $log);
	}//end testTheRelayStreamsIntoTheSinkAndLogsNoBody()
	/**
	 * Fields the caller names go on the call log record when it is written (the call log is
	 * append-only); they never replace the engine's own.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/gateway-mcp-proxy/specs/endpoint-runtime/spec.md#requirement-the-call-log-names-the-mcp-tool-req-mcpx-002
	 */
	public function testTheCallerFieldsAreWrittenOnTheCallLog(): void {
		$service = $this->service(new Response(200, ['Content-Type' => 'application/json']));
		$output = $this->createMock(IOutput::class);
		$sink = new McpRelayStream(output: $output, flush: static function (): void {
		});

		$service->relay(
			source: $this->source(),
			endpoint: '/mcp',
			method: 'POST',
			config: ['body' => '{"jsonrpc":"2.0","id":1,"method":"tools/call","params":{"name":"search"}}'],
			sink: $sink,
			onHeaders: static function (): void {
			},
			logFields: ['mcpMethod' => 'tools/call', 'mcpTool' => 'search', 'statusCode' => 999]
		);

		$callLogs = array_values(array_filter($this->saved, static fn (array $row): bool => $row['schema'] === 'call_log'));
		$this->assertCount(1, $callLogs);
		$this->assertSame('tools/call', $callLogs[0]['object']['mcpMethod']);
		$this->assertSame('search', $callLogs[0]['object']['mcpTool']);
		$this->assertSame(200, $callLogs[0]['object']['statusCode'], 'A caller field never replaces the engine\'s own.');
	}//end testTheCallerFieldsAreWrittenOnTheCallLog()
}//end class

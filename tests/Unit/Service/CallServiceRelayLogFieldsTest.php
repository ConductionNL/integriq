<?php

/**
 * Unit tests: a relayed call's log record is written with the fields its
 * caller names it by, such as the MCP method and tool.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @spec openspec/changes/gateway-mcp-proxy/specs/endpoint-runtime/spec.md#requirement-the-call-log-names-the-mcp-tool-req-mcpx-002
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Service;

use DateTimeImmutable;
use GuzzleHttp\Client;
use GuzzleHttp\Psr7\Response;
use GuzzleHttp\Psr7\Utils;
use OCA\Integriq\Outbound\Call\BodyCapturePolicy;
use OCA\Integriq\Service\AuthenticationService;
use OCA\Integriq\Service\BrokeredCallService;
use OCA\Integriq\Service\CallService;
use OCA\Integriq\Service\Security\SensitiveFieldRegistry;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectSaveOptions;
use OCA\OpenRegister\Service\ObjectService;
use OCP\IAppConfig;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use ReflectionProperty;
use Twig\Loader\ArrayLoader;

/**
 * A CallService over a recording ObjectService and a Guzzle double.
 *
 * call_log is append-only, so a field a caller wants on the record has to be
 * on it when it is first saved: these tests read the one save.
 *
 * @covers \OCA\Integriq\Service\CallService
 * @uses \OCA\Integriq\Service\Call\CallRequest
 * @uses \OCA\Integriq\Service\Call\FinishedCall
 * @uses \OCA\Integriq\Service\SourceAuthApplier
 * @uses \OCA\Integriq\Outbound\Call\BodyCapturePolicy
 * @uses \OCA\Integriq\Service\Security\SensitiveFieldRegistry
 */
class CallServiceRelayLogFieldsTest extends TestCase {

	/**
	 * Single saves handed to ObjectService::saveObject().
	 *
	 * @var array<int,array<string,mixed>>
	 */
	private array $saved = [];

	/**
	 * A CallService whose upstream answers 200 with a JSON-RPC result.
	 *
	 * @return CallService
	 */
	private function service(): CallService {
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

		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('hasKey')->willReturn(false);
		$appConfig->method('getValueInt')->willReturnArgument(2);

		$clock = $this->createMock(ClockInterface::class);
		$clock->method('now')->willReturn(new DateTimeImmutable('2026-11-25T12:00:00+00:00'));

		$brokered = $this->createMock(BrokeredCallService::class);
		$brokered->method('hasCredentialRef')->willReturn(false);

		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn(null);

		$service = new CallService(
			$objectService,
			new ArrayLoader([]),
			$this->createMock(AuthenticationService::class),
			$appConfig,
			$this->createMock(LoggerInterface::class),
			$brokered,
			new SensitiveFieldRegistry(),
			new BodyCapturePolicy($appConfig, $clock, $session),
		);

		$client = $this->createMock(Client::class);
		$client->method('request')->willReturn(new Response(200, ['Content-Type' => 'application/json'], '{"jsonrpc":"2.0","id":1,"result":{}}'));
		$property = new ReflectionProperty(CallService::class, 'client');
		$property->setValue($service, $client);

		return $service;
	}//end service()

	/**
	 * A tool server source.
	 *
	 * @return ObjectEntity
	 */
	private function source(): ObjectEntity {
		$source = new ObjectEntity();
		$source->setUuid('7d1c2b3a-4e5f-4a6b-8c7d-9e0f1a2b3c4d');
		$source->setObject(['name' => 'tools', 'isEnabled' => true, 'location' => 'https://tools.example.invalid/']);

		return $source;
	}//end source()

	/**
	 * The call_log records written.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private function callLogs(): array {
		$logs = [];
		foreach ($this->saved as $row) {
			if ($row['schema'] === 'call_log') {
				$logs[] = $row['object'];
			}
		}

		return $logs;
	}//end callLogs()

	/**
	 * Relay one call with the given log fields.
	 *
	 * @param array<string,scalar> $logFields The fields the record is written with.
	 *
	 * @return void
	 */
	private function relay(array $logFields): void {
		$this->service()->relay(
			source: $this->source(),
			endpoint: 'mcp',
			method: 'POST',
			config: ['body' => '{"jsonrpc":"2.0","id":1,"method":"tools/call","params":{"name":"search","arguments":{"q":"secret"}}}'],
			sink: Utils::streamFor(''),
			onHeaders: static function (): void {
			},
			logFields: $logFields
		);
	}//end relay()

	/**
	 * The record is saved once, already naming the method and the tool, without the arguments.
	 *
	 * @return void
	 */
	public function testTheRecordIsWrittenWithTheCallersFields(): void {
		$this->relay(logFields: ['mcpMethod' => 'tools/call', 'mcpTool' => 'search']);

		$logs = $this->callLogs();
		$this->assertCount(1, $logs, 'One save: an append-only record is never updated.');
		$this->assertSame('tools/call', $logs[0]['mcpMethod']);
		$this->assertSame('search', $logs[0]['mcpTool']);
		$this->assertSame(200, $logs[0]['statusCode']);
		$this->assertStringNotContainsString('secret', (string)json_encode($logs[0]), 'A relay withholds its bodies by default.');
	}//end testTheRecordIsWrittenWithTheCallersFields()

	/**
	 * A caller's field never replaces one the engine sets.
	 *
	 * @return void
	 */
	public function testACallersFieldDoesNotReplaceTheEngines(): void {
		$this->relay(logFields: ['statusCode' => 299, 'direction' => 'inbound', 'mcpMethod' => 'tools/list']);

		$logs = $this->callLogs();
		$this->assertSame(200, $logs[0]['statusCode']);
		$this->assertSame('outbound', $logs[0]['direction']);
		$this->assertSame('tools/list', $logs[0]['mcpMethod']);
	}//end testACallersFieldDoesNotReplaceTheEngines()

	/**
	 * Without fields the record is as before.
	 *
	 * @return void
	 */
	public function testWithoutFieldsTheRecordIsUnchanged(): void {
		$this->relay(logFields: []);

		$logs = $this->callLogs();
		$this->assertCount(1, $logs);
		$this->assertArrayNotHasKey('mcpMethod', $logs[0]);
		$this->assertArrayNotHasKey('mcpTool', $logs[0]);
	}//end testWithoutFieldsTheRecordIsUnchanged()
}//end class

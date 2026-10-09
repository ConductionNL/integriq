<?php

/**
 * Unit tests: CallService stores call bodies only inside an investigation window.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @spec openspec/specs/outbound-call-log/spec.md#requirement-every-outbound-call-is-a-record-with-its-request-and-its-response-req-ocd-001
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
use OCA\Integriq\Service\Security\SensitiveFieldRegistry;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use ReflectionProperty;
use RuntimeException;
use Twig\Loader\ArrayLoader;

/**
 * Body capture on the CallService write path (Woo row 13.23, decision D5).
 */
class CallServiceBodyCaptureTest extends TestCase {

	/**
	 * The fixed moment every test calls at.
	 *
	 * @var string
	 */
	private const NOW = '2026-10-09T12:00:00+00:00';

	/**
	 * Single saves handed to ObjectService::saveObject().
	 *
	 * @var array<int,array<string,mixed>>
	 */
	private array $saved = [];

	/**
	 * Batches handed to ObjectService::saveObjects().
	 *
	 * @var array<int,array<int,array<string,mixed>>>
	 */
	private array $batches = [];

	/**
	 * Build the service with a capturing ObjectService, a fixed clock and the given upstream answer.
	 *
	 * @param Response $answer What the upstream answers.
	 * @param IAppConfig|null $appConfig The app config, a default one when null.
	 *
	 * @return CallService
	 */
	private function service(Response $answer, ?IAppConfig $appConfig = null): CallService {
		$objectService = $this->createMock(ObjectService::class);
		$objectService->method('saveObject')->willReturnCallback(
			function ($object = [], $register = null, $schema = null) {
				$this->saved[] = ['object' => $object, 'schema' => $schema];
				$entity = new ObjectEntity();
				$entity->setUuid('saved-' . count($this->saved));
				$entity->setObject($object);
				return $entity;
			}
		);
		$objectService->method('saveObjects')->willReturnCallback(
			function (array $objects = []) {
				$this->batches[] = $objects;
				return [];
			}
		);

		if ($appConfig === null) {
			$appConfig = $this->createMock(IAppConfig::class);
			$appConfig->method('hasKey')->willReturn(false);
			$appConfig->method('getValueInt')->willReturnArgument(2);
		}

		$clock = $this->createMock(ClockInterface::class);
		$clock->method('now')->willReturn(new DateTimeImmutable(self::NOW));

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
			null,
			new BodyCapturePolicy($appConfig, $clock),
		);

		$client = $this->createMock(Client::class);
		$client->method('request')->willReturn($answer);
		$property = new ReflectionProperty(CallService::class, 'client');
		$property->setValue($service, $client);

		return $service;
	}//end service()

	/**
	 * A source, optionally with an investigation window ending at the given moment.
	 *
	 * @param string|null $windowEnd The window end, ISO 8601, or null for none.
	 *
	 * @return ObjectEntity
	 */
	private function source(?string $windowEnd): ObjectEntity {
		$data = [
			'name' => 'zgw-zaken',
			'isEnabled' => true,
			'location' => 'https://zaken.example.invalid',
			'configuration' => [
				'json' => ['zaaktype' => 'https://catalogi.example.invalid/zaaktypen/1', 'bsn' => '999993653'],
			],
		];
		if ($windowEnd !== null) {
			$data['bodyCaptureUntil'] = $windowEnd;
			$data['bodyCaptureReason'] = 'melding 4711: verkeerde zaaktypen';
			$data['bodyCaptureBy'] = 'admin';
		}

		$source = new ObjectEntity();
		$source->setUuid('7d3f0c1e-5b2a-4c8d-9e6f-0a1b2c3d4e5f');
		$source->setObject($data);

		return $source;
	}//end source()

	/**
	 * The call records handed to the single save.
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
	 * Assert a stored record carries no request or response body.
	 *
	 * @param array<string,mixed> $log The stored record.
	 *
	 * @return void
	 */
	private function assertNoBody(array $log): void {
		foreach (BodyCapturePolicy::REQUEST_BODY_KEYS as $key) {
			$this->assertArrayNotHasKey($key, $log['request'], 'request.' . $key);
		}

		$this->assertArrayNotHasKey('body', $log['response']);
		$this->assertFalse($log['bodyCaptured']);
	}//end assertNoBody()

	/**
	 * A 500 from a source without a window stores neither body; the rest stays.
	 *
	 * @return void
	 */
	public function testAFailureOutsideAWindowStoresNoBody(): void {
		$service = $this->service(new Response(500, ['Content-Type' => 'text/plain'], 'zaaktype onbekend voor 999993653'));

		$entity = $service->call(source: $this->source(null), endpoint: '/zaken', method: 'POST');

		$logs = $this->callLogs();
		$this->assertCount(1, $logs);
		$this->assertNoBody($logs[0]);
		$this->assertSame(500, $logs[0]['statusCode']);
		$this->assertSame('POST', $logs[0]['request']['method']);
		$this->assertArrayHasKey('responseTime', $logs[0]['response']);
		$this->assertArrayHasKey('headers', $logs[0]['response']);
		$this->assertStringNotContainsString('999993653', json_encode([$logs[0]['request'], $logs[0]['response']]));

		// The caller still gets the full answer to process.
		$this->assertSame('zaaktype onbekend voor 999993653', $entity->getObject()['response']['body']);
	}//end testAFailureOutsideAWindowStoresNoBody()

	/**
	 * A 200 from a source without a window stores no request body either.
	 *
	 * @return void
	 */
	public function testASuccessOutsideAWindowStoresNoBody(): void {
		$service = $this->service(new Response(200, [], '{"uuid":"z1"}'));

		$service->call(source: $this->source(null), endpoint: '/zaken', method: 'POST');

		$logs = $this->callLogs();
		$this->assertCount(1, $logs);
		$this->assertNoBody($logs[0]);
		$this->assertArrayNotHasKey('replayRequest', $logs[0]);
	}//end testASuccessOutsideAWindowStoresNoBody()

	/**
	 * Inside a window both bodies stay, with an expiry of window end plus retention.
	 *
	 * @return void
	 */
	public function testASuccessInsideAWindowStoresBothBodies(): void {
		$service = $this->service(new Response(200, [], '{"uuid":"z1"}'));

		$service->call(source: $this->source('2026-10-10T12:00:00+00:00'), endpoint: '/zaken', method: 'POST');

		$logs = $this->callLogs();
		$this->assertCount(1, $logs);
		$this->assertTrue($logs[0]['bodyCaptured']);
		$this->assertSame('999993653', $logs[0]['request']['json']['bsn']);
		$this->assertSame('{"uuid":"z1"}', $logs[0]['response']['body']);
		$this->assertSame('2026-10-17T12:00:00+00:00', $logs[0]['bodyExpiresAt']);
	}//end testASuccessInsideAWindowStoresBothBodies()

	/**
	 * A window that ended a minute ago stores nothing.
	 *
	 * @return void
	 */
	public function testACallAfterTheWindowEndsStoresNoBody(): void {
		$service = $this->service(new Response(200, [], '{"uuid":"z1"}'));

		$service->call(source: $this->source('2026-10-09T11:59:00+00:00'), endpoint: '/zaken', method: 'POST');

		$logs = $this->callLogs();
		$this->assertCount(1, $logs);
		$this->assertNoBody($logs[0]);
	}//end testACallAfterTheWindowEndsStoresNoBody()

	/**
	 * A window that cannot be read stores nothing.
	 *
	 * @return void
	 */
	public function testAnUnreadableWindowStoresNoBody(): void {
		$service = $this->service(new Response(200, [], '{"uuid":"z1"}'));

		$service->call(source: $this->source('not a moment'), endpoint: '/zaken', method: 'POST');

		$this->assertNoBody($this->callLogs()[0]);
	}//end testAnUnreadableWindowStoresNoBody()

	/**
	 * A settings read that throws stores nothing, even inside a window.
	 *
	 * @return void
	 */
	public function testABrokenSettingsReadStoresNoBody(): void {
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('hasKey')->willReturn(false);
		$appConfig->method('getValueInt')->willThrowException(new RuntimeException('app config unavailable'));

		$service = $this->service(new Response(200, [], '{"uuid":"z1"}'), $appConfig);

		$service->call(source: $this->source('2026-10-10T12:00:00+00:00'), endpoint: '/zaken', method: 'POST');

		$this->assertNoBody($this->callLogs()[0]);
	}//end testABrokenSettingsReadStoresNoBody()

	/**
	 * A failure keeps the request it sent, redacted, as the replay request.
	 *
	 * @return void
	 */
	public function testAFailureKeepsItsRequestForReplay(): void {
		$service = $this->service(new Response(502, [], 'bad gateway'));
		$source = $this->source(null);
		$data = $source->getObject();
		$data['configuration']['headers'] = ['Authorization' => 'Bearer live-secret-token-123'];
		$source->setObject($data);

		$service->call(source: $source, endpoint: '/zaken', method: 'POST');

		$log = $this->callLogs()[0];
		$this->assertSame('999993653', $log['replayRequest']['json']['bsn']);
		$this->assertSame('***REDACTED***', $log['replayRequest']['headers']['Authorization']);
		$this->assertStringNotContainsString('live-secret-token-123', json_encode($log));
		$this->assertArrayHasKey('bodyExpiresAt', $log);
	}//end testAFailureKeepsItsRequestForReplay()

	/**
	 * The buffered path applies the same rule to every buffered record.
	 *
	 * @return void
	 */
	public function testBufferedRecordsFollowTheSameRule(): void {
		$service = $this->service(new Response(500, [], 'zaaktype onbekend'));
		$service->bufferCallLogs(true);

		$service->call(source: $this->source(null), endpoint: '/zaken', method: 'POST');
		$service->call(source: $this->source(null), endpoint: '/zaken', method: 'POST');
		$this->assertSame(2, $service->flushCallLogs());

		$this->assertSame([], $this->callLogs());
		$this->assertCount(1, $this->batches);
		$this->assertCount(2, $this->batches[0]);
		foreach ($this->batches[0] as $log) {
			$this->assertNoBody($log);
		}
	}//end testBufferedRecordsFollowTheSameRule()

	/**
	 * A caller that passed logBody keeps the bodies, as the allowlisted SLO adapter does.
	 *
	 * @return void
	 */
	public function testLogBodyKeepsTheBodies(): void {
		$service = $this->service(new Response(200, [], '{"public":"curriculum"}'));

		$service->call(source: $this->source(null), endpoint: '/zaken', config: ['logBody' => true]);

		$log = $this->callLogs()[0];
		$this->assertTrue($log['bodyCaptured']);
		$this->assertSame('{"public":"curriculum"}', $log['response']['body']);
	}//end testLogBodyKeepsTheBodies()
}//end class

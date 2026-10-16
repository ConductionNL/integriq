<?php

/**
 * Unit tests: a call made with `withholdBodies` leaves a record without any body.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @spec openspec/changes/call-log-without-bodies/specs/outbound-call-log/spec.md#requirement-a-caller-can-have-a-call-recorded-without-any-body
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
use OCA\Integriq\Service\Helper\ExecutionTraceContext;
use OCA\Integriq\Service\Security\SensitiveFieldRegistry;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService;
use OCP\IAppConfig;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use ReflectionProperty;
use Twig\Loader\ArrayLoader;

/**
 * The per-call switch that keeps every body out of the call log (decision 229).
 *
 * Every test uses a source whose investigation window is OPEN and whose
 * configuration carries a number in the request body, so a record that keeps
 * a body anywhere fails the string search.
 *
 * @covers \OCA\Integriq\Service\CallService
 * @uses \OCA\Integriq\Outbound\Call\BodyCapturePolicy
 * @uses \OCA\Integriq\Service\Helper\ExecutionTraceContext
 * @uses \OCA\Integriq\Service\Security\SensitiveFieldRegistry
 */
class CallServiceWithheldBodiesTest extends TestCase {
	/**
	 * The fixed moment every test calls at.
	 *
	 * @var string
	 */
	private const NOW = '2026-10-12T12:00:00+00:00';

	/**
	 * The number the request carries.
	 *
	 * @var string
	 */
	private const ASKED = '999993653';

	/**
	 * The number the answer carries.
	 *
	 * @var string
	 */
	private const ANSWERED = '999990755';

	/**
	 * Single saves handed to ObjectService::saveObject().
	 *
	 * @var array<int,array<string,mixed>>
	 */
	private array $saved = [];

	/**
	 * Bulk saves handed to ObjectService::saveObjects().
	 *
	 * @var array<int,array<int,array<string,mixed>>>
	 */
	private array $batches = [];

	/**
	 * A CallService over a recording ObjectService and a Guzzle double.
	 *
	 * @param Response $answer What the upstream answers.
	 * @param string|null $userId The user in the session, null for none.
	 *
	 * @return CallService
	 */
	private function service(Response $answer, ?string $userId = 'j.devries'): CallService {
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

		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('hasKey')->willReturn(false);
		$appConfig->method('getValueInt')->willReturnArgument(2);

		$clock = $this->createMock(ClockInterface::class);
		$clock->method('now')->willReturn(new DateTimeImmutable(self::NOW));

		$brokered = $this->createMock(BrokeredCallService::class);
		$brokered->method('hasCredentialRef')->willReturn(false);

		$user = null;
		if ($userId !== null) {
			$user = $this->createMock(IUser::class);
			$user->method('getUID')->willReturn($userId);
		}

		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);

		$service = new CallService(
			$objectService,
			new ArrayLoader([]),
			$this->createMock(AuthenticationService::class),
			$appConfig,
			$this->createMock(LoggerInterface::class),
			$brokered,
			new SensitiveFieldRegistry(),
			null,
			new BodyCapturePolicy($appConfig, $clock, $session),
		);

		$client = $this->createMock(Client::class);
		$client->method('request')->willReturn($answer);
		$property = new ReflectionProperty(CallService::class, 'client');
		$property->setValue($service, $client);

		return $service;
	}//end service()

	/**
	 * A source with an OPEN investigation window and a number in its request body.
	 *
	 * @return ObjectEntity
	 */
	private function source(): ObjectEntity {
		$source = new ObjectEntity();
		$source->setUuid('7d3f0c1e-5b2a-4c8d-9e6f-0a1b2c3d4e5f');
		$source->setObject(
			[
				'name' => 'brp-haalcentraal',
				'isEnabled' => true,
				'location' => 'https://brp.example.invalid/haalcentraal/api/brp',
				'bodyCaptureUntil' => '2026-10-13T12:00:00+00:00',
				'bodyCaptureReason' => 'melding 4711',
				'bodyCaptureBy' => 'admin',
				'configuration' => [
					'json' => ['burgerservicenummer' => [self::ASKED]],
					'query' => ['burgerservicenummer' => self::ASKED],
				],
			]
		);

		return $source;
	}//end source()

	/**
	 * The call_log records written singly.
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
	 * Search the whole stored record, as a string, for either number and for body keys.
	 *
	 * @param array<string,mixed> $log The stored record.
	 *
	 * @return void
	 */
	private function assertHoldsNoBody(array $log): void {
		$stored = (string)json_encode($log);
		$this->assertStringNotContainsString(self::ASKED, $stored);
		$this->assertStringNotContainsString(self::ANSWERED, $stored);
		$this->assertStringNotContainsString('burgerservicenummer', $stored);
		$this->assertArrayNotHasKey('replayRequest', $log);
		$this->assertArrayNotHasKey('bodyExpiresAt', $log);
		$this->assertFalse($log['bodyCaptured']);
		$this->assertSame(['url', 'method'], array_keys($log['request']));
		$this->assertArrayNotHasKey('body', $log['response']);
		$this->assertArrayNotHasKey('headers', $log['response']);
	}//end assertHoldsNoBody()

	/**
	 * Inside an open investigation window the record still holds no body.
	 *
	 * @return void
	 */
	public function testAnAnsweredCallIsARecordWithoutBodiesEvenInsideAWindow(): void {
		$service = $this->service(new Response(200, ['X-Echo' => self::ASKED], '{"personen":[{"burgerservicenummer":"' . self::ANSWERED . '"}]}'));

		$entity = $service->call(source: $this->source(), endpoint: '/personen', method: 'POST', withholdBodies: true);

		$logs = $this->callLogs();
		$this->assertCount(1, $logs);
		$this->assertHoldsNoBody($logs[0]);
		$this->assertSame(200, $logs[0]['statusCode']);
		$this->assertSame('7d3f0c1e-5b2a-4c8d-9e6f-0a1b2c3d4e5f', $logs[0]['source']);
		$this->assertSame('outbound', $logs[0]['direction']);
		$this->assertSame('j.devries', $logs[0]['userId']);
		$this->assertSame('POST', $logs[0]['request']['method']);
		$this->assertSame('https://brp.example.invalid/haalcentraal/api/brp/personen', $logs[0]['request']['url']);
		$this->assertArrayHasKey('created', $logs[0]);
		$this->assertArrayHasKey('responseTime', $logs[0]['response']);

		// The caller still gets the whole answer to work with.
		$this->assertStringContainsString(self::ANSWERED, $entity->getObject()['response']['body']);
	}//end testAnAnsweredCallIsARecordWithoutBodiesEvenInsideAWindow()

	/**
	 * A failure keeps no replay request: that would be the request body by another name.
	 *
	 * @return void
	 */
	public function testAFailedCallKeepsNoReplayRequest(): void {
		$service = $this->service(new Response(500, [], 'geen persoon voor ' . self::ASKED));

		$service->call(source: $this->source(), endpoint: '/personen', method: 'POST', withholdBodies: true);

		$logs = $this->callLogs();
		$this->assertCount(1, $logs);
		$this->assertHoldsNoBody($logs[0]);
		$this->assertSame(500, $logs[0]['statusCode']);
		$this->assertSame('j.devries', $logs[0]['userId']);
	}//end testAFailedCallKeepsNoReplayRequest()

	/**
	 * The switch wins over `logBody`: it cannot be undone by another option on the same call.
	 *
	 * @return void
	 */
	public function testTheSwitchWinsOverLogBody(): void {
		$service = $this->service(new Response(200, [], '{"burgerservicenummer":"' . self::ANSWERED . '"}'));

		$service->call(source: $this->source(), endpoint: '/personen', method: 'POST', config: ['logBody' => true], withholdBodies: true);

		$this->assertHoldsNoBody($this->callLogs()[0]);
	}//end testTheSwitchWinsOverLogBody()

	/**
	 * A buffered record follows the same rule.
	 *
	 * @return void
	 */
	public function testABufferedRecordHoldsNoBody(): void {
		$service = $this->service(new Response(502, [], 'bad gateway ' . self::ASKED));
		$service->bufferCallLogs(true);

		$service->call(source: $this->source(), endpoint: '/personen', method: 'POST', withholdBodies: true);
		$this->assertSame(1, $service->flushCallLogs());

		$this->assertSame([], $this->callLogs());
		$this->assertHoldsNoBody($this->batches[0][0]);
		$this->assertSame('j.devries', $this->batches[0][0]['userId']);
	}//end testABufferedRecordHoldsNoBody()

	/**
	 * An execution trace gets the same stripped request and response.
	 *
	 * @return void
	 */
	public function testTheTraceStepHoldsNoBody(): void {
		$service = $this->service(new Response(200, [], '{"burgerservicenummer":"' . self::ANSWERED . '"}'));
		$trace = new ExecutionTraceContext('test');

		$service->call(source: $this->source(), endpoint: '/personen', method: 'POST', trace: $trace, withholdBodies: true);

		$steps = (string)json_encode($trace->getSteps());
		$this->assertStringNotContainsString(self::ASKED, $steps);
		$this->assertStringNotContainsString(self::ANSWERED, $steps);
	}//end testTheTraceStepHoldsNoBody()

	/**
	 * A call without a user in the session, such as a background job, records no user.
	 *
	 * @return void
	 */
	public function testACallWithoutAUserRecordsNoUser(): void {
		$service = $this->service(new Response(200, [], '{}'), null);

		$service->call(source: $this->source(), endpoint: '/personen', method: 'POST', withholdBodies: true);

		$this->assertArrayNotHasKey('userId', $this->callLogs()[0]);
	}//end testACallWithoutAUserRecordsNoUser()

	/**
	 * Control: the same call without the switch stores the bodies, so the
	 * searches above can fail.
	 *
	 * @return void
	 */
	public function testControlWithoutTheSwitchTheWindowStoresTheBodies(): void {
		$service = $this->service(new Response(200, [], '{"burgerservicenummer":"' . self::ANSWERED . '"}'));

		$service->call(source: $this->source(), endpoint: '/personen', method: 'POST');

		$stored = (string)json_encode($this->callLogs()[0]);
		$this->assertStringContainsString(self::ASKED, $stored);
		$this->assertStringContainsString(self::ANSWERED, $stored);
	}//end testControlWithoutTheSwitchTheWindowStoresTheBodies()
}//end class

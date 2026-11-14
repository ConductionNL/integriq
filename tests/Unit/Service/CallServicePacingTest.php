<?php

/**
 * Unit tests: CallService waits for a paced source's turn, and a live call
 * whose turn is too far away is refused before anything is sent.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @spec openspec/changes/sources-outbound-rate-limit-pacing/specs/http-call-engine/spec.md#requirement-a-live-call-waits-briefly-background-work-waits-for-the-window-req-rlpc-002
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
use OCA\Integriq\Service\Call\PacerClock;
use OCA\Integriq\Service\Call\SourcePacer;
use OCA\Integriq\Service\CallService;
use OCA\Integriq\Service\Security\SensitiveFieldRegistry;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService;
use OCP\IAppConfig;
use OCP\ICacheFactory;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use ReflectionProperty;
use Twig\Loader\ArrayLoader;

/**
 * A CallService over a recording ObjectService, a Guzzle double and a pacer on a fake clock.
 *
 * @covers \OCA\Integriq\Service\CallService
 * @uses \OCA\Integriq\Service\Call\SourcePacer
 * @uses \OCA\Integriq\Service\Call\PacingDecision
 * @uses \OCA\Integriq\Service\Call\PacerClock
 * @uses \OCA\Integriq\Service\Call\CallRequest
 * @uses \OCA\Integriq\Service\Call\FinishedCall
 * @uses \OCA\Integriq\Service\SourceAuthApplier
 * @uses \OCA\Integriq\Outbound\Call\BodyCapturePolicy
 * @uses \OCA\Integriq\Service\Security\SensitiveFieldRegistry
 */
class CallServicePacingTest extends TestCase {

	/**
	 * The fake clock's start, in microseconds.
	 *
	 * @var int
	 */
	private const START = 1794744000000000;

	/**
	 * Single saves handed to ObjectService::saveObject().
	 *
	 * @var array<int,array<string,mixed>>
	 */
	private array $saved = [];

	/**
	 * The request options of every call sent upstream.
	 *
	 * @var array<int,array<string,mixed>>
	 */
	private array $sent = [];

	/**
	 * The fake clock the pacer reads.
	 *
	 * @var PacerClock
	 */
	private PacerClock $clock;

	/**
	 * A CallService with a pacer over a per-process budget on the fake clock.
	 *
	 * @return CallService
	 */
	private function service(): CallService {
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

		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('hasKey')->willReturn(false);
		$appConfig->method('getValueInt')->willReturnArgument(2);

		$clock = $this->createMock(ClockInterface::class);
		$clock->method('now')->willReturn(new DateTimeImmutable('2026-11-14T12:00:00+00:00'));

		$brokered = $this->createMock(BrokeredCallService::class);
		$brokered->method('hasCredentialRef')->willReturn(false);

		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn(null);

		$this->clock = new class(self::START) extends PacerClock {
			/**
			 * @param int $now The time the clock starts at.
			 */
			public function __construct(public int $now) {
			}

			/**
			 * @return int
			 */
			public function nowMicros(): int {
				return $this->now;
			}

			/**
			 * @param int $micros How long to wait.
			 *
			 * @return void
			 */
			public function sleepMicros(int $micros): void {
				$this->now += $micros;
			}
		};

		$cacheFactory = $this->createMock(ICacheFactory::class);
		$cacheFactory->method('isAvailable')->willReturn(false);

		$service = new CallService(
			$objectService,
			new ArrayLoader([]),
			$this->createMock(AuthenticationService::class),
			$appConfig,
			$this->createMock(LoggerInterface::class),
			$brokered,
			new SensitiveFieldRegistry(),
			new BodyCapturePolicy($appConfig, $clock, $session),
			null,
			new SourcePacer($cacheFactory, $this->clock),
		);

		$client = $this->createMock(Client::class);
		$client->method('request')->willReturnCallback(
			function (string $method, string $url, array $options = []) {
				$this->sent[] = $options;
				return new Response(200, ['Content-Type' => 'application/json'], '{"ok":true}');
			}
		);
		$property = new ReflectionProperty(CallService::class, 'client');
		$property->setValue($service, $client);

		return $service;
	}//end service()

	/**
	 * A source allowing one call every five seconds.
	 *
	 * @return ObjectEntity
	 */
	private function source(): ObjectEntity {
		$source = new ObjectEntity();
		$source->setUuid('5c0e8f2a-1b3d-4e6f-8a9b-0c1d2e3f4a5b');
		$source->setObject(
			[
				'name' => 'kvk',
				'isEnabled' => true,
				'location' => 'https://kvk.example.invalid/api/v2',
				'pace' => ['maxCalls' => 1, 'perSeconds' => 5],
			]
		);

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
	 * Background work waits for its turn, and the call log records how long.
	 *
	 * @return void
	 */
	public function testABackgroundCallWaitsForItsTurnAndLogsTheWait(): void {
		$service = $this->service();

		$service->call(source: $this->source(), endpoint: '/zoeken');
		$service->call(source: $this->source(), endpoint: '/zoeken');

		$this->assertCount(2, $this->sent);
		$logs = $this->callLogs();
		$this->assertCount(2, $logs);
		$this->assertSame(200, $logs[0]['statusCode']);
		$this->assertArrayNotHasKey('pacedMs', $logs[0]);
		$this->assertSame(200, $logs[1]['statusCode']);
		$this->assertSame(5000, $logs[1]['pacedMs']);
		$this->assertSame(self::START + 5000000, $this->clock->nowMicros());
	}//end testABackgroundCallWaitsForItsTurnAndLogsTheWait()

	/**
	 * A live call whose turn is five seconds away is refused with 429 and retryAfter, and nothing is sent.
	 *
	 * @return void
	 */
	public function testALiveCallTooFarAwayIsRefusedAndNotSent(): void {
		$service = $this->service();

		$service->call(source: $this->source(), endpoint: '/zoeken', config: ['pacing' => 'live']);
		$refused = $service->call(source: $this->source(), endpoint: '/zoeken', config: ['pacing' => 'live']);

		$this->assertCount(1, $this->sent);
		$this->assertArrayNotHasKey('pacing', $this->sent[0]);
		$data = $refused->getObject();
		$this->assertSame(429, $data['statusCode']);
		$this->assertSame(5, $data['retryAfter']);
		$this->assertSame('This source is paced: the next call is due in 5 seconds, longer than this call may wait.', $data['statusMessage']);
		$this->assertSame('5c0e8f2a-1b3d-4e6f-8a9b-0c1d2e3f4a5b', $data['source']);
		$this->assertSame('outbound', $data['direction']);
		$this->assertSame(self::START, $this->clock->nowMicros());
	}//end testALiveCallTooFarAwayIsRefusedAndNotSent()

	/**
	 * The interactive test of a source is live as well.
	 *
	 * @return void
	 */
	public function testTheInteractiveTestIsLive(): void {
		$service = $this->service();

		$service->call(source: $this->source(), endpoint: '/zoeken');
		$refused = $service->call(source: $this->source(), endpoint: '/zoeken', persistLog: false);

		$this->assertCount(1, $this->sent);
		$this->assertSame(429, $refused->getObject()['statusCode']);
		$this->assertSame(5, $refused->getObject()['retryAfter']);
	}//end testTheInteractiveTestIsLive()

	/**
	 * An unpaced source is called at once, and its log carries no wait.
	 *
	 * @return void
	 */
	public function testAnUnpacedSourceIsNotPaced(): void {
		$service = $this->service();
		$source = $this->source();
		$data = $source->getObject();
		unset($data['pace']);
		$source->setObject($data);

		$service->call(source: $source, endpoint: '/zoeken', config: ['pacing' => 'live']);
		$service->call(source: $source, endpoint: '/zoeken', config: ['pacing' => 'live']);

		$this->assertCount(2, $this->sent);
		foreach ($this->callLogs() as $log) {
			$this->assertSame(200, $log['statusCode']);
			$this->assertArrayNotHasKey('pacedMs', $log);
		}

		$this->assertSame(self::START, $this->clock->nowMicros());
	}//end testAnUnpacedSourceIsNotPaced()
}//end class

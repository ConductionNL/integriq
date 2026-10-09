<?php

/**
 * An object write persists its CloudEvent and queues the fan-out.
 *
 * Before this, handleObjectCreated/Updated/Deleted ran processEvent() inline:
 * a subscription query, one event_message per match and a synchronous push
 * delivery, all inside the request that wrote someone else's object. These
 * tests hold the write path to one `event` save and one queued job, and the
 * queued run to one subscription query however many events it handles.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/stop-cloudevent-recursion/specs/events/spec.md#requirement-event-fan-out-shall-not-run-inside-the-originating-write-request
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Service;

use OCA\Integriq\BackgroundJob\ProcessEventJob;
use OCA\Integriq\Service\CallService;
use OCA\Integriq\Service\EventService;
use OCA\Integriq\Service\FlowRunnerService;
use OCA\Integriq\Service\JobService;
use OCA\Integriq\Service\SynchronizationService;
use OCA\Integriq\Service\WebhookSignatureService;
use OCA\Integriq\Tests\Helpers\ObjectServiceMockBuilder;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService;
use OCP\BackgroundJob\IJobList;
use OCP\Http\Client\IClientService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionMethod;

/**
 * The object handlers queue; the queued run resolves subscriptions once.
 */
class EventServiceFanOutOffRequestTest extends TestCase {

	/**
	 * Schema slug of every saveObject call, in call order.
	 *
	 * @var array<int, string|null>
	 */
	private array $savedSchemas = [];

	/**
	 * Schema slug of every findAll call, in call order.
	 *
	 * @var array<int, string|null>
	 */
	private array $queriedSchemas = [];

	/**
	 * Arguments of every job-list add, in call order.
	 *
	 * @var array<int, array{0: string, 1: mixed}>
	 */
	private array $queued = [];

	/**
	 * Active subscriptions the object service answers with.
	 *
	 * @var array<int, ObjectEntity>
	 */
	private array $subscriptions = [];

	/**
	 * Events by uuid, for find().
	 *
	 * @var array<string, ObjectEntity>
	 */
	private array $events = [];

	/**
	 * The HTTP client factory; a push delivery would go through it.
	 *
	 * @var IClientService&MockObject
	 */
	private IClientService $clientService;

	/**
	 * The service under test.
	 *
	 * @var EventService
	 */
	private EventService $service;

	/**
	 * Build the service over a recording object service and job list.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$objectService = ObjectServiceMockBuilder::make($this);
		$names = array_map(
			static fn (\ReflectionParameter $parameter): string => $parameter->getName(),
			(new ReflectionMethod(ObjectService::class, 'saveObject'))->getParameters()
		);

		$objectService->method('saveObject')->willReturnCallback(
			function (...$args) use ($names) {
				$named = array_combine(array_slice($names, 0, count($args)), $args);
				$this->savedSchemas[] = ($named['schema'] ?? null);

				$entity = new ObjectEntity();
				$entity->setUuid('saved-' . count($this->savedSchemas));
				$entity->setObject(is_array($args[0]) === true ? $args[0] : []);

				return $entity;
			}
		);
		$objectService->method('findAll')->willReturnCallback(
			function (array $config = []) {
				$schema = ($config['filters']['schema'] ?? null);
				$this->queriedSchemas[] = $schema;
				if ($schema === 'event_subscription') {
					return ['results' => $this->subscriptions];
				}

				return ['results' => []];
			}
		);
		$objectService->method('find')->willReturnCallback(
			fn ($id) => ($this->events[(string)$id] ?? null)
		);

		$jobList = $this->createMock(IJobList::class);
		$jobList->method('add')->willReturnCallback(
			function ($job, $argument = null): void {
				$this->queued[] = [(string)$job, $argument];
			}
		);

		$logger = $this->createMock(LoggerInterface::class);
		$this->clientService = $this->createMock(IClientService::class);
		$this->service = new EventService(
			objectService: $objectService,
			clientService: $this->clientService,
			logger: $logger,
			signatureService: new WebhookSignatureService($logger),
			synchronizationService: $this->createMock(SynchronizationService::class),
			jobService: $this->createMock(JobService::class),
			callService: $this->createMock(CallService::class),
			flowRunnerService: $this->createMock(FlowRunnerService::class),
			jobList: $jobList,
		);
	}//end setUp()

	/**
	 * An object with the given uuid.
	 *
	 * @param string $uuid The uuid.
	 *
	 * @return ObjectEntity
	 */
	private function object(string $uuid): ObjectEntity {
		$object = new ObjectEntity();
		$object->setUuid($uuid);
		$object->setObject(['type' => 'thing', 'userId' => 'alice']);

		return $object;
	}//end object()

	/**
	 * An active subscription with no filters, so it matches every event.
	 *
	 * @param string $uuid  The subscription uuid.
	 * @param string $style push or pull.
	 *
	 * @return ObjectEntity
	 */
	private function subscription(string $uuid, string $style): ObjectEntity {
		$subscription = new ObjectEntity();
		$subscription->setUuid($uuid);
		$subscription->setObject(['status' => 'active', 'style' => $style, 'sink' => 'https://unreachable.invalid/hook']);

		return $subscription;
	}//end subscription()

	/**
	 * Each object handler saves one event, queues one job and fans out nothing.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/stop-cloudevent-recursion/specs/events/spec.md#requirement-event-fan-out-shall-not-run-inside-the-originating-write-request
	 */
	public function testAnObjectWriteQueuesOneJobAndCreatesNoMessages(): void {
		$this->subscriptions = [$this->subscription('sub-1', 'push')];
		$this->clientService->expects($this->never())->method('newClient');

		$results = [
			$this->service->handleObjectCreated($this->object('o-1')),
			$this->service->handleObjectUpdated($this->object('o-1'), $this->object('o-1')),
			$this->service->handleObjectDeleted($this->object('o-1')),
		];

		$this->assertSame(['event', 'event', 'event'], $this->savedSchemas, 'Only the event row is written in the request.');
		$this->assertNotContains('event_subscription', $this->queriedSchemas, 'No subscription query in the request.');
		$this->assertSame([[], [], []], $results, 'No messages are created in the request.');
		$this->assertCount(3, $this->queued, 'One job per object write.');
		foreach ($this->queued as $index => [$job, $argument]) {
			$this->assertSame(ProcessEventJob::class, $job);
			$this->assertSame(['eventId' => 'saved-' . ($index + 1)], $argument);
		}
	}//end testAnObjectWriteQueuesOneJobAndCreatesNoMessages()

	/**
	 * A queued run over several events asks for the subscriptions once.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/stop-cloudevent-recursion/specs/events/spec.md#requirement-active-subscriptions-shall-be-resolved-once-per-processing-run
	 */
	public function testAQueuedRunQueriesTheSubscriptionsOnce(): void {
		$this->subscriptions = [$this->subscription('sub-1', 'pull'), $this->subscription('sub-2', 'pull')];
		foreach (['e-1', 'e-2', 'e-3'] as $uuid) {
			$event = new ObjectEntity();
			$event->setUuid($uuid);
			$event->setObject(['type' => 'com.nextcloud.openregister.object.created', 'source' => '/objects/thing']);
			$this->events[$uuid] = $event;
		}

		$created = $this->service->processQueuedEvents(['e-1', 'e-2', 'e-3']);

		$subscriptionQueries = array_filter($this->queriedSchemas, static fn ($schema): bool => $schema === 'event_subscription');
		$this->assertCount(1, $subscriptionQueries, 'Subscriptions are fetched once per run.');
		$this->assertSame(6, $created, 'Three events times two matching subscriptions.');
		$this->assertSame(6, count(array_filter($this->savedSchemas, static fn ($schema): bool => $schema === 'event_message')));
	}//end testAQueuedRunQueriesTheSubscriptionsOnce()

	/**
	 * An event that is gone by the time the job runs is skipped, not fatal.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/stop-cloudevent-recursion/specs/events/spec.md#requirement-event-fan-out-shall-not-run-inside-the-originating-write-request
	 */
	public function testAMissingEventIsSkipped(): void {
		$this->subscriptions = [$this->subscription('sub-1', 'pull')];

		$this->assertSame(0, $this->service->processQueuedEvents(['gone']));
		$this->assertNotContains('event_message', $this->savedSchemas);
	}//end testAMissingEventIsSkipped()
}//end class

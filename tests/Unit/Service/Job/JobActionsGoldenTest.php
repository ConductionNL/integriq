<?php

/**
 * Golden test pinning the whole response of EventAction::run() and SynchronizationAction::run().
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Service\Job
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 *
 * @spec openspec/specs/event-bus/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Service\Job;

use OCA\Integriq\Service\EventService;
use OCA\Integriq\Service\Job\EventAction;
use OCA\Integriq\Service\Job\SynchronizationAction;
use OCA\Integriq\Service\SynchronizationService;
use OCA\Integriq\Tests\Helpers\ObjectServiceMockBuilder;
use OCP\AppFramework\Db\DoesNotExistException;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;

/**
 * Pins every response array, key order included, and what reached the services.
 */
class JobActionsGoldenTest extends TestCase {

	/** @var array<int, array> What emitCloudEvent() received. */
	private array $emitted = [];

	/**
	 * An EventAction over a service double.
	 *
	 * @param mixed $subscribed What hasActiveSubscriptions() returns, or a Throwable it throws.
	 * @param mixed $emit       What emitCloudEvent() returns, or a Throwable it throws.
	 *
	 * @return EventAction
	 */
	private function eventAction(mixed $subscribed = true, mixed $emit = [1, 2]): EventAction {
		$this->emitted = [];
		$service = $this->createMock(EventService::class);
		$service->method('hasActiveSubscriptions')->willReturnCallback(
			function () use ($subscribed) {
				if ($subscribed instanceof \Throwable) {
					throw $subscribed;
				}

				return $subscribed;
			}
		);
		$service->method('emitCloudEvent')->willReturnCallback(
			function (...$arguments) use ($emit) {
				$this->emitted[] = $arguments;
				if ($emit instanceof \Throwable) {
					throw $emit;
				}

				return $emit;
			}
		);

		return new EventAction($service);

	}//end eventAction()

	/**
	 * Every EventAction response.
	 *
	 * @return void
	 */
	public function testEventActionResponses(): void {
		$this->assertSame(
			['stackTrace' => ['Running EventAction', 'EventAction needs both a "type" and a "source" argument; nothing was emitted.'], 'level' => 'ERROR', 'message' => 'EventAction needs both a "type" and a "source" argument; nothing was emitted.'],
			$this->eventAction()->run(['type' => ' ', 'source' => 's'])
		);
		$this->assertSame(
			['stackTrace' => ['Running EventAction', 'The "data" argument must be an object, or a JSON object as a string.'], 'level' => 'ERROR', 'message' => 'The "data" argument must be an object, or a JSON object as a string.'],
			$this->eventAction()->run(['type' => 't', 'source' => 's', 'data' => 'nope'])
		);
		$this->assertSame([], $this->emitted);

		$this->assertSame(
			['stackTrace' => ['Running EventAction', 'No active subscriptions — the event is recorded but delivered to nobody.', 'Emitting CloudEvent type="t" source="s"', 'Emitted CloudEvent "t"; 2 subscription message(s) created.'], 'level' => 'INFO', 'message' => 'Emitted CloudEvent "t"; 2 subscription message(s) created.'],
			$this->eventAction(false)->run(['type' => ' t ', 'source' => ' s', 'subject' => 12, 'data' => '{"a":1}', 'userId' => 7])
		);
		$this->assertSame([['t', 's', '12', ['a' => 1], '7']], array_map(static fn (array $call): array => array_values($call), $this->emitted));

		$this->assertSame(
			['stackTrace' => ['Running EventAction', 'Could not check for active subscriptions: db', 'Emitting CloudEvent type="t" source="s"', 'Emitting the event failed: bus'], 'level' => 'ERROR', 'message' => 'Emitting the event failed: bus'],
			$this->eventAction(new RuntimeException('db'), new RuntimeException('bus'))->run(['type' => 't', 'source' => 's', 'data' => ['k' => 'v']])
		);
		$this->assertSame([['t', 's', null, ['k' => 'v'], null]], array_map(static fn (array $call): array => array_values($call), $this->emitted));

		$this->assertSame(
			['stackTrace' => ['Running EventAction', 'Emitting CloudEvent type="t" source="s"', 'Emitted CloudEvent "t"; 0 subscription message(s) created.'], 'level' => 'INFO', 'message' => 'Emitted CloudEvent "t"; 0 subscription message(s) created.'],
			$this->eventAction(true, [])->run(['type' => 't', 'source' => 's'])
		);

	}//end testEventActionResponses()

	/**
	 * A SynchronizationAction over a service double.
	 *
	 * @param mixed $found  The synchronization, or a Throwable getSynchronization() throws.
	 * @param mixed $result What synchronize() returns, or a Throwable it throws.
	 * @param array $calls  The synchronize() arguments, by reference.
	 *
	 * @return SynchronizationAction
	 */
	private function syncAction(mixed $found, mixed $result, array &$calls = []): SynchronizationAction {
		$service = $this->createMock(SynchronizationService::class);
		$service->method('getSynchronization')->willReturnCallback(
			function () use ($found) {
				if ($found instanceof \Throwable) {
					throw $found;
				}

				return $found;
			}
		);
		$service->method('synchronize')->willReturnCallback(
			function (...$arguments) use ($result, &$calls) {
				$calls[] = $arguments;
				if ($result instanceof \Throwable) {
					throw $result;
				}

				return $result;
			}
		);

		return new SynchronizationAction($service);

	}//end syncAction()

	/**
	 * Every SynchronizationAction response.
	 *
	 * @return void
	 */
	public function testSynchronizationActionResponses(): void {
		$sync = ObjectServiceMockBuilder::objectEntity($this, ['name' => 'sync'], 'sync-1');

		$this->assertSame(
			['stackTrace' => ['Check for a valid synchronization ID', 'No synchronization ID provided'], 'message' => 'No synchronization ID provided', 'level' => 'ERROR'],
			$this->syncAction($sync, [])->run([])
		);
		$this->assertSame(
			['stackTrace' => ['Check for a valid synchronization ID', 'Getting synchronization: 9', 'Synchronization not found: 9'], 'message' => 'Synchronization not found: 9', 'level' => 'WARNING'],
			$this->syncAction(new DoesNotExistException('x'), [])->run(['synchronizationId' => 9])
		);
		$this->assertSame(
			['stackTrace' => ['Check for a valid synchronization ID', 'Getting synchronization: s', 'Force enabled for synchronization job', 'Doing the synchronization', 'Stopped synchronization: slow', 'Returning X-RateLimit-Reset header to update Job nextRun: 1700000000'], 'message' => 'Stopped synchronization: slow', 'level' => 'WARNING', 'nextRun' => 1700000000],
			$this->syncAction($sync, new TooManyRequestsHttpException(null, 'slow', null, 0, ['X-RateLimit-Reset' => 1700000000]))->run(['synchronizationId' => 's', 'force' => 'yes'])
		);
		$this->assertSame(
			['stackTrace' => ['Check for a valid synchronization ID', 'Getting synchronization: s', 'Doing the synchronization', 'Stopped synchronization: slow'], 'message' => 'Stopped synchronization: slow', 'level' => 'WARNING'],
			$this->syncAction($sync, new TooManyRequestsHttpException(null, 'slow'))->run(['synchronizationId' => 's'])
		);
		$this->assertSame(
			['stackTrace' => ['Check for a valid synchronization ID', 'Getting synchronization: s', 'Doing the synchronization', 'Failed to synchronize: boom'], 'message' => 'Failed to synchronize: boom', 'level' => 'ERROR'],
			$this->syncAction($sync, new RuntimeException('boom'))->run(['synchronizationId' => 's'])
		);

		$calls = [];
		$this->assertSame(
			['stackTrace' => ['Check for a valid synchronization ID', 'Getting synchronization: s', 'Doing the synchronization', 'Synchronized 2 successfully'], 'message' => 'Synchronized 2 successfully', 'level' => 'INFO'],
			$this->syncAction($sync, ['result' => ['contracts' => ['a', 'b'], 'objects' => ['found' => 9]]], $calls)->run(['synchronizationId' => 's', 'force' => 'no', '_executionTrace' => null])
		);
		// Positional: synchronization (0), isTest (1), force (2), ..., trace (10).
		$this->assertSame([$sync, false, false, null], [$calls[0][0], $calls[0][1], $calls[0][2], $calls[0][10]]);
		$this->assertSame(
			['stackTrace' => ['Check for a valid synchronization ID', 'Getting synchronization: s', 'Doing the synchronization', 'Synchronized 9 successfully', 'More batches remain; resuming at batch 3 on the next run'], 'message' => 'Synchronized 9 successfully', 'level' => 'INFO', 'resumeSoon' => true],
			$this->syncAction($sync, ['result' => ['contracts' => [], 'objects' => ['found' => 9], 'resume' => ['page' => 3]]])->run(['synchronizationId' => 's'])
		);
		$this->assertSame(
			['stackTrace' => ['Check for a valid synchronization ID', 'Getting synchronization: s', 'Doing the synchronization', 'Synchronized 0 successfully'], 'message' => 'Synchronized 0 successfully', 'level' => 'INFO'],
			$this->syncAction($sync, null)->run(['synchronizationId' => 's'])
		);

	}//end testSynchronizationActionResponses()
}//end class

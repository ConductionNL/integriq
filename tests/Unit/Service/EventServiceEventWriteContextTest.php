<?php

/**
 * EventService writes its own `event` rows in system context.
 *
 * The `event` schema lets only administrators create an event through the
 * object API (integriq#2224), because a created `nl.conduction.peppol.outbound.requested`
 * event makes integriq read and send the file it names. integriq's own event
 * writers therefore save in system context, or a non-admin's object write, a
 * sessionless webhook or a Nextcloud file event would lose its CloudEvent.
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
 * @spec openspec/changes/peppol-readable-payloads-and-scoped-consumer/specs/peppol-access-point-connector/spec.md#requirement-the-access-point-receives-the-ubl-document-itself-req-008
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Service;

use OCA\Integriq\Event\DeliveryRequestedEvent;
use OCA\Integriq\Service\CallService;
use OCA\Integriq\Service\EventService;
use OCA\Integriq\Service\FlowRunnerService;
use OCA\Integriq\Service\JobService;
use OCA\Integriq\Service\SynchronizationService;
use OCA\Integriq\Service\WebhookSignatureService;
use OCA\Integriq\Tests\Helpers\ObjectServiceMockBuilder;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService;
use OCP\Http\Client\IClientService;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionMethod;

/**
 * Every EventService writer of an `event` row saves with `_rbac: false`.
 */
class EventServiceEventWriteContextTest extends TestCase {

	/**
	 * The `_rbac` and `_multitenancy` values of each `event` save, in call order.
	 *
	 * @var array<int, array{rbac: mixed, multitenancy: mixed}>
	 */
	private array $eventSaves = [];

	/**
	 * The service under test.
	 *
	 * @var EventService
	 */
	private EventService $service;

	/**
	 * Build the service over an object service that records each `event` save.
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
				if (($named['schema'] ?? null) === 'event') {
					$this->eventSaves[] = [
						'rbac' => ($named['_rbac'] ?? true),
						'multitenancy' => ($named['_multitenancy'] ?? true),
					];
				}

				$entity = new ObjectEntity();
				$entity->setUuid('event-uuid-1');
				$entity->setObject(is_array($args[0]) === true ? $args[0] : []);

				return $entity;
			}
		);

		$logger = $this->createMock(LoggerInterface::class);
		$this->service = new EventService(
			$objectService,
			$this->createMock(IClientService::class),
			$logger,
			new WebhookSignatureService($logger),
			$this->createMock(SynchronizationService::class),
			$this->createMock(JobService::class),
			$this->createMock(CallService::class),
			$this->createMock(FlowRunnerService::class),
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
	 * All six writers save their `event` row in system context.
	 *
	 * @return void
	 */
	public function testEveryEventWriterSavesInSystemContext(): void {
		$this->service->emitCloudEvent(type: 'nl.conduction.test', source: '/test', subject: null, data: []);
		$this->service->ingestDeliveryRequest(
			new DeliveryRequestedEvent('dossiq', 'r', 's', 'id-1', 'Label', 'notice', 'email', [], 'corr-1')
		);
		$this->service->handleNextcloudEvent(type: 'com.nextcloud.files.node.created', payload: ['source' => '/nextcloud/files']);
		$this->service->handleObjectCreated($this->object('o-1'));
		$this->service->handleObjectUpdated($this->object('o-1'), $this->object('o-1'));
		$this->service->handleObjectDeleted($this->object('o-1'));

		$this->assertCount(6, $this->eventSaves, 'Each writer saves one event row.');
		foreach ($this->eventSaves as $index => $save) {
			$this->assertFalse($save['rbac'], "Event save #$index ran in the caller's RBAC context.");
			$this->assertFalse($save['multitenancy'], "Event save #$index ran in the caller's tenant scope.");
		}
	}//end testEveryEventWriterSavesInSystemContext()
}//end class

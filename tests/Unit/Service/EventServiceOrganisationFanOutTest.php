<?php

/**
 * An object event reaches only the subscriptions of the object's organisation.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 *
 * @spec openspec/changes/integriq-tenant-scoping/specs/tenant-scoping/spec.md#requirement-a-user-only-reaches-their-own-organisations-integriq-objects-req-ten-001
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Service;

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
 * The fan-out reads the subscriptions of every organisation in system context,
 * so the organisation check sits in the match. Three pull subscriptions: one of
 * organisation A, one of B, one created before multitenancy (no organisation).
 */
class EventServiceOrganisationFanOutTest extends TestCase {

	/**
	 * Organisation A.
	 *
	 * @var string
	 */
	private const ORG_A = '0a0a0a0a-0000-4000-8000-00000000000a';

	/**
	 * Organisation B.
	 *
	 * @var string
	 */
	private const ORG_B = '0b0b0b0b-0000-4000-8000-00000000000b';

	/**
	 * Every saved object per schema.
	 *
	 * @var array<string, array<int, ObjectEntity>>
	 */
	private array $rows = [];

	/**
	 * The service under test.
	 *
	 * @var EventService
	 */
	private EventService $service;

	/**
	 * Build the service over a store that holds the three subscriptions.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->rows['event_subscription'] = [
			$this->subscription(uuid: 'sub-a', organisation: self::ORG_A),
			$this->subscription(uuid: 'sub-b', organisation: self::ORG_B),
			$this->subscription(uuid: 'sub-none', organisation: null),
		];

		$objectService = ObjectServiceMockBuilder::make($this);
		$objectService->method('findAll')->willReturnCallback(
			fn (array $config = []): array => ['results' => ($this->rows[(string)($config['filters']['schema'] ?? '')] ?? [])]
		);

		$names = array_map(
			static fn (\ReflectionParameter $parameter): string => $parameter->getName(),
			(new ReflectionMethod(ObjectService::class, 'saveObject'))->getParameters()
		);
		$objectService->method('saveObject')->willReturnCallback(
			function (...$args) use ($names): ObjectEntity {
				$named = array_combine(array_slice($names, 0, count($args)), $args);
				$entity = new ObjectEntity();
				$entity->setUuid('saved-' . count($this->rows, COUNT_RECURSIVE));
				$entity->setObject(is_array($args[0]) === true ? $args[0] : []);
				$this->rows[(string)($named['schema'] ?? '')][] = $entity;

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
	 * A pull subscription that matches every event.
	 *
	 * @param string      $uuid         The subscription uuid.
	 * @param string|null $organisation The owning organisation, or null for none.
	 *
	 * @return ObjectEntity
	 */
	private function subscription(string $uuid, ?string $organisation): ObjectEntity {
		$subscription = new ObjectEntity();
		$subscription->setUuid($uuid);
		$subscription->setObject(['style' => 'pull', 'status' => 'active']);
		if ($organisation !== null) {
			$subscription->setOrganisation($organisation);
		}

		return $subscription;
	}//end subscription()

	/**
	 * The subscriptions that got a message.
	 *
	 * @param array<int, ObjectEntity> $messages The created messages.
	 *
	 * @return array<int, string>
	 */
	private function subscribers(array $messages): array {
		$subscribers = array_map(static fn (ObjectEntity $message): string => (string)$message->getObject()['subscription'], $messages);
		sort($subscribers);

		return $subscribers;
	}//end subscribers()

	/**
	 * Organisation A's object write reaches A's subscription and the one without an organisation, never B's.
	 *
	 * @return void
	 */
	public function testAnObjectEventReachesOnlyItsOwnOrganisation(): void {
		$object = new ObjectEntity();
		$object->setUuid('case-1');
		$object->setObject(['type' => 'case', 'title' => 'Bezwaar 2026-17']);
		$object->setOrganisation(self::ORG_A);

		$this->service->handleObjectCreated($object);

		$event = $this->rows['event'][0];
		$this->assertSame(self::ORG_A, $event->getObject()[EventService::ORGANISATION_ATTRIBUTE]);
		$this->assertSame(['sub-a', 'sub-none'], $this->subscribers($this->rows['event_message']));
	}//end testAnObjectEventReachesOnlyItsOwnOrganisation()

	/**
	 * An update and a delete are kept to the organisation the same way.
	 *
	 * @return void
	 */
	public function testUpdatesAndDeletesStayInTheirOrganisation(): void {
		$old = new ObjectEntity();
		$old->setUuid('case-2');
		$old->setObject(['type' => 'case']);
		$new = new ObjectEntity();
		$new->setUuid('case-2');
		$new->setObject(['type' => 'case', 'status' => 'closed']);
		$new->setOrganisation(self::ORG_B);

		$this->service->handleObjectUpdated($old, $new);
		$this->service->handleObjectDeleted($new);

		$this->assertSame(['sub-b', 'sub-b', 'sub-none', 'sub-none'], $this->subscribers($this->rows['event_message']));
	}//end testUpdatesAndDeletesStayInTheirOrganisation()

	/**
	 * An event that names no organisation, such as a system event, still reaches every subscription.
	 *
	 * @return void
	 */
	public function testAnEventWithoutAnOrganisationReachesEverySubscription(): void {
		$messages = $this->service->emitCloudEvent(type: 'nl.conduction.test', source: 'integriq', subject: null, data: []);

		$this->assertSame(['sub-a', 'sub-b', 'sub-none'], $this->subscribers($messages));
	}//end testAnEventWithoutAnOrganisationReachesEverySubscription()

}//end class

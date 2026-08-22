<?php

/**
 * Unit tests for NotificatiesSubscriberService.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/specs/notificaties-api-connector/spec.md
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Service;

use OCA\Integriq\Service\CallService;
use OCA\Integriq\Service\EventService;
use OCA\Integriq\Service\NotificatiesSubscriberService;
use OCA\Integriq\Service\WebhookSignatureService;
use OCA\Integriq\Tests\Helpers\ObjectServiceMockBuilder;
use OCP\IURLGenerator;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Tests for the ZGW Notificaties API abonnement lifecycle + notification
 * normalization/publish-body mapping.
 *
 * @spec openspec/specs/notificaties-api-connector/spec.md
 */
class NotificatiesSubscriberServiceTest extends TestCase {

	/**
	 * @var NotificatiesSubscriberService
	 */
	private NotificatiesSubscriberService $service;

	/**
	 * @var \PHPUnit\Framework\MockObject\MockObject
	 */
	private $objectService;

	/**
	 * @var CallService|\PHPUnit\Framework\MockObject\MockObject
	 */
	private $callService;

	/**
	 * @var EventService|\PHPUnit\Framework\MockObject\MockObject
	 */
	private $eventService;

	/**
	 * @var IURLGenerator|\PHPUnit\Framework\MockObject\MockObject
	 */
	private $urlGenerator;

	/**
	 * Every saveObject() call made during a test, in order — {schema, object, uuid}.
	 *
	 * @var array<int, array{schema: string, object: array, uuid: string}>
	 */
	private array $savedObjects = [];

	/**
	 * Set up test fixtures.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->savedObjects = [];

		$this->objectService = ObjectServiceMockBuilder::make($this);
		$this->objectService->method('saveObject')->willReturnCallback(
			function (array $object, string $register, string $schema, ?string $uuid = null) {
				$id = ($uuid ?? ('generated-' . $schema . '-' . count($this->savedObjects)));
				$this->savedObjects[] = ['schema' => $schema, 'object' => $object, 'uuid' => $id];

				return ObjectServiceMockBuilder::objectEntity($this, $object, $id);
			}
		);

		$this->callService = $this->createMock(CallService::class);
		$this->eventService = $this->createMock(EventService::class);
		$logger = $this->createMock(LoggerInterface::class);
		$this->urlGenerator = $this->createMock(IURLGenerator::class);
		$this->urlGenerator->method('linkToRouteAbsolute')->willReturn('https://cloud.example/apps/openconnector/api/notificaties/callback/abon-1');

		$this->service = new NotificatiesSubscriberService(
			$this->objectService,
			$this->callService,
			$this->eventService,
			new WebhookSignatureService($logger),
			$this->urlGenerator,
			$logger
		);

	}//end setUp()

	/**
	 * A source lookup that always resolves to a reachable Notificaties API source.
	 *
	 * @param string $location The source's `location` base URL.
	 *
	 * @return void
	 */
	private function stubSourceFound(string $location = 'https://notificaties.example'): void {
		$source = ObjectServiceMockBuilder::objectEntity($this, ['location' => $location], 'source-1');
		$this->objectService->method('find')->willReturnCallback(
			function (string $id, string $register, string $schema) use ($source) {
				if ($schema === 'source') {
					return $source;
				}

				throw new \OCP\AppFramework\Db\DoesNotExistException('not found');
			}
		);

	}//end stubSourceFound()

	/**
	 * TC-1: registering an abonnement persists it active with the remote-assigned url.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/notificaties-api-connector/spec.md#requirement-abonnement-registration-update-and-deletion-against-the-remote-api-req-001
	 */
	public function testCreateAbonnementPersistsActiveWithRemoteUrl(): void {
		$this->stubSourceFound();

		$callLog = ObjectServiceMockBuilder::objectEntity(
			$this,
			[
				'statusCode' => 201,
				'response' => ['statusCode' => 201, 'body' => json_encode(['url' => 'https://notificaties.example/abonnement/uuid-1'])],
			],
			'call-log-1'
		);
		$this->callService->method('call')->willReturn($callLog);

		$result = $this->service->createAbonnement(
			[
				'name' => 'Zaken kanaal',
				'sourceId' => 'source-1',
				'kanalen' => [['naam' => 'zaken', 'filters' => []]],
			]
		);

		$data = $result->getObject();
		$this->assertSame('active', $data['status']);
		$this->assertSame('https://notificaties.example/abonnement/uuid-1', $data['url']);
		$this->assertNotEmpty($data['consumerId']);

	}//end testCreateAbonnementPersistsActiveWithRemoteUrl()

	/**
	 * TC-1 (Decision 2): the companion consumer is created with the secret
	 * sent as the abonnement's `auth` field.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/notificaties-api-connector/spec.md#requirement-abonnement-deletion-cascades-its-companion-consumer-req-004
	 */
	public function testCreateAbonnementProvisionsConsumerWithMatchingSecret(): void {
		$this->stubSourceFound();

		$capturedBody = null;
		$callLog = ObjectServiceMockBuilder::objectEntity(
			$this,
			['statusCode' => 201, 'response' => ['statusCode' => 201, 'body' => json_encode(['url' => 'https://notificaties.example/abonnement/uuid-1'])]],
			'call-log-1'
		);
		$this->callService->method('call')->willReturnCallback(
			function ($source, $endpoint, $method, $config = []) use (&$capturedBody, $callLog) {
				$capturedBody = ($config['json'] ?? []);
				return $callLog;
			}
		);

		$this->service->createAbonnement(
			[
				'name' => 'Zaken kanaal',
				'sourceId' => 'source-1',
				'kanalen' => [['naam' => 'zaken', 'filters' => []]],
			]
		);

		$consumerSave = null;
		foreach ($this->savedObjects as $saved) {
			if ($saved['schema'] === 'consumer') {
				$consumerSave = $saved;
			}
		}

		$this->assertNotNull($consumerSave);
		$this->assertSame('apiKey', $consumerSave['object']['authorizationType']);
		$this->assertNotEmpty($consumerSave['object']['authorizationConfiguration']['apiKey']);
		$this->assertSame($consumerSave['object']['authorizationConfiguration']['apiKey'], $capturedBody['auth']);

	}//end testCreateAbonnementProvisionsConsumerWithMatchingSecret()

	/**
	 * TC-2: a failed registration is recorded as an error, not silently dropped.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/notificaties-api-connector/spec.md#requirement-abonnement-registration-update-and-deletion-against-the-remote-api-req-001
	 */
	public function testCreateAbonnementRecordsErrorOnRemote503(): void {
		$this->stubSourceFound();

		$callLog = ObjectServiceMockBuilder::objectEntity($this, ['statusCode' => 503], 'call-log-2');
		$this->callService->method('call')->willReturn($callLog);

		$result = $this->service->createAbonnement(
			[
				'name' => 'Besluiten kanaal',
				'sourceId' => 'source-1',
				'kanalen' => [['naam' => 'besluiten', 'filters' => []]],
			]
		);

		$data = $result->getObject();
		$this->assertSame('error', $data['status']);
		$this->assertNotEmpty($data['lastError']);

	}//end testCreateAbonnementRecordsErrorOnRemote503()

	/**
	 * TC-17: a newly-created abonnement is briefly pending before settling —
	 * the very first saveObject() call (before any remote call) persists
	 * `status='pending'`.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/notificaties-api-connector/spec.md#requirement-abonnement-lifecycle-status-is-observable-req-007
	 */
	public function testCreateAbonnementIsPendingBeforeSettling(): void {
		$this->stubSourceFound();

		$callLog = ObjectServiceMockBuilder::objectEntity($this, ['statusCode' => 201, 'response' => ['statusCode' => 201, 'body' => '{}']], 'call-log-3');
		$this->callService->method('call')->willReturn($callLog);

		$this->service->createAbonnement(
			[
				'name' => 'Documenten kanaal',
				'sourceId' => 'source-1',
				'kanalen' => [['naam' => 'documenten', 'filters' => []]],
			]
		);

		$firstAbonnementSave = null;
		foreach ($this->savedObjects as $saved) {
			if ($saved['schema'] === 'notificaties_abonnement') {
				$firstAbonnementSave = $saved;
				break;
			}
		}

		$this->assertNotNull($firstAbonnementSave);
		$this->assertSame('pending', $firstAbonnementSave['object']['status']);

	}//end testCreateAbonnementIsPendingBeforeSettling()

	/**
	 * TC-3: deleting an abonnement that still exists remotely fails safely.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/notificaties-api-connector/spec.md#requirement-abonnement-registration-update-and-deletion-against-the-remote-api-req-001
	 */
	public function testDeleteAbonnementFailsSafelyOnRemote500(): void {
		$abonnement = ObjectServiceMockBuilder::objectEntity(
			$this,
			['status' => 'active', 'sourceId' => 'source-1', 'url' => 'https://notificaties.example/abonnement/uuid-1', 'consumerId' => 'consumer-1'],
			'abon-1'
		);
		$this->objectService->method('find')->willReturnCallback(
			function (string $id, string $register, string $schema) use ($abonnement) {
				if ($schema === 'notificaties_abonnement') {
					return $abonnement;
				}

				if ($schema === 'source') {
					return ObjectServiceMockBuilder::objectEntity($this, ['location' => 'https://notificaties.example'], 'source-1');
				}

				throw new \OCP\AppFramework\Db\DoesNotExistException('not found');
			}
		);

		$callLog = ObjectServiceMockBuilder::objectEntity($this, ['statusCode' => 500], 'call-log-4');
		$this->callService->method('call')->willReturn($callLog);
		$this->objectService->expects($this->never())->method('deleteObject');

		$result = $this->service->deleteAbonnement('abon-1');
		$data = $result->getObject();

		$this->assertNotSame('deleted', $data['status']);
		$this->assertNotEmpty($data['lastError']);

	}//end testDeleteAbonnementFailsSafelyOnRemote500()

	/**
	 * TC-9: deleting an abonnement removes its companion consumer.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/notificaties-api-connector/spec.md#requirement-abonnement-deletion-cascades-its-companion-consumer-req-004
	 */
	public function testDeleteAbonnementCascadesConsumer(): void {
		$abonnement = ObjectServiceMockBuilder::objectEntity(
			$this,
			['status' => 'active', 'sourceId' => 'source-1', 'url' => 'https://notificaties.example/abonnement/uuid-1', 'consumerId' => 'consumer-1'],
			'abon-2'
		);
		$this->objectService->method('find')->willReturnCallback(
			function (string $id, string $register, string $schema) use ($abonnement) {
				if ($schema === 'notificaties_abonnement') {
					return $abonnement;
				}

				if ($schema === 'source') {
					return ObjectServiceMockBuilder::objectEntity($this, ['location' => 'https://notificaties.example'], 'source-1');
				}

				throw new \OCP\AppFramework\Db\DoesNotExistException('not found');
			}
		);

		$callLog = ObjectServiceMockBuilder::objectEntity($this, ['statusCode' => 200], 'call-log-5');
		$this->callService->method('call')->willReturn($callLog);
		$this->objectService->expects($this->once())->method('deleteObject')->with($this->equalTo('consumer-1'));

		$result = $this->service->deleteAbonnement('abon-2');
		$data = $result->getObject();

		$this->assertSame('deleted', $data['status']);

	}//end testDeleteAbonnementCascadesConsumer()

	/**
	 * TC-10: a consumer-deletion failure does not block the abonnement's deleted status.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/notificaties-api-connector/spec.md#requirement-abonnement-deletion-cascades-its-companion-consumer-req-004
	 */
	public function testDeleteAbonnementConsumerDeleteFailureDoesNotBlock(): void {
		$abonnement = ObjectServiceMockBuilder::objectEntity(
			$this,
			['status' => 'active', 'sourceId' => 'source-1', 'url' => 'https://notificaties.example/abonnement/uuid-1', 'consumerId' => 'consumer-2'],
			'abon-3'
		);
		$this->objectService->method('find')->willReturnCallback(
			function (string $id, string $register, string $schema) use ($abonnement) {
				if ($schema === 'notificaties_abonnement') {
					return $abonnement;
				}

				if ($schema === 'source') {
					return ObjectServiceMockBuilder::objectEntity($this, ['location' => 'https://notificaties.example'], 'source-1');
				}

				throw new \OCP\AppFramework\Db\DoesNotExistException('not found');
			}
		);

		$callLog = ObjectServiceMockBuilder::objectEntity($this, ['statusCode' => 200], 'call-log-6');
		$this->callService->method('call')->willReturn($callLog);
		$this->objectService->method('deleteObject')->willThrowException(new \RuntimeException('consumer delete failed'));

		$result = $this->service->deleteAbonnement('abon-3');
		$data = $result->getObject();

		$this->assertSame('deleted', $data['status']);

	}//end testDeleteAbonnementConsumerDeleteFailureDoesNotBlock()

	/**
	 * TC-6/REQ-003: an authenticated, well-formed notification is normalized
	 * via emitCloudEvent() with the correct type/source/subject.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/notificaties-api-connector/spec.md#requirement-inbound-notifications-are-normalized-into-the-existing-cloudevents-pipe-req-003
	 */
	public function testHandleInboundNotificationEmitsCloudEvent(): void {
		$this->eventService->expects($this->once())
			->method('emitCloudEvent')
			->with(
				$this->equalTo('nl.conduction.zgw.notificatie.zaak'),
				$this->equalTo('/notificaties-api/zaken'),
				$this->equalTo('https://zaken.example/api/v1/zaken/uuid-1'),
				$this->callback(fn ($data) => $data['abonnementId'] === 'abon-1' && $data['kanaal'] === 'zaken')
			)
			->willReturn([]);

		$this->service->handleInboundNotification(
			'abon-1',
			[
				'kanaal' => 'zaken',
				'hoofdObject' => 'https://zaken.example/api/v1/zaken/uuid-1',
				'resource' => 'zaak',
				'resourceUrl' => 'https://zaken.example/api/v1/zaken/uuid-1',
				'actie' => 'create',
				'aanmaakdatum' => '2026-07-15T10:00:00Z',
				'kenmerken' => ['bronorganisatie' => '123443210'],
			]
		);

	}//end testHandleInboundNotificationEmitsCloudEvent()

	/**
	 * TC-8/REQ-003: a malformed notification body (missing kanaal) is
	 * rejected before emitCloudEvent is called.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/notificaties-api-connector/spec.md#requirement-inbound-notifications-are-normalized-into-the-existing-cloudevents-pipe-req-003
	 */
	public function testHandleInboundNotificationRejectsMissingKanaal(): void {
		$this->eventService->expects($this->never())->method('emitCloudEvent');

		$this->expectException(\InvalidArgumentException::class);

		$this->service->handleInboundNotification(
			'abon-1',
			[
				'resource' => 'zaak',
				'actie' => 'create',
				'aanmaakdatum' => '2026-07-15T10:00:00Z',
			]
		);

	}//end testHandleInboundNotificationRejectsMissingKanaal()

	/**
	 * TC-14: an OR object update event is mapped to an update notification.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/notificaties-api-connector/spec.md#requirement-zgw-notification-publish-body-shape-req-005
	 */
	public function testBuildNotificationBodyMapsUpdateEvent(): void {
		$event = ObjectServiceMockBuilder::objectEntity(
			$this,
			[
				'type' => 'com.nextcloud.openregister.object.updated',
				'subject' => 'uuid-1',
				'time' => '2026-07-15T09:00:00Z',
				'data' => [],
			],
			'event-1'
		);

		$body = NotificatiesSubscriberService::buildNotificationBody(
			$event,
			['kind' => 'notificaties', 'channel' => 'zaken', 'resourceField' => null]
		);

		$this->assertSame('update', $body['actie']);
		$this->assertSame('uuid-1', $body['hoofdObject']);
		$this->assertSame('2026-07-15T09:00:00Z', $body['aanmaakdatum']);
		$this->assertSame('object', $body['resource']);
		$this->assertSame('zaken', $body['kanaal']);

	}//end testBuildNotificationBodyMapsUpdateEvent()

	/**
	 * TC-15: static kenmerken merge with event-supplied kenmerken, event
	 * wins on key collision.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/notificaties-api-connector/spec.md#requirement-zgw-notification-publish-body-shape-req-005
	 */
	public function testBuildNotificationBodyKenmerkenMergeEventWins(): void {
		$event = ObjectServiceMockBuilder::objectEntity(
			$this,
			[
				'type' => 'com.nextcloud.openregister.object.updated',
				'subject' => 'uuid-1',
				'time' => '2026-07-15T09:00:00Z',
				'data' => [
					'characteristics' => [
						'bronorganisatie' => '123443210',
						'zaaktype' => 'https://zaken.example/zaaktypen/1',
					],
				],
			],
			'event-2'
		);

		$body = NotificatiesSubscriberService::buildNotificationBody(
			$event,
			['kind' => 'notificaties', 'channel' => 'zaken', 'characteristics' => ['bronorganisatie' => '000000000']]
		);

		$this->assertSame(
			[
				'bronorganisatie' => '123443210',
				'zaaktype' => 'https://zaken.example/zaaktypen/1',
			],
			$body['kenmerken']
		);

	}//end testBuildNotificationBodyKenmerkenMergeEventWins()
}//end class

<?php

/**
 * Integriq — digital post send, status and inbound tests.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Service\DigitalPost
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://www.integriq.nl
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Service\DigitalPost;

use OCA\Integriq\BackgroundJob\DigitalPostInboundJob;
use OCA\Integriq\Event\DigitalPostDeliveredEvent;
use OCA\Integriq\Event\DigitalPostSendRequestedEvent;
use OCA\Integriq\Service\ConnectionStore;
use OCA\Integriq\Service\DigitalPost\DigitalPostProviderInterface;
use OCA\Integriq\Service\DigitalPost\DigitalPostProviderRegistry;
use OCA\Integriq\Service\DigitalPost\DigitalPostResult;
use OCA\Integriq\Service\DigitalPost\DigitalPostService;
use OCA\Integriq\Service\Mail\IntakeDocumentDispatcher;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService as OrObjectService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventDispatcher;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * REQ-DPA-002 and REQ-DPA-003.
 *
 * @spec openspec/changes/berichtenbox-digital-post-adapter/specs/digital-post-adapter/spec.md#requirement-a-send-is-a-typed-command-with-a-tracked-message-req-dpa-002
 */
class DigitalPostServiceTest extends TestCase {
	/**
	 * Every object saved through the doubled object service.
	 *
	 * @var array<int,array<string,mixed>>
	 */
	private array $saved = [];

	/**
	 * Every event the doubled dispatcher saw.
	 *
	 * @var array<int,Event>
	 */
	private array $dispatched = [];

	/**
	 * Reset the recorders.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->saved = [];
		$this->dispatched = [];
	}//end setUp()

	/**
	 * A provider double answering with one canned result.
	 *
	 * @param string $providerId The provider id.
	 * @param DigitalPostResult|null $result What send() answers.
	 *
	 * @return DigitalPostProviderInterface The double.
	 */
	private function provider(string $providerId, ?DigitalPostResult $result = null): DigitalPostProviderInterface {
		$provider = $this->createMock(DigitalPostProviderInterface::class);
		$provider->method('getProviderId')->willReturn($providerId);
		$provider->method('send')->willReturn(
			($result ?? DigitalPostResult::accepted(DigitalPostResult::STATUS_SENT, 'ref-1'))
		);

		return $provider;
	}//end provider()

	/**
	 * Build the service over a configured source.
	 *
	 * @param DigitalPostProviderInterface $provider The binding.
	 * @param array<string,mixed>|null $sourceConfig The source configuration, or null for no such source.
	 *
	 * @return DigitalPostService The service under test.
	 */
	private function service(DigitalPostProviderInterface $provider, ?array $sourceConfig): DigitalPostService {
		$store = $this->getMockBuilder(ConnectionStore::class)
			->disableOriginalConstructor()
			->onlyMethods(['findSourceBySlug'])
			->getMock();

		if ($sourceConfig === null) {
			$store->method('findSourceBySlug')->willReturn(null);
		} else {
			$source = $this->createMock(ObjectEntity::class);
			$source->method('getObject')->willReturn(['configuration' => $sourceConfig]);
			$store->method('findSourceBySlug')->willReturn($source);
		}

		$objectService = $this->createMock(OrObjectService::class);
		$objectService->method('saveObject')->willReturnCallback(
			function (array $object, string $register, string $schema, ?string $uuid = null) {
				$this->saved[] = $object;
				$entity = $this->createMock(ObjectEntity::class);
				$entity->method('getUuid')->willReturn(($uuid ?? 'msg-1'));

				return $entity;
			}
		);

		$dispatcher = $this->createMock(IEventDispatcher::class);
		$dispatcher->method('dispatchTyped')->willReturnCallback(
			function (Event $event): void {
				$this->dispatched[] = $event;
			}
		);

		return new DigitalPostService(
			new DigitalPostProviderRegistry([$provider]),
			$store,
			$objectService,
			$dispatcher,
			$this->createMock(LoggerInterface::class)
		);
	}//end service()

	/**
	 * A send request from another app.
	 *
	 * @return DigitalPostSendRequestedEvent The event.
	 */
	private function request(): DigitalPostSendRequestedEvent {
		return new DigitalPostSendRequestedEvent(
			'dossiq',
			'berichtenbox-source',
			'999993653',
			'Uw aanvraag',
			'Beste heer De Vries,',
			[['name' => 'besluit.pdf', 'url' => 'https://example.test/besluit.pdf']],
			'behandelaar1',
			'corr-1'
		);
	}//end request()

	/**
	 * A case app sends a letter: the message exists, the result slot carries
	 * its id, and the status change is announced.
	 *
	 * @return void
	 */
	public function testACaseAppSendsALetterAndGetsTheMessageId(): void {
		$service = $this->service(
			$this->provider('berichtenbox'),
			['providerId' => 'berichtenbox', 'certificateRef' => 'pki', 'senderOin' => '000']
		);
		$event = $this->request();

		$service->handleSendRequest($event);

		$this->assertTrue($event->isHandled());
		$this->assertSame('msg-1', $event->getMessageId());
		$this->assertNull($event->getRefusal());

		$this->assertCount(2, $this->saved, 'The message is stored before the provider is called, and updated after.');
		$this->assertSame(DigitalPostResult::STATUS_QUEUED, $this->saved[0]['status']);
		$this->assertSame(DigitalPostResult::STATUS_SENT, $this->saved[1]['status']);

		$this->assertCount(1, $this->dispatched);
		$this->assertInstanceOf(DigitalPostDeliveredEvent::class, $this->dispatched[0]);
		$this->assertSame('behandelaar1', $this->dispatched[0]->getRequestedBy());
	}//end testACaseAppSendsALetterAndGetsTheMessageId()

	/**
	 * A failed send keeps the letter, with its attachments, and reports the
	 * provider's reason.
	 *
	 * @return void
	 */
	public function testAFailedSendKeepsTheLetterAndItsAttachments(): void {
		$service = $this->service(
			$this->provider('berichtenbox', DigitalPostResult::refused('Ontvanger heeft geen Berichtenbox')),
			['providerId' => 'berichtenbox']
		);
		$event = $this->request();

		$service->handleSendRequest($event);

		$stored = end($this->saved);
		$this->assertSame(DigitalPostResult::STATUS_FAILED, $stored['status']);
		$this->assertSame('Ontvanger heeft geen Berichtenbox', $stored['lastError']);
		$this->assertCount(1, $stored['attachments'], 'The PDF is still attached, so the letter can be retried.');

		$this->assertNotNull($event->getRefusal());
		$this->assertSame('provider_refused', $event->getRefusal()['code']);
		$this->assertNull($event->getMessageId(), 'A refusal and a message id are never both returned.');
	}//end testAFailedSendKeepsTheLetterAndItsAttachments()

	/**
	 * A request naming a source nobody configured is refused, and nothing is
	 * stored.
	 *
	 * @return void
	 */
	public function testARequestForAnUnknownSourceIsRefused(): void {
		$service = $this->service($this->provider('berichtenbox'), null);
		$event = $this->request();

		$service->handleSendRequest($event);

		$this->assertTrue($event->isHandled());
		$this->assertSame('unknown_source', $event->getRefusal()['code']);
		$this->assertSame([], $this->saved);
	}//end testARequestForAnUnknownSourceIsRefused()

	/**
	 * A source naming a provider nothing answers to is refused, naming the
	 * providers that do exist.
	 *
	 * @return void
	 */
	public function testASourceNamingAnUnknownProviderIsRefused(): void {
		$service = $this->service($this->provider('log'), ['providerId' => 'carrier-pigeon']);
		$event = $this->request();

		$service->handleSendRequest($event);

		$this->assertSame('unknown_provider', $event->getRefusal()['code']);
		$this->assertStringContainsString('carrier-pigeon', $event->getRefusal()['reason']);
		$this->assertSame([], $this->saved, 'Nothing is tracked for a letter that could never be sent.');
	}//end testASourceNamingAnUnknownProviderIsRefused()

	/**
	 * A status poll that finds a change stores it and announces it; one that
	 * finds none does neither.
	 *
	 * @return void
	 */
	public function testAStatusPollAnnouncesOnlyRealChanges(): void {
		$provider = $this->createMock(DigitalPostProviderInterface::class);
		$provider->method('getProviderId')->willReturn('berichtenbox');
		$provider->method('status')->willReturnOnConsecutiveCalls(
			DigitalPostResult::accepted(DigitalPostResult::STATUS_DELIVERED, 'ref-1'),
			DigitalPostResult::accepted(DigitalPostResult::STATUS_SENT, 'ref-2')
		);

		$service = $this->service($provider, ['providerId' => 'berichtenbox']);

		$changed = $service->pollStatuses(
			[
				[
					'uuid' => 'msg-1',
					'providerId' => 'berichtenbox',
					'providerReference' => 'ref-1',
					'status' => DigitalPostResult::STATUS_SENT,
					'requestedBy' => 'behandelaar1',
					'sourceId' => 'berichtenbox-source',
				],
				[
					'uuid' => 'msg-2',
					'providerId' => 'berichtenbox',
					'providerReference' => 'ref-2',
					'status' => DigitalPostResult::STATUS_SENT,
					'requestedBy' => 'behandelaar1',
					'sourceId' => 'berichtenbox-source',
				],
			]
		);

		$this->assertSame(1, $changed);
		$this->assertCount(1, $this->dispatched);
		$this->assertSame(DigitalPostResult::STATUS_DELIVERED, $this->dispatched[0]->getStatus());
		$this->assertSame(DigitalPostResult::STATUS_SENT, $this->dispatched[0]->getPreviousStatus());
	}//end testAStatusPollAnnouncesOnlyRealChanges()

	/**
	 * Health answers what a source page shows: last send, last error, queue
	 * depth.
	 *
	 * @return void
	 */
	public function testHealthReportsLastSendLastErrorAndQueueDepth(): void {
		$service = $this->service($this->provider('log'), ['providerId' => 'log']);

		$health = $service->health(
			[
				['status' => 'queued', 'created' => '2026-09-18T08:00:00+00:00', 'lastError' => ''],
				['status' => 'failed', 'created' => '2026-09-18T09:00:00+00:00', 'lastError' => 'Ontvanger onbekend'],
				['status' => 'queued', 'created' => '2026-09-17T08:00:00+00:00', 'lastError' => ''],
			]
		);

		$this->assertSame('2026-09-18T09:00:00+00:00', $health['lastSend']);
		$this->assertSame('Ontvanger onbekend', $health['lastError']);
		$this->assertSame(2, $health['queueDepth']);
	}//end testHealthReportsLastSendLastErrorAndQueueDepth()

	/**
	 * A citizen reply arrives: one intake event per item, on channel
	 * digitalPost, carrying the sender.
	 *
	 * @return void
	 */
	public function testACitizenReplyReachesTheIntakeInbox(): void {
		$provider = $this->createMock(DigitalPostProviderInterface::class);
		$provider->method('getProviderId')->willReturn('berichtenbox');
		$provider->method('pollInbound')->willReturn(
			[
				[
					'sender' => '999993653',
					'subject' => 'Reactie op uw brief',
					'document' => ['name' => 'reactie.pdf'],
					'receivedAt' => '2026-09-18T10:00:00+00:00',
				],
			]
		);

		$offered = [];
		$intake = $this->getMockBuilder(IntakeDocumentDispatcher::class)
			->disableOriginalConstructor()
			->onlyMethods(['dispatch', 'isAvailable'])
			->getMock();
		$intake->method('dispatch')->willReturnCallback(
			function (array $payload) use (&$offered): bool {
				$offered[] = $payload;

				return true;
			}
		);

		$job = new DigitalPostInboundJob(
			$this->createMock(ITimeFactory::class),
			new DigitalPostProviderRegistry([$provider]),
			$intake,
			$this->createMock(OrObjectService::class),
			$this->createMock(LoggerInterface::class)
		);

		$count = $job->pollSource(
			['slug' => 'berichtenbox-source', 'configuration' => ['providerId' => 'berichtenbox']]
		);

		$this->assertSame(1, $count);
		$this->assertCount(1, $offered);
		$this->assertSame('digitalPost', $offered[0]['channel']);
		$this->assertSame('999993653', $offered[0]['metadata']['sender']);
	}//end testACitizenReplyReachesTheIntakeInbox()

	/**
	 * A source naming no digital post provider is not polled at all.
	 *
	 * @return void
	 */
	public function testASourceWithNoDigitalPostProviderIsNotPolled(): void {
		$provider = $this->createMock(DigitalPostProviderInterface::class);
		$provider->method('getProviderId')->willReturn('berichtenbox');
		$provider->expects($this->never())->method('pollInbound');

		$intake = $this->getMockBuilder(IntakeDocumentDispatcher::class)
			->disableOriginalConstructor()
			->onlyMethods(['dispatch', 'isAvailable'])
			->getMock();
		$intake->expects($this->never())->method('dispatch');

		$job = new DigitalPostInboundJob(
			$this->createMock(ITimeFactory::class),
			new DigitalPostProviderRegistry([$provider]),
			$intake,
			$this->createMock(OrObjectService::class),
			$this->createMock(LoggerInterface::class)
		);

		$this->assertSame(0, $job->pollSource(['slug' => 'een-api', 'configuration' => ['type' => 'api']]));
	}//end testASourceWithNoDigitalPostProviderIsNotPolled()
}//end class

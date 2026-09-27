<?php

/**
 * Unit tests for RodService.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 *
 * @spec openspec/changes/integriq-adapter-rod/tasks.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Service;

use OCA\Integriq\Event\RodAcknowledgementReceivedEvent;
use OCA\Integriq\Exception\RodProviderException;
use OCA\Integriq\Service\Rod\LogRodProvider;
use OCA\Integriq\Service\Rod\RodAcknowledgementTranslator;
use OCA\Integriq\Service\Rod\RodEnvelopeTranslator;
use OCA\Integriq\Service\Rod\RodProviderRegistry;
use OCA\Integriq\Service\RodService;
use OCA\Integriq\Service\Security\RawSourceResolver;
use OCA\Integriq\Tests\Helpers\ObjectServiceMockBuilder;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService as ORObjectService;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\IL10N;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Tests for the ROD send/retour orchestration (provider selection,
 * per-message persistence, event dispatch, retry isolation, AVG/BSN hygiene).
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 *
 * @spec openspec/changes/integriq-adapter-rod/specs/rod-adapter/spec.md
 */
class RodServiceTest extends TestCase {

	/**
	 * @var ORObjectService|\PHPUnit\Framework\MockObject\MockObject
	 */
	private $objectService;

	/**
	 * @var IEventDispatcher|\PHPUnit\Framework\MockObject\MockObject
	 */
	private $eventDispatcher;

	/**
	 * @var RodService
	 */
	private RodService $service;

	/**
	 * @var array<string, array<int, array{object: array, register: string|null, uuid: string|null}>>
	 */
	private array $saved = [];

	/**
	 * @var array<int, ObjectEntity>
	 */
	private array $sources = [];

	/**
	 * @var array<int, ObjectEntity>
	 */
	private array $messages = [];

	/**
	 * Dispatched events, captured for assertion.
	 *
	 * @var array<int, object>
	 */
	private array $dispatched = [];

	/**
	 * Set up test fixtures.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->objectService = $this->getMockBuilder(ORObjectService::class)
			->disableOriginalConstructor()
			->getMock();

		$l = $this->createMock(IL10N::class);
		$l->method('t')->willReturnArgument(0);
		$logger = $this->createMock(LoggerInterface::class);

		$this->saved = [];
		$this->sources = [];
		$this->messages = [];
		$this->dispatched = [];

		$this->objectService->method('findAll')->willReturnCallback(
			function (array $config): array {
				$filters = ($config['filters'] ?? []);
				$schema = ($filters['schema'] ?? null);

				if ($schema === RodService::SCHEMA_SOURCE) {
					return ['results' => $this->sources];
				}

				if ($schema === RodService::SCHEMA_MESSAGE) {
					$kenmerk = ($filters['kenmerk'] ?? null);
					if ($kenmerk !== null) {
						$matching = array_values(
							array_filter(
								$this->messages,
								static fn (ObjectEntity $m) => ($m->getObject()['kenmerk'] ?? null) === $kenmerk
							)
						);
						return ['results' => $matching];
					}

					return ['results' => $this->messages];
				}

				return ['results' => []];
			}
		);

		$this->objectService->method('saveObject')->willReturnCallback(
			function ($object, $register = null, $schema = null, $uuid = null): ObjectEntity {
				$key = (string)$schema;
				$this->saved[$key][] = ['object' => $object, 'register' => $register, 'uuid' => $uuid];
				return ObjectServiceMockBuilder::objectEntity($this, $object, ($uuid ?? 'saved-uuid-' . count($this->saved[$key])));
			}
		);

		$this->eventDispatcher = $this->createMock(IEventDispatcher::class);
		$this->eventDispatcher->method('dispatchTyped')->willReturnCallback(
			function ($event): void {
				$this->dispatched[] = $event;
			}
		);

		$this->service = new RodService(
			$this->objectService,
			new RodProviderRegistry([new LogRodProvider()]),
			new RodEnvelopeTranslator(),
			new RodAcknowledgementTranslator(),
			$this->eventDispatcher,
			$l,
			$logger,
			new RawSourceResolver($this->objectService, $logger)
		);

	}//end setUp()

	/**
	 * A ROD source entity (type rod, log provider by default).
	 *
	 * @param array $configuration Extra configuration merged over the default.
	 * @param string $uuid Entity uuid.
	 *
	 * @return ObjectEntity
	 */
	private function sourceEntity(array $configuration = [], string $uuid = 'source-1'): ObjectEntity {
		return ObjectServiceMockBuilder::objectEntity(
			$this,
			['type' => 'rod', 'isEnabled' => true, 'configuration' => array_merge(['provider' => 'log'], $configuration)],
			$uuid
		);
	}//end sourceEntity()

	/**
	 * resolveActiveSource() throws when no active source is configured.
	 *
	 * @return void
	 */
	public function testResolveActiveSourceThrowsWhenNoneConfigured(): void {
		$this->expectException(RodProviderException::class);
		$this->service->resolveActiveSource();

	}//end testResolveActiveSourceThrowsWhenNoneConfigured()

	/**
	 * A successful outbound send persists a sent record with its ref and a
	 * SHA-256 BSN hash, never the raw BSN.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/integriq-adapter-rod/specs/rod-adapter/spec.md#scenario-a-successful-outbound-send-persists-a-sent-record-with-its-ref
	 * @spec openspec/changes/integriq-adapter-rod/specs/rod-adapter/spec.md#scenario-the-sent-envelope-carries-the-raw-bsn-but-the-audit-record-does-not
	 */
	public function testSuccessfulSendPersistsSentRecordWithHashedBsn(): void {
		$this->sources[] = $this->sourceEntity();

		$result = $this->service->sendBericht(
			'inschrijving',
			'seed-kenmerk-001',
			['bsn' => '999999990', 'inschrijvingsdatum' => '2026-09-01', 'leerjaar' => 4, 'groep' => '4B']
		);

		$this->assertSame('inschrijving', $result['berichtsoort']);
		$this->assertSame('sent', $result['status']);
		$this->assertStringStartsWith('MOCK-ROD-', $result['ref']);

		$saved = $this->saved[RodService::SCHEMA_MESSAGE][0]['object'];
		$this->assertSame('sent', $saved['status']);
		$this->assertSame(hash('sha256', '999999990'), $saved['bsnHash']);
		$this->assertArrayNotHasKey('bsn', $saved);

	}//end testSuccessfulSendPersistsSentRecordWithHashedBsn()

	/**
	 * A translation failure never persists a record and never reaches the transport.
	 *
	 * @return void
	 */
	public function testTranslationFailureNeverPersistsARecord(): void {
		$this->sources[] = $this->sourceEntity();

		try {
			$this->service->sendBericht('inschrijving', 'k1', ['bsn' => '999999990']);
			$this->fail('Expected RodTranslationException was not thrown.');
		} catch (\OCA\Integriq\Exception\RodTranslationException $exception) {
			$this->assertArrayNotHasKey(RodService::SCHEMA_MESSAGE, $this->saved);
		}

	}//end testTranslationFailureNeverPersistsARecord()

	/**
	 * receiveReturn() dispatches RodAcknowledgementReceivedEvent with accepted true
	 * for an accepted signaalcode, and persists an acknowledged record.
	 *
	 * @return void
	 */
	public function testReceiveReturnDispatchesAcceptedEvent(): void {
		$this->sources[] = $this->sourceEntity();
		$this->messages[] = ObjectServiceMockBuilder::objectEntity(
			$this,
			['direction' => 'outbound', 'berichtsoort' => 'schooladvies', 'kenmerk' => 'seed-kenmerk-002', 'status' => 'sent'],
			'msg-1'
		);

		$xml = file_get_contents(__DIR__ . '/../../fixtures/rod/retour-accepted.xml');
		$this->service->receiveReturn((string)$xml);

		$this->assertCount(1, $this->dispatched);
		$event = $this->dispatched[0];
		$this->assertInstanceOf(RodAcknowledgementReceivedEvent::class, $event);
		$this->assertTrue($event->isAccepted());
		$this->assertSame('seed-kenmerk-002', $event->getKenmerk());
		$this->assertSame('schooladvies', $event->getBerichtsoort());

		$saved = $this->saved[RodService::SCHEMA_MESSAGE][0]['object'];
		$this->assertSame('acknowledged', $saved['status']);

	}//end testReceiveReturnDispatchesAcceptedEvent()

	/**
	 * receiveReturn() with an unknown kenmerk still dispatches the event (the
	 * caller/controller always acknowledges receipt; unresolved is logged, not thrown).
	 *
	 * @return void
	 */
	public function testReceiveReturnWithUnknownKenmerkStillDispatches(): void {
		$this->sources[] = $this->sourceEntity();

		$xml = file_get_contents(__DIR__ . '/../../fixtures/rod/retour-rejected.xml');
		$this->service->receiveReturn((string)$xml);

		$this->assertCount(1, $this->dispatched);
		$this->assertFalse($this->dispatched[0]->isAccepted());

		$saved = $this->saved[RodService::SCHEMA_MESSAGE][0]['object'];
		$this->assertSame('No matching outbound message found for kenmerk', $saved['error']);

	}//end testReceiveReturnWithUnknownKenmerkStillDispatches()

	/**
	 * retryFailed() retries a failed row and leaves a sent one untouched.
	 *
	 * @return void
	 */
	public function testRetryFailedRetriesOnlyFailedOrPendingRows(): void {
		$this->sources[] = $this->sourceEntity();
		$this->messages[] = ObjectServiceMockBuilder::objectEntity(
			$this,
			['direction' => 'outbound', 'berichtsoort' => 'inschrijving', 'kenmerk' => 'k-failed', 'status' => 'failed', 'ref' => 'MOCK-ROD-1'],
			'msg-failed'
		);
		$this->messages[] = ObjectServiceMockBuilder::objectEntity(
			$this,
			['direction' => 'outbound', 'berichtsoort' => 'inschrijving', 'kenmerk' => 'k-sent', 'status' => 'sent', 'ref' => 'MOCK-ROD-2'],
			'msg-sent'
		);

		$retried = $this->service->retryFailed();

		$this->assertSame(1, $retried);
		$this->assertCount(1, $this->saved[RodService::SCHEMA_MESSAGE]);
		$this->assertSame('sent', $this->saved[RodService::SCHEMA_MESSAGE][0]['object']['status']);

	}//end testRetryFailedRetriesOnlyFailedOrPendingRows()
}//end class

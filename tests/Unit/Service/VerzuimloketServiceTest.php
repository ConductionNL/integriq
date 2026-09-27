<?php

/**
 * Unit tests for VerzuimloketService.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 *
 * @spec openspec/changes/integriq-adapter-verzuimloket/tasks.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Service;

use OCA\Integriq\Event\VerzuimloketAcknowledgementReceivedEvent;
use OCA\Integriq\Exception\VerzuimloketProviderException;
use OCA\Integriq\Exception\VerzuimloketTranslationException;
use OCA\Integriq\Service\Security\RawSourceResolver;
use OCA\Integriq\Service\Verzuimloket\LogVerzuimloketProvider;
use OCA\Integriq\Service\Verzuimloket\VerzuimloketAcknowledgementTranslator;
use OCA\Integriq\Service\Verzuimloket\VerzuimloketEnvelopeTranslator;
use OCA\Integriq\Service\Verzuimloket\VerzuimloketProviderRegistry;
use OCA\Integriq\Service\VerzuimloketService;
use OCA\Integriq\Tests\Helpers\ObjectServiceMockBuilder;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService as ORObjectService;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\IL10N;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Tests for the Verzuimloket send/retour orchestration.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 *
 * @spec openspec/changes/integriq-adapter-verzuimloket/specs/verzuimloket-adapter/spec.md
 */
class VerzuimloketServiceTest extends TestCase {

	/**
	 * @var ORObjectService|\PHPUnit\Framework\MockObject\MockObject
	 */
	private $objectService;

	/**
	 * @var IEventDispatcher|\PHPUnit\Framework\MockObject\MockObject
	 */
	private $eventDispatcher;

	/**
	 * @var VerzuimloketService
	 */
	private VerzuimloketService $service;

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

				if ($schema === VerzuimloketService::SCHEMA_SOURCE) {
					return ['results' => $this->sources];
				}

				if ($schema === VerzuimloketService::SCHEMA_MESSAGE) {
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

		$this->service = new VerzuimloketService(
			$this->objectService,
			new VerzuimloketProviderRegistry([new LogVerzuimloketProvider()]),
			new VerzuimloketEnvelopeTranslator(),
			new VerzuimloketAcknowledgementTranslator(),
			$this->eventDispatcher,
			$l,
			$logger,
			new RawSourceResolver($this->objectService, $logger)
		);

	}//end setUp()

	/**
	 * A Verzuimloket source entity (type verzuimloket, log provider by default).
	 *
	 * @param array $configuration Extra configuration merged over the default.
	 * @param string $uuid Entity uuid.
	 *
	 * @return ObjectEntity
	 */
	private function sourceEntity(array $configuration = [], string $uuid = 'source-1'): ObjectEntity {
		return ObjectServiceMockBuilder::objectEntity(
			$this,
			['type' => 'verzuimloket', 'isEnabled' => true, 'configuration' => array_merge(['provider' => 'log'], $configuration)],
			$uuid
		);
	}//end sourceEntity()

	/**
	 * resolveActiveSource() throws when no active source is configured.
	 *
	 * @return void
	 */
	public function testResolveActiveSourceThrowsWhenNoneConfigured(): void {
		$this->expectException(VerzuimloketProviderException::class);
		$this->service->resolveActiveSource();

	}//end testResolveActiveSourceThrowsWhenNoneConfigured()

	/**
	 * A successful outbound send persists a sent record with its ref and a
	 * SHA-256 BSN hash, never the raw BSN.
	 *
	 * @return void
	 */
	public function testSuccessfulSendPersistsSentRecordWithHashedBsn(): void {
		$this->sources[] = $this->sourceEntity();

		$result = $this->service->sendMelding(
			'eerste-melding',
			'seed-verzuim-kenmerk-001',
			['bsn' => '999999990', 'windowStart' => '2026-09-01', 'windowEnd' => '2026-09-28', 'metricValue' => 16]
		);

		$this->assertSame('eerste-melding', $result['meldingType']);
		$this->assertSame('sent', $result['status']);
		$this->assertStringStartsWith('MOCK-VERZUIM-', $result['ref']);

		$saved = $this->saved[VerzuimloketService::SCHEMA_MESSAGE][0]['object'];
		$this->assertSame('sent', $saved['status']);
		$this->assertSame(hash('sha256', '999999990'), $saved['bsnHash']);
		$this->assertArrayNotHasKey('bsn', $saved);

	}//end testSuccessfulSendPersistsSentRecordWithHashedBsn()

	/**
	 * A translation failure never persists a record.
	 *
	 * @return void
	 */
	public function testTranslationFailureNeverPersistsARecord(): void {
		$this->sources[] = $this->sourceEntity();

		try {
			$this->service->sendMelding('eerste-melding', 'k1', ['bsn' => '999999990']);
			$this->fail('Expected VerzuimloketTranslationException was not thrown.');
		} catch (VerzuimloketTranslationException $exception) {
			$this->assertArrayNotHasKey(VerzuimloketService::SCHEMA_MESSAGE, $this->saved);
		}

	}//end testTranslationFailureNeverPersistsARecord()

	/**
	 * receiveReturn() dispatches VerzuimloketAcknowledgementReceivedEvent with
	 * accepted true for an accepted signaalcode, and persists an acknowledged record.
	 *
	 * @return void
	 */
	public function testReceiveReturnDispatchesAcceptedEvent(): void {
		$this->sources[] = $this->sourceEntity();
		$this->messages[] = ObjectServiceMockBuilder::objectEntity(
			$this,
			['direction' => 'outbound', 'meldingType' => 'eerste-melding', 'kenmerk' => 'seed-verzuim-kenmerk-001', 'status' => 'sent'],
			'msg-1'
		);

		$xml = file_get_contents(__DIR__ . '/../../fixtures/verzuimloket/retour-accepted.xml');
		$this->service->receiveReturn((string)$xml);

		$this->assertCount(1, $this->dispatched);
		$event = $this->dispatched[0];
		$this->assertInstanceOf(VerzuimloketAcknowledgementReceivedEvent::class, $event);
		$this->assertTrue($event->isAccepted());
		$this->assertSame('seed-verzuim-kenmerk-001', $event->getKenmerk());
		$this->assertSame('eerste-melding', $event->getMeldingType());

		$saved = $this->saved[VerzuimloketService::SCHEMA_MESSAGE][0]['object'];
		$this->assertSame('acknowledged', $saved['status']);

	}//end testReceiveReturnDispatchesAcceptedEvent()

	/**
	 * retryFailed() retries a failed row and leaves a sent one untouched.
	 *
	 * @return void
	 */
	public function testRetryFailedRetriesOnlyFailedOrPendingRows(): void {
		$this->sources[] = $this->sourceEntity();
		$this->messages[] = ObjectServiceMockBuilder::objectEntity(
			$this,
			['direction' => 'outbound', 'meldingType' => 'eerste-melding', 'kenmerk' => 'k-failed', 'status' => 'failed', 'ref' => 'MOCK-VERZUIM-1'],
			'msg-failed'
		);
		$this->messages[] = ObjectServiceMockBuilder::objectEntity(
			$this,
			['direction' => 'outbound', 'meldingType' => 'eerste-melding', 'kenmerk' => 'k-sent', 'status' => 'sent', 'ref' => 'MOCK-VERZUIM-2'],
			'msg-sent'
		);

		$retried = $this->service->retryFailed();

		$this->assertSame(1, $retried);
		$this->assertCount(1, $this->saved[VerzuimloketService::SCHEMA_MESSAGE]);
		$this->assertSame('sent', $this->saved[VerzuimloketService::SCHEMA_MESSAGE][0]['object']['status']);

	}//end testRetryFailedRetriesOnlyFailedOrPendingRows()
}//end class

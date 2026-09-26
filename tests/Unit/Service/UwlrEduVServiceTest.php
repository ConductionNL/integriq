<?php

/**
 * Unit tests for UwlrEduVService.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 *
 * @spec openspec/changes/integriq-adapter-uwlr-eduv/tasks.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Service;

use OCA\Integriq\Event\UwlrEduVAcknowledgementReceivedEvent;
use OCA\Integriq\Exception\UwlrEduVProviderException;
use OCA\Integriq\Exception\UwlrEduVTranslationException;
use OCA\Integriq\Service\Security\RawSourceResolver;
use OCA\Integriq\Service\UwlrEduV\BasispoortSyncTranslator;
use OCA\Integriq\Service\UwlrEduV\EduVExportEnvelopeTranslator;
use OCA\Integriq\Service\UwlrEduV\EntreeContentSyncTranslator;
use OCA\Integriq\Service\UwlrEduV\LogUwlrEduVProvider;
use OCA\Integriq\Service\UwlrEduV\UwlrEduVAcknowledgementTranslator;
use OCA\Integriq\Service\UwlrEduV\UwlrEduVProviderRegistry;
use OCA\Integriq\Service\UwlrEduV\UwlrExportEnvelopeTranslator;
use OCA\Integriq\Service\UwlrEduVService;
use OCA\Integriq\Tests\Helpers\ObjectServiceMockBuilder;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService as ORObjectService;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\IL10N;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Tests for the UWLR/Edu-V/Basispoort/Entree-content send/sync/retour orchestration.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 * @SuppressWarnings(PHPMD.TooManyPublicMethods)
 *
 * @spec openspec/changes/integriq-adapter-uwlr-eduv/specs/uwlr-eduv-adapter/spec.md
 */
class UwlrEduVServiceTest extends TestCase {

	/**
	 * @var ORObjectService|\PHPUnit\Framework\MockObject\MockObject
	 */
	private $objectService;

	/**
	 * @var IEventDispatcher|\PHPUnit\Framework\MockObject\MockObject
	 */
	private $eventDispatcher;

	/**
	 * @var UwlrEduVService
	 */
	private UwlrEduVService $service;

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

				if ($schema === UwlrEduVService::SCHEMA_SOURCE) {
					return ['results' => $this->sources];
				}

				if ($schema === UwlrEduVService::SCHEMA_MESSAGE) {
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

		$this->service = new UwlrEduVService(
			$this->objectService,
			new UwlrEduVProviderRegistry([new LogUwlrEduVProvider()]),
			new UwlrExportEnvelopeTranslator(),
			new EduVExportEnvelopeTranslator(),
			new BasispoortSyncTranslator(),
			new EntreeContentSyncTranslator(),
			new UwlrEduVAcknowledgementTranslator(),
			$this->eventDispatcher,
			$l,
			$logger,
			new RawSourceResolver($this->objectService, $logger)
		);

	}//end setUp()

	/**
	 * A UWLR/Edu-V source entity (type uwlr-eduv, log provider by default).
	 *
	 * @param array $configuration Extra configuration merged over the default.
	 * @param string $uuid Entity uuid.
	 *
	 * @return ObjectEntity
	 */
	private function sourceEntity(array $configuration = [], string $uuid = 'source-1'): ObjectEntity {
		return ObjectServiceMockBuilder::objectEntity(
			$this,
			['type' => 'uwlr-eduv', 'isEnabled' => true, 'configuration' => array_merge(['provider' => 'log'], $configuration)],
			$uuid
		);
	}//end sourceEntity()

	/**
	 * resolveActiveSource() throws when no active source is configured.
	 *
	 * @return void
	 */
	public function testResolveActiveSourceThrowsWhenNoneConfigured(): void {
		$this->expectException(UwlrEduVProviderException::class);
		$this->service->resolveActiveSource();

	}//end testResolveActiveSourceThrowsWhenNoneConfigured()

	/**
	 * A successful UWLR export persists a sent record with its ref.
	 *
	 * @return void
	 */
	public function testSuccessfulUwlrExportPersistsSentRecord(): void {
		$this->sources[] = $this->sourceEntity();

		$result = $this->service->sendUwlrExport('k1', 'pupil', ['eckId' => 'eck-001', 'schoolBrin' => '12AB']);

		$this->assertSame('uwlr', $result['target']);
		$this->assertSame('sent', $result['status']);
		$this->assertStringStartsWith('MOCK-UWLREDUV-', $result['ref']);

		$saved = $this->saved[UwlrEduVService::SCHEMA_MESSAGE][0]['object'];
		$this->assertSame('uwlr', $saved['target']);
		$this->assertSame('pupil', $saved['subtype']);
		$this->assertSame('export', $saved['direction']);
		$this->assertSame('sent', $saved['status']);

	}//end testSuccessfulUwlrExportPersistsSentRecord()

	/**
	 * A successful Edu-V export persists its data-service subtype.
	 *
	 * @return void
	 */
	public function testSuccessfulEduVExportPersistsSubtype(): void {
		$this->sources[] = $this->sourceEntity();

		$result = $this->service->sendEduVExport('k2', 'onderwijsgroepen', ['eckId' => 'eck-002', 'schoolBrin' => '12AB']);

		$this->assertSame('edu-v', $result['target']);
		$saved = $this->saved[UwlrEduVService::SCHEMA_MESSAGE][0]['object'];
		$this->assertSame('onderwijsgroepen', $saved['subtype']);

	}//end testSuccessfulEduVExportPersistsSubtype()

	/**
	 * A successful Basispoort sync persists direction "sync".
	 *
	 * @return void
	 */
	public function testSuccessfulBasispoortSyncPersistsSyncDirection(): void {
		$this->sources[] = $this->sourceEntity();

		$this->service->syncBasispoort('k3', ['eckId' => 'eck-003', 'schoolBrin' => '12AB', 'ssoAudience' => 'method-x']);

		$saved = $this->saved[UwlrEduVService::SCHEMA_MESSAGE][0]['object'];
		$this->assertSame('basispoort', $saved['target']);
		$this->assertSame('sync', $saved['direction']);

	}//end testSuccessfulBasispoortSyncPersistsSyncDirection()

	/**
	 * A successful Entree content sync persists direction "sync".
	 *
	 * @return void
	 */
	public function testSuccessfulEntreeContentSyncPersistsSyncDirection(): void {
		$this->sources[] = $this->sourceEntity();

		$this->service->syncEntreeContent('k4', ['eckId' => 'eck-004', 'schoolBrin' => '12AB', 'ssoAudience' => 'publisher-y']);

		$saved = $this->saved[UwlrEduVService::SCHEMA_MESSAGE][0]['object'];
		$this->assertSame('entree-content', $saved['target']);
		$this->assertSame('sync', $saved['direction']);

	}//end testSuccessfulEntreeContentSyncPersistsSyncDirection()

	/**
	 * A translation failure never persists a record.
	 *
	 * @return void
	 */
	public function testTranslationFailureNeverPersistsARecord(): void {
		$this->sources[] = $this->sourceEntity();

		try {
			$this->service->sendUwlrExport('k1', 'pupil', ['eckId' => 'eck-001']);
			$this->fail('Expected UwlrEduVTranslationException was not thrown.');
		} catch (UwlrEduVTranslationException $exception) {
			$this->assertArrayNotHasKey(UwlrEduVService::SCHEMA_MESSAGE, $this->saved);
		}

	}//end testTranslationFailureNeverPersistsARecord()

	/**
	 * receiveReturn() dispatches UwlrEduVAcknowledgementReceivedEvent with accepted true.
	 *
	 * @return void
	 */
	public function testReceiveReturnDispatchesAcceptedEvent(): void {
		$xml = file_get_contents(__DIR__ . '/../../fixtures/uwlr-eduv/retour-accepted.xml');
		$this->service->receiveReturn((string)$xml);

		$this->assertCount(1, $this->dispatched);
		$event = $this->dispatched[0];
		$this->assertInstanceOf(UwlrEduVAcknowledgementReceivedEvent::class, $event);
		$this->assertTrue($event->isAccepted());

		$saved = $this->saved[UwlrEduVService::SCHEMA_MESSAGE][0]['object'];
		$this->assertSame('acknowledged', $saved['status']);

	}//end testReceiveReturnDispatchesAcceptedEvent()

	/**
	 * receiveReturn() with an unparsable retour dispatches nothing and does not throw.
	 *
	 * @return void
	 */
	public function testReceiveReturnWithUnparsableRetourDoesNotThrowOrDispatch(): void {
		$this->service->receiveReturn('not xml at all');

		$this->assertCount(0, $this->dispatched);
		$this->assertArrayNotHasKey(UwlrEduVService::SCHEMA_MESSAGE, $this->saved);

	}//end testReceiveReturnWithUnparsableRetourDoesNotThrowOrDispatch()

	/**
	 * retryFailed() retries a failed row, across targets, and leaves a sent one untouched.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/integriq-adapter-uwlr-eduv/specs/uwlr-eduv-adapter/spec.md#scenario-a-failed-send-persists-and-is-retried-in-isolation
	 */
	public function testRetryFailedRetriesOnlyFailedRows(): void {
		$this->sources[] = $this->sourceEntity();
		$this->messages[] = ObjectServiceMockBuilder::objectEntity(
			$this,
			['target' => 'uwlr', 'kenmerk' => 'k-failed', 'status' => 'failed', 'ref' => 'MOCK-UWLREDUV-1'],
			'msg-failed'
		);
		$this->messages[] = ObjectServiceMockBuilder::objectEntity(
			$this,
			['target' => 'edu-v', 'kenmerk' => 'k-sent', 'status' => 'sent', 'ref' => 'MOCK-UWLREDUV-2'],
			'msg-sent'
		);

		$retried = $this->service->retryFailed();

		$this->assertSame(1, $retried);
		$this->assertCount(1, $this->saved[UwlrEduVService::SCHEMA_MESSAGE]);
		$this->assertSame('sent', $this->saved[UwlrEduVService::SCHEMA_MESSAGE][0]['object']['status']);

	}//end testRetryFailedRetriesOnlyFailedRows()
}//end class

<?php

/**
 * Unit tests for OsoService.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 *
 * @spec openspec/changes/integriq-adapter-oso/tasks.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Service;

use OCA\Integriq\Event\OsoAcknowledgementReceivedEvent;
use OCA\Integriq\Event\OsoDossierReceivedEvent;
use OCA\Integriq\Exception\OsoProviderException;
use OCA\Integriq\Exception\OsoTranslationException;
use OCA\Integriq\Service\Oso\LogOsoProvider;
use OCA\Integriq\Service\Oso\OsoAcknowledgementTranslator;
use OCA\Integriq\Service\Oso\OsoExportEnvelopeTranslator;
use OCA\Integriq\Service\Oso\OsoImportTranslator;
use OCA\Integriq\Service\Oso\OsoProviderRegistry;
use OCA\Integriq\Service\OsoService;
use OCA\Integriq\Service\Security\RawSourceResolver;
use OCA\Integriq\Tests\Helpers\ObjectServiceMockBuilder;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService as ORObjectService;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\IL10N;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Tests for the OSO export/import/retour orchestration.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 *
 * @spec openspec/changes/integriq-adapter-oso/specs/oso-adapter/spec.md
 */
class OsoServiceTest extends TestCase {

	/**
	 * @var ORObjectService|\PHPUnit\Framework\MockObject\MockObject
	 */
	private $objectService;

	/**
	 * @var IEventDispatcher|\PHPUnit\Framework\MockObject\MockObject
	 */
	private $eventDispatcher;

	/**
	 * @var OsoService
	 */
	private OsoService $service;

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

				if ($schema === OsoService::SCHEMA_SOURCE) {
					return ['results' => $this->sources];
				}

				if ($schema === OsoService::SCHEMA_MESSAGE) {
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

		$this->service = new OsoService(
			$this->objectService,
			new OsoProviderRegistry([new LogOsoProvider()]),
			new OsoExportEnvelopeTranslator(),
			new OsoImportTranslator(),
			new OsoAcknowledgementTranslator(),
			$this->eventDispatcher,
			$l,
			$logger,
			new RawSourceResolver($this->objectService, $logger)
		);

	}//end setUp()

	/**
	 * An OSO source entity (type oso, log provider by default).
	 *
	 * @param array $configuration Extra configuration merged over the default.
	 * @param string $uuid Entity uuid.
	 *
	 * @return ObjectEntity
	 */
	private function sourceEntity(array $configuration = [], string $uuid = 'source-1'): ObjectEntity {
		return ObjectServiceMockBuilder::objectEntity(
			$this,
			['type' => 'oso', 'isEnabled' => true, 'configuration' => array_merge(['provider' => 'log'], $configuration)],
			$uuid
		);
	}//end sourceEntity()

	/**
	 * resolveActiveSource() throws when no active source is configured.
	 *
	 * @return void
	 */
	public function testResolveActiveSourceThrowsWhenNoneConfigured(): void {
		$this->expectException(OsoProviderException::class);
		$this->service->resolveActiveSource();

	}//end testResolveActiveSourceThrowsWhenNoneConfigured()

	/**
	 * A successful export persists a sent record with its ref.
	 *
	 * @return void
	 */
	public function testSuccessfulExportPersistsSentRecord(): void {
		$this->sources[] = $this->sourceEntity();

		$result = $this->service->sendExport(
			'seed-oso-kenmerk-001',
			['learnerEckId' => 'eck-id-seed-001', 'targetSchoolBrin' => '34CD', 'categories' => [['category' => 'basisgegevens', 'included' => true]]]
		);

		$this->assertSame('export', $result['direction']);
		$this->assertSame('sent', $result['status']);
		$this->assertStringStartsWith('MOCK-OSO-', $result['ref']);

		$saved = $this->saved[OsoService::SCHEMA_MESSAGE][0]['object'];
		$this->assertSame('export', $saved['direction']);
		$this->assertSame('sent', $saved['status']);

	}//end testSuccessfulExportPersistsSentRecord()

	/**
	 * A translation failure never persists a record.
	 *
	 * @return void
	 */
	public function testTranslationFailureNeverPersistsARecord(): void {
		$this->sources[] = $this->sourceEntity();

		try {
			$this->service->sendExport('k1', ['learnerEckId' => 'eck-id-seed-001']);
			$this->fail('Expected OsoTranslationException was not thrown.');
		} catch (OsoTranslationException $exception) {
			$this->assertArrayNotHasKey(OsoService::SCHEMA_MESSAGE, $this->saved);
		}

	}//end testTranslationFailureNeverPersistsARecord()

	/**
	 * receiveImport() persists an import record and dispatches OsoDossierReceivedEvent.
	 *
	 * @return void
	 */
	public function testReceiveImportPersistsAndDispatches(): void {
		$xml = file_get_contents(__DIR__ . '/../../fixtures/oso/import-complete.xml');
		$this->service->receiveImport((string)$xml);

		$this->assertCount(1, $this->dispatched);
		$event = $this->dispatched[0];
		$this->assertInstanceOf(OsoDossierReceivedEvent::class, $event);
		$this->assertSame('12AB', $event->getSourceSchoolBrin());
		$this->assertSame('eck-id-seed-001', $event->getLearnerEckId());

		$saved = $this->saved[OsoService::SCHEMA_MESSAGE][0]['object'];
		$this->assertSame('import', $saved['direction']);
		$this->assertSame('received', $saved['status']);

	}//end testReceiveImportPersistsAndDispatches()

	/**
	 * receiveReturn() dispatches OsoAcknowledgementReceivedEvent with accepted true.
	 *
	 * @return void
	 */
	public function testReceiveReturnDispatchesAcceptedEvent(): void {
		$xml = file_get_contents(__DIR__ . '/../../fixtures/oso/retour-accepted.xml');
		$this->service->receiveReturn((string)$xml);

		$this->assertCount(1, $this->dispatched);
		$event = $this->dispatched[0];
		$this->assertInstanceOf(OsoAcknowledgementReceivedEvent::class, $event);
		$this->assertTrue($event->isAccepted());

		$saved = $this->saved[OsoService::SCHEMA_MESSAGE][0]['object'];
		$this->assertSame('acknowledged', $saved['status']);

	}//end testReceiveReturnDispatchesAcceptedEvent()

	/**
	 * retryFailed() retries a failed export row and leaves a sent one untouched.
	 *
	 * @return void
	 */
	public function testRetryFailedRetriesOnlyFailedOrPendingExportRows(): void {
		$this->sources[] = $this->sourceEntity();
		$this->messages[] = ObjectServiceMockBuilder::objectEntity(
			$this,
			['direction' => 'export', 'kenmerk' => 'k-failed', 'status' => 'failed', 'ref' => 'MOCK-OSO-1'],
			'msg-failed'
		);
		$this->messages[] = ObjectServiceMockBuilder::objectEntity(
			$this,
			['direction' => 'export', 'kenmerk' => 'k-sent', 'status' => 'sent', 'ref' => 'MOCK-OSO-2'],
			'msg-sent'
		);

		$retried = $this->service->retryFailed();

		$this->assertSame(1, $retried);
		$this->assertCount(1, $this->saved[OsoService::SCHEMA_MESSAGE]);
		$this->assertSame('sent', $this->saved[OsoService::SCHEMA_MESSAGE][0]['object']['status']);

	}//end testRetryFailedRetriesOnlyFailedOrPendingExportRows()
}//end class

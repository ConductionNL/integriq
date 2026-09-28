<?php

/**
 * Unit tests for PeppolTransmissionService.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 *
 * @spec openspec/changes/peppol-access-point-connector/tasks.md#task-4
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Service;

use OCA\Integriq\Exception\PeppolProviderException;
use OCA\Integriq\Service\EventService;
use OCA\Integriq\Service\Peppol\LogPeppolAccessPointProvider;
use OCA\Integriq\Service\Peppol\RestPeppolAccessPointProvider;
use OCA\Integriq\Service\PeppolTransmissionService;
use OCA\Integriq\Tests\Helpers\ObjectServiceMockBuilder;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService as ORObjectService;
use OCP\Files\File;
use OCP\Files\IRootFolder;
use OCP\Files\NotFoundException;
use OCP\IL10N;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Tests for participant lookup + the outbound transmission lifecycle.
 *
 * @spec openspec/changes/peppol-access-point-connector/specs/peppol-access-point-connector/spec.md
 */
class PeppolTransmissionServiceTest extends TestCase {

	/**
	 * @var ORObjectService|\PHPUnit\Framework\MockObject\MockObject
	 */
	private $objectService;

	/**
	 * @var LogPeppolAccessPointProvider|\PHPUnit\Framework\MockObject\MockObject
	 */
	private $logProvider;

	/**
	 * @var RestPeppolAccessPointProvider|\PHPUnit\Framework\MockObject\MockObject
	 */
	private $restProvider;

	/**
	 * @var EventService|\PHPUnit\Framework\MockObject\MockObject
	 */
	private $eventService;

	/**
	 * @var IL10N|\PHPUnit\Framework\MockObject\MockObject
	 */
	private $l;

	/**
	 * @var LoggerInterface|\PHPUnit\Framework\MockObject\MockObject
	 */
	private $logger;

	/**
	 * @var IRootFolder|\PHPUnit\Framework\MockObject\MockObject
	 */
	private $rootFolder;

	/**
	 * @var PeppolTransmissionService
	 */
	private PeppolTransmissionService $service;

	/**
	 * A minimal UBL invoice, as shillinq stores it in Nextcloud Files.
	 *
	 * @var string
	 */
	private const UBL = '<?xml version="1.0" encoding="UTF-8"?>'
		. '<Invoice xmlns="urn:oasis:names:specification:ubl:schema:xsd:Invoice-2"><ID>2026-0142</ID></Invoice>';

	/**
	 * Where that invoice sits in Nextcloud Files.
	 *
	 * @var string
	 */
	private const UBL_PATH = '/admin/files/Invoices/2026-0142.xml';

	/**
	 * Set up test fixtures.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->objectService = ObjectServiceMockBuilder::make($this);
		$this->logProvider = $this->createMock(LogPeppolAccessPointProvider::class);
		$this->restProvider = $this->createMock(RestPeppolAccessPointProvider::class);
		$this->eventService = $this->createMock(EventService::class);
		$this->l = $this->createMock(IL10N::class);
		$this->l->method('t')->willReturnArgument(0);
		$this->logger = $this->createMock(LoggerInterface::class);
		$this->rootFolder = $this->createMock(IRootFolder::class);

		$this->service = new PeppolTransmissionService(
			$this->objectService,
			$this->logProvider,
			$this->restProvider,
			$this->eventService,
			$this->l,
			$this->logger,
			$this->rootFolder
		);

	}//end setUp()

	/**
	 * Build a real ObjectEntity for a given data payload (magic getters need the real Entity path).
	 *
	 * @param array $data The object data.
	 * @param string $uuid The entity uuid.
	 *
	 * @return ObjectEntity
	 */
	private function entity(array $data, string $uuid = 'uuid-1'): ObjectEntity {
		$entity = new ObjectEntity();
		$entity->setObject($data);
		$entity->setUuid($uuid);
		return $entity;
	}//end entity()

	/**
	 * A Nextcloud file node holding the given content.
	 *
	 * @param string $path The node's absolute path.
	 * @param string $content The file's content.
	 *
	 * @return File
	 */
	private function file(string $path, string $content): File {
		$file = $this->createMock(File::class);
		$file->method('getPath')->willReturn($path);
		$file->method('getContent')->willReturn($content);
		return $file;
	}//end file()

	/**
	 * Configure one active log-provider source and no existing transmission,
	 * and return the transmission the first save creates.
	 *
	 * @return ObjectEntity The queued transmission.
	 */
	private function givenActiveSourceAndNoTransmission(): ObjectEntity {
		$source = $this->entity(['type' => 'peppol', 'configuration' => ['provider' => 'log']]);

		$this->objectService->method('findAll')->willReturnCallback(
			function (array $config) use ($source) {
				$filters = ($config['filters'] ?? []);
				if (($filters['schema'] ?? null) === 'source') {
					return ['results' => [$source], 'total' => 1];
				}

				return ['results' => [], 'total' => 0];
			}
		);

		return $this->entity(
			[
				'objectUri' => '/apps/shillinq/invoices/2026-0142',
				'recipientPeppolId' => '0106:12345678',
				'documentType' => 'ubl-invoice-2.1',
				'status' => 'queued',
				'attempts' => [],
			],
			'tx-uuid-142'
		);
	}//end givenActiveSourceAndNoTransmission()

	/**
	 * An outbound request for the given payload reference.
	 *
	 * @param string $payloadFileUri The reference to the UBL document.
	 *
	 * @return array The CloudEvent data payload.
	 */
	private function outboundRequest(string $payloadFileUri): array {
		return [
			'sourceApp' => 'shillinq',
			'objectUri' => '/apps/shillinq/invoices/2026-0142',
			'recipientPeppolId' => '0106:12345678',
			'documentType' => 'ubl-invoice-2.1',
			'payloadFileUri' => $payloadFileUri,
		];
	}//end outboundRequest()

	/**
	 * A valid `scheme:identifier` peppolId passes validation.
	 *
	 * @return void
	 */
	public function testIsValidPeppolIdAcceptsValidShape(): void {
		$this->assertTrue($this->service->isValidPeppolId('0192:1234567890'));

	}//end testIsValidPeppolIdAcceptsValidShape()

	/**
	 * A malformed peppolId (no scheme:identifier shape) fails validation — REQ-001.
	 *
	 * @return void
	 */
	public function testIsValidPeppolIdRejectsMalformedShape(): void {
		$this->assertFalse($this->service->isValidPeppolId('not-a-peppol-id'));
		$this->assertFalse($this->service->isValidPeppolId(''));
		$this->assertFalse($this->service->isValidPeppolId('12:'));

	}//end testIsValidPeppolIdRejectsMalformedShape()

	/**
	 * resolveProvider selects the log provider by default and for explicit "log".
	 *
	 * @return void
	 */
	public function testResolveProviderDefaultsToLog(): void {
		$this->assertSame($this->logProvider, $this->service->resolveProvider([]));
		$this->assertSame($this->logProvider, $this->service->resolveProvider(['provider' => 'log']));

	}//end testResolveProviderDefaultsToLog()

	/**
	 * resolveProvider selects the REST provider for configuration.provider=rest.
	 *
	 * @return void
	 */
	public function testResolveProviderSelectsRestWhenConfigured(): void {
		$this->assertSame($this->restProvider, $this->service->resolveProvider(['provider' => 'rest']));

	}//end testResolveProviderSelectsRestWhenConfigured()

	/**
	 * lookupParticipant throws a descriptive error when no active Peppol source exists.
	 *
	 * @return void
	 */
	public function testLookupParticipantThrowsWhenNoSourceConfigured(): void {
		$this->objectService->method('findAll')->willReturn(['results' => [], 'total' => 0]);

		$this->expectException(PeppolProviderException::class);

		$this->service->lookupParticipant('0192:1234567890');

	}//end testLookupParticipantThrowsWhenNoSourceConfigured()

	/**
	 * lookupParticipant delegates to the configured provider with the source configuration.
	 *
	 * @return void
	 */
	public function testLookupParticipantDelegatesToProvider(): void {
		$source = $this->entity(['type' => 'peppol', 'configuration' => ['provider' => 'log', 'mockParticipants' => ['0192:1234567890']]]);
		$this->objectService->method('findAll')->willReturn(['results' => [$source], 'total' => 1]);

		$this->logProvider->expects($this->once())
			->method('lookupParticipant')
			->with($this->anything(), '0192:1234567890')
			->willReturn(['exists' => true, 'supportedDocTypes' => ['ubl-invoice-2.1']]);

		$result = $this->service->lookupParticipant('0192:1234567890');

		$this->assertTrue($result['exists']);

	}//end testLookupParticipantDelegatesToProvider()

	/**
	 * A fresh outbound.requested event creates a queued->sent transmission and emits one delivery-status event.
	 *
	 * @return void
	 */
	public function testHandleOutboundRequestedCreatesAndSendsTransmission(): void {
		$source = $this->entity(['type' => 'peppol', 'configuration' => ['provider' => 'log']]);

		$this->objectService->method('findAll')->willReturnCallback(
			function (array $config) use ($source) {
				$filters = ($config['filters'] ?? []);
				if (($filters['schema'] ?? null) === 'source') {
					return ['results' => [$source], 'total' => 1];
				}

				// No existing transmission for this objectUri+documentType yet.
				return ['results' => [], 'total' => 0];
			}
		);

		$created = $this->entity(
			[
				'objectUri' => '/objects/ar-invoice/1',
				'recipientPeppolId' => '0192:1234567890',
				'documentType' => 'ubl-invoice-2.1',
				'status' => 'queued',
				'attempts' => [],
			],
			'tx-uuid-1'
		);

		$this->objectService->expects($this->exactly(2))
			->method('saveObject')
			->willReturnOnConsecutiveCalls(
				$created,
				$this->entity(
					[
						'objectUri' => '/objects/ar-invoice/1',
						'recipientPeppolId' => '0192:1234567890',
						'documentType' => 'ubl-invoice-2.1',
						'status' => 'sent',
						'transmissionId' => 'MOCK-PEPPOL-1',
						'attempts' => [['at' => '2026-01-01T00:00:00+00:00', 'error' => null]],
					],
					'tx-uuid-1'
				)
			);

		$this->rootFolder->method('get')->willReturn($this->file(self::UBL_PATH, self::UBL));
		$this->logProvider->method('submitDocument')->willReturn('MOCK-PEPPOL-1');

		$this->eventService->expects($this->once())
			->method('emitCloudEvent')
			->with(
				PeppolTransmissionService::EVENT_TYPE_DELIVERY_STATUS,
				$this->anything(),
				'tx-uuid-1',
				$this->callback(static fn (array $data) => ($data['status'] ?? null) === 'sent')
			);

		$result = $this->service->handleOutboundRequested(
			[
				'sourceApp' => 'shillinq',
				'objectUri' => '/objects/ar-invoice/1',
				'recipientPeppolId' => '0192:1234567890',
				'documentType' => 'ubl-invoice-2.1',
				'payloadFileUri' => self::UBL_PATH,
			]
		);

		$this->assertNotNull($result);
		$this->assertSame('sent', $result->getObject()['status']);
		$this->assertSame('MOCK-PEPPOL-1', $result->getObject()['transmissionId']);

	}//end testHandleOutboundRequestedCreatesAndSendsTransmission()

	/**
	 * A redelivered event for an already-sent transmission is idempotent — no second submission.
	 *
	 * @return void
	 */
	public function testHandleOutboundRequestedIsIdempotentForAlreadySentTransmission(): void {
		$existing = $this->entity(
			[
				'objectUri' => '/objects/ar-invoice/1',
				'recipientPeppolId' => '0192:1234567890',
				'documentType' => 'ubl-invoice-2.1',
				'status' => 'sent',
				'transmissionId' => 'MOCK-PEPPOL-1',
				'attempts' => [['at' => '2026-01-01T00:00:00+00:00', 'error' => null]],
			],
			'tx-uuid-1'
		);

		$this->objectService->method('findAll')->willReturn(['results' => [$existing], 'total' => 1]);
		$this->objectService->expects($this->never())->method('saveObject');
		$this->logProvider->expects($this->never())->method('submitDocument');
		$this->eventService->expects($this->never())->method('emitCloudEvent');

		$result = $this->service->handleOutboundRequested(
			[
				'objectUri' => '/objects/ar-invoice/1',
				'recipientPeppolId' => '0192:1234567890',
				'documentType' => 'ubl-invoice-2.1',
			]
		);

		$this->assertSame('sent', $result->getObject()['status']);

	}//end testHandleOutboundRequestedIsIdempotentForAlreadySentTransmission()

	/**
	 * Missing required fields on the event payload are ignored (no crash, no persistence).
	 *
	 * @return void
	 */
	public function testHandleOutboundRequestedIgnoresIncompletePayload(): void {
		$this->objectService->expects($this->never())->method('saveObject');

		$result = $this->service->handleOutboundRequested(['objectUri' => '/objects/ar-invoice/1']);

		$this->assertNull($result);

	}//end testHandleOutboundRequestedIgnoresIncompletePayload()

	/**
	 * A transmission that has exhausted its retry budget (status=failed, MAX_ATTEMPTS attempts)
	 * stays dead-lettered on a redelivery: no further AP call.
	 *
	 * @return void
	 */
	public function testHandleOutboundRequestedStaysDeadLetteredWhenBudgetExhausted(): void {
		$exhaustedAttempts = array_fill(0, PeppolTransmissionService::MAX_ATTEMPTS, ['at' => '2026-01-01T00:00:00+00:00', 'error' => 'boom']);
		$existing = $this->entity(
			[
				'objectUri' => '/objects/ar-invoice/1',
				'recipientPeppolId' => '0192:1234567890',
				'documentType' => 'ubl-invoice-2.1',
				'status' => 'failed',
				'attempts' => $exhaustedAttempts,
			],
			'tx-uuid-1'
		);

		$this->objectService->method('findAll')->willReturn(['results' => [$existing], 'total' => 1]);
		$this->objectService->expects($this->never())->method('saveObject');
		$this->logProvider->expects($this->never())->method('submitDocument');

		$result = $this->service->handleOutboundRequested(
			[
				'objectUri' => '/objects/ar-invoice/1',
				'recipientPeppolId' => '0192:1234567890',
				'documentType' => 'ubl-invoice-2.1',
			]
		);

		$this->assertSame('failed', $result->getObject()['status']);

	}//end testHandleOutboundRequestedStaysDeadLetteredWhenBudgetExhausted()

	/**
	 * A submission that throws moves the transmission to failed, records the attempt, and emits a failed event.
	 *
	 * @return void
	 */
	public function testHandleOutboundRequestedRecordsFailedAttemptOnException(): void {
		$source = $this->entity(['type' => 'peppol', 'configuration' => ['provider' => 'log']]);

		$this->objectService->method('findAll')->willReturnCallback(
			function (array $config) use ($source) {
				$filters = ($config['filters'] ?? []);
				if (($filters['schema'] ?? null) === 'source') {
					return ['results' => [$source], 'total' => 1];
				}

				return ['results' => [], 'total' => 0];
			}
		);

		$created = $this->entity(
			[
				'objectUri' => '/objects/ar-invoice/1',
				'recipientPeppolId' => '0192:1234567890',
				'documentType' => 'ubl-invoice-2.1',
				'status' => 'queued',
				'attempts' => [],
			],
			'tx-uuid-1'
		);
		$failed = $this->entity(
			[
				'objectUri' => '/objects/ar-invoice/1',
				'recipientPeppolId' => '0192:1234567890',
				'documentType' => 'ubl-invoice-2.1',
				'status' => 'failed',
				'attempts' => [['at' => '2026-01-01T00:00:00+00:00', 'error' => 'AP unreachable']],
			],
			'tx-uuid-1'
		);

		$this->objectService->method('saveObject')->willReturnOnConsecutiveCalls($created, $failed);

		$this->rootFolder->method('get')->willReturn($this->file(self::UBL_PATH, self::UBL));
		// The document is readable, so the failure recorded is the access point's.
		$this->logProvider->expects($this->once())->method('submitDocument')->willThrowException(new PeppolProviderException(message: 'AP unreachable'));

		$this->eventService->expects($this->once())
			->method('emitCloudEvent')
			->with(
				PeppolTransmissionService::EVENT_TYPE_DELIVERY_STATUS,
				$this->anything(),
				'tx-uuid-1',
				$this->callback(static fn (array $data) => ($data['status'] ?? null) === 'failed')
			);

		$result = $this->service->handleOutboundRequested(
			[
				'objectUri' => '/objects/ar-invoice/1',
				'recipientPeppolId' => '0192:1234567890',
				'documentType' => 'ubl-invoice-2.1',
				'payloadFileUri' => self::UBL_PATH,
			]
		);

		$this->assertSame('failed', $result->getObject()['status']);

	}//end testHandleOutboundRequestedRecordsFailedAttemptOnException()

	/**
	 * REQ-008: the access point receives the XML of the file `payloadFileUri`
	 * names by absolute path, not the path itself. Before the fix the
	 * reference string was passed on as the document.
	 *
	 * @return void
	 */
	public function testSubmitsTheUblContentNotItsReference(): void {
		$created = $this->givenActiveSourceAndNoTransmission();
		$this->objectService->method('saveObject')->willReturnOnConsecutiveCalls($created, $created);

		$this->rootFolder->method('get')->willReturnCallback(
			fn (string $path) => $path === self::UBL_PATH ? $this->file(self::UBL_PATH, self::UBL) : throw new NotFoundException($path)
		);

		$this->logProvider->expects($this->once())
			->method('submitDocument')
			->with($this->anything(), '0106:12345678', 'ubl-invoice-2.1', self::UBL)
			->willReturn('MOCK-PEPPOL-142');

		$this->service->handleOutboundRequested($this->outboundRequest(self::UBL_PATH));

	}//end testSubmitsTheUblContentNotItsReference()

	/**
	 * REQ-008: a `nextcloud-file:<id>` reference is read by file id.
	 *
	 * @return void
	 */
	public function testSubmitsTheUblNamedByFileId(): void {
		$created = $this->givenActiveSourceAndNoTransmission();
		$this->objectService->method('saveObject')->willReturnOnConsecutiveCalls($created, $created);

		$this->rootFolder->method('getFirstNodeById')->willReturnCallback(
			fn (int $id) => $id === 4711 ? $this->file(self::UBL_PATH, self::UBL) : null
		);

		$this->logProvider->expects($this->once())
			->method('submitDocument')
			->with($this->anything(), '0106:12345678', 'ubl-invoice-2.1', self::UBL)
			->willReturn('MOCK-PEPPOL-142');

		$this->service->handleOutboundRequested($this->outboundRequest('nextcloud-file:4711'));

	}//end testSubmitsTheUblNamedByFileId()

	/**
	 * REQ-008: a reference that does not resolve fails the transmission with
	 * "payload not readable" and the reference, and no call reaches the
	 * access point. The attempt is recorded, so the existing retry budget and
	 * dead-letter path apply.
	 *
	 * @return void
	 */
	public function testUnreadableReferenceFailsWithoutCallingTheAccessPoint(): void {
		$created = $this->givenActiveSourceAndNoTransmission();
		$missing = '/admin/files/Invoices/missing.xml';

		$this->rootFolder->method('get')->willThrowException(new NotFoundException($missing));

		$saved = [];
		$this->objectService->method('saveObject')->willReturnCallback(
			function (array $object) use (&$saved, $created) {
				$saved[] = $object;
				return (count($saved) === 1) ? $created : $this->entity($object, 'tx-uuid-142');
			}
		);

		$this->logProvider->expects($this->never())->method('submitDocument');

		$result = $this->service->handleOutboundRequested($this->outboundRequest($missing));

		$this->assertSame('failed', $result->getObject()['status']);
		$this->assertStringContainsString('payload not readable', (string)$result->getObject()['detail']);
		$this->assertStringContainsString($missing, (string)$result->getObject()['detail']);
		$this->assertCount(1, $result->getObject()['attempts']);

	}//end testUnreadableReferenceFailsWithoutCallingTheAccessPoint()

	/**
	 * A path outside a user's `files/` tree is not read, so a request cannot
	 * make integriq send app data or another storage root.
	 *
	 * @return void
	 */
	public function testPathOutsideUserFilesIsNotRead(): void {
		$this->givenActiveSourceAndNoTransmission();
		$this->objectService->method('saveObject')->willReturnCallback(
			fn (array $object) => $this->entity($object, 'tx-uuid-142')
		);

		$this->rootFolder->expects($this->never())->method('get');
		$this->logProvider->expects($this->never())->method('submitDocument');

		$result = $this->service->handleOutboundRequested($this->outboundRequest('/appdata_oc123/integriq/secret.xml'));

		$this->assertSame('failed', $result->getObject()['status']);

	}//end testPathOutsideUserFilesIsNotRead()

	/**
	 * A signed delivery callback for a known transmissionId advances the transmission and emits a status event.
	 *
	 * @return void
	 */
	public function testHandleDeliveryCallbackAdvancesKnownTransmission(): void {
		$existing = $this->entity(
			[
				'objectUri' => '/objects/ar-invoice/1',
				'recipientPeppolId' => '0192:1234567890',
				'documentType' => 'ubl-invoice-2.1',
				'status' => 'sent',
				'transmissionId' => 'AP-TX-123',
			],
			'tx-uuid-1'
		);

		$this->objectService->method('findAll')->willReturn(['results' => [$existing], 'total' => 1]);

		$updated = $this->entity(
			array_merge($existing->getObject(), ['status' => 'delivered', 'detail' => 'Accepted']),
			'tx-uuid-1'
		);
		$this->objectService->method('saveObject')->willReturn($updated);

		$this->eventService->expects($this->once())
			->method('emitCloudEvent')
			->with(
				PeppolTransmissionService::EVENT_TYPE_DELIVERY_STATUS,
				$this->anything(),
				'tx-uuid-1',
				$this->callback(static fn (array $data) => ($data['status'] ?? null) === 'delivered')
			);

		$result = $this->service->handleDeliveryCallback('AP-TX-123', 'delivered', 'Accepted');

		$this->assertSame('delivered', $result->getObject()['status']);

	}//end testHandleDeliveryCallbackAdvancesKnownTransmission()

	/**
	 * A rejection callback with no supplied detail is given a non-empty default (REQ-004).
	 *
	 * @return void
	 */
	public function testHandleDeliveryCallbackRejectionGetsNonEmptyDefaultDetail(): void {
		$existing = $this->entity(
			['objectUri' => '/objects/ar-invoice/1', 'status' => 'sent', 'transmissionId' => 'AP-TX-123'],
			'tx-uuid-1'
		);
		$this->objectService->method('findAll')->willReturn(['results' => [$existing], 'total' => 1]);

		$captured = null;
		$this->objectService->method('saveObject')->willReturnCallback(
			function (array $object, ...$rest) use (&$captured) {
				$captured = $object;
				return $this->entity($object, 'tx-uuid-1');
			}
		);

		$this->service->handleDeliveryCallback('AP-TX-123', 'rejected', null);

		$this->assertNotEmpty($captured['detail']);

	}//end testHandleDeliveryCallbackRejectionGetsNonEmptyDefaultDetail()

	/**
	 * A callback for an unknown transmissionId is a no-op (recorded via log) — never a crash.
	 *
	 * @return void
	 */
	public function testHandleDeliveryCallbackUnknownTransmissionIdIsNoOp(): void {
		$this->objectService->method('findAll')->willReturn(['results' => [], 'total' => 0]);
		$this->objectService->expects($this->never())->method('saveObject');
		$this->eventService->expects($this->never())->method('emitCloudEvent');

		$result = $this->service->handleDeliveryCallback('UNKNOWN-TX', 'delivered', null);

		$this->assertNull($result);

	}//end testHandleDeliveryCallbackUnknownTransmissionIdIsNoOp()

	/**
	 * An unsupported status on the callback is ignored, not persisted.
	 *
	 * @return void
	 */
	public function testHandleDeliveryCallbackUnsupportedStatusIsIgnored(): void {
		$this->objectService->expects($this->never())->method('findAll');
		$this->objectService->expects($this->never())->method('saveObject');

		$result = $this->service->handleDeliveryCallback('AP-TX-123', 'queued', null);

		$this->assertNull($result);

	}//end testHandleDeliveryCallbackUnsupportedStatusIsIgnored()

	/**
	 * handleInboundDocument republishes a signed inbound-document notification as a CloudEvent.
	 *
	 * @return void
	 */
	public function testHandleInboundDocumentEmitsInboundReceivedEvent(): void {
		$this->eventService->expects($this->once())
			->method('emitCloudEvent')
			->with(
				PeppolTransmissionService::EVENT_TYPE_INBOUND_RECEIVED,
				$this->anything(),
				'0192:9999999999',
				[
					'senderPeppolId' => '0192:9999999999',
					'documentType' => 'ubl-invoice-2.1',
					'payloadReference' => 'https://ap.example/doc/AP-DOC-9',
				]
			)
			->willReturn([]);

		$this->service->handleInboundDocument('0192:9999999999', 'ubl-invoice-2.1', 'https://ap.example/doc/AP-DOC-9');

	}//end testHandleInboundDocumentEmitsInboundReceivedEvent()
}//end class

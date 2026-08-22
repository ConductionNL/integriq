<?php

/**
 * Unit tests for IwmoIjwSyncService.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 *
 * @spec openspec/changes/iwmo-ijw-adapter/tasks.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Service;

use OCA\Integriq\Exception\IwmoIjwProviderException;
use OCA\Integriq\Exception\IwmoIjwTranslationException;
use OCA\Integriq\Service\IwmoIjw\InboundReturnTranslator;
use OCA\Integriq\Service\IwmoIjw\IStandardsClient;
use OCA\Integriq\Service\IwmoIjw\LogIwmoIjwProvider;
use OCA\Integriq\Service\IwmoIjw\OutboundMessageTranslator;
use OCA\Integriq\Service\IwmoIjwSyncService;
use OCA\Integriq\Service\Security\RawSourceResolver;
use OCA\Integriq\Tests\Helpers\ObjectServiceMockBuilder;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService as ORObjectService;
use OCP\IL10N;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Tests for the iWMO/iJW send/retour sync orchestration (provider selection,
 * per-message persistence, single-write-path case update, retry isolation, AVG hygiene).
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 * @SuppressWarnings(PHPMD.TooManyPublicMethods)
 *
 * @spec openspec/changes/iwmo-ijw-adapter/specs/iwmo-ijw-adapter/spec.md
 */
class IwmoIjwSyncServiceTest extends TestCase {

	/**
	 * @var ORObjectService|\PHPUnit\Framework\MockObject\MockObject
	 */
	private $objectService;

	/**
	 * @var LogIwmoIjwProvider|\PHPUnit\Framework\MockObject\MockObject
	 */
	private $logProvider;

	/**
	 * @var IStandardsClient|\PHPUnit\Framework\MockObject\MockObject
	 */
	private $restProvider;

	/**
	 * @var LoggerInterface|\PHPUnit\Framework\MockObject\MockObject
	 */
	private $logger;

	/**
	 * @var IwmoIjwSyncService
	 */
	private IwmoIjwSyncService $service;

	/**
	 * Every saveObject invocation captured as [schema => list of {object, register, uuid}].
	 *
	 * @var array<string, array<int, array{object: array, register: string|null, uuid: string|null}>>
	 */
	private array $saved = [];

	/**
	 * Pre-seeded `openconnector` register `source` rows returned for schema=source lookups.
	 *
	 * @var array<int, ObjectEntity>
	 */
	private array $sources = [];

	/**
	 * Pre-seeded `iwmo_ijw_message` rows returned for schema=iwmo_ijw_message lookups.
	 *
	 * @var array<int, ObjectEntity>
	 */
	private array $messages = [];

	/**
	 * Pre-seeded linked case objects, keyed by "register:schema:uuid".
	 *
	 * @var array<string, ObjectEntity>
	 */
	private array $cases = [];

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
		$this->logProvider = $this->createMock(LogIwmoIjwProvider::class);
		$this->restProvider = $this->createMock(IStandardsClient::class);
		$l = $this->createMock(IL10N::class);
		$l->method('t')->willReturnArgument(0);
		$this->logger = $this->createMock(LoggerInterface::class);

		$this->saved = [];
		$this->sources = [];
		$this->messages = [];
		$this->cases = [];

		$this->objectService->method('findAll')->willReturnCallback(
			function (array $config): array {
				$filters = ($config['filters'] ?? []);
				$schema = ($filters['schema'] ?? null);

				if ($schema === IwmoIjwSyncService::SCHEMA_SOURCE) {
					return ['results' => $this->sources];
				}

				if ($schema === IwmoIjwSyncService::SCHEMA_MESSAGE) {
					$ref = ($filters['ref'] ?? null);
					if ($ref !== null) {
						$matching = array_values(
							array_filter(
								$this->messages,
								static fn (ObjectEntity $m) => ($m->getObject()['ref'] ?? null) === $ref
							)
						);
						return ['results' => $matching];
					}

					return ['results' => $this->messages];
				}

				return ['results' => []];
			}
		);

		$this->objectService->method('find')->willReturnCallback(
			function ($id, ?string $register = null, ?string $schema = null): ?ObjectEntity {
				return ($this->cases[$register . ':' . $schema . ':' . $id] ?? null);
			}
		);

		$this->objectService->method('saveObject')->willReturnCallback(
			function ($object, $register = null, $schema = null, $uuid = null): ObjectEntity {
				$key = (string)$schema;
				$this->saved[$key][] = ['object' => $object, 'register' => $register, 'uuid' => $uuid];
				return $this->entity($object, ($uuid ?? 'saved-uuid-' . count($this->saved[$key])));
			}
		);

		$this->service = new IwmoIjwSyncService(
			$this->objectService,
			$this->logProvider,
			$this->restProvider,
			new OutboundMessageTranslator(),
			new InboundReturnTranslator(),
			$l,
			$this->logger,
			new RawSourceResolver($this->objectService, $this->logger)
		);

	}//end setUp()

	/**
	 * Build a real ObjectEntity for a data payload (magic getters need the real Entity path).
	 *
	 * @param array $data The object data.
	 * @param string $uuid The entity uuid.
	 *
	 * @return ObjectEntity
	 */
	private function entity(array $data, string $uuid = 'uuid-1'): ObjectEntity {
		return ObjectServiceMockBuilder::objectEntity($this, $data, $uuid);
	}//end entity()

	/**
	 * An iWMO/iJW source entity (type iwmo-ijw, log provider by default).
	 *
	 * @param array $configuration Extra configuration merged over the default.
	 * @param string $uuid Entity uuid.
	 *
	 * @return ObjectEntity
	 */
	private function sourceEntity(array $configuration = [], string $uuid = 'source-1'): ObjectEntity {
		return $this->entity(
			[
				'type' => 'iwmo-ijw',
				'isEnabled' => true,
				'configuration' => array_merge(['provider' => 'log'], $configuration),
			],
			$uuid
		);

	}//end sourceEntity()

	/**
	 * A complete toewijzing push input.
	 *
	 * @param array $overrides Extra fields merged over the default.
	 *
	 * @return array
	 */
	private function toewijzingInput(array $overrides = []): array {
		return array_merge(
			[
				'kind' => OutboundMessageTranslator::KIND_TOEWIJZING,
				'domain' => 'wmo',
				'bsn' => '999995571',
				'productcode' => '05C05',
				'ingangsdatum' => '2026-08-01',
				'omvang' => '4 uur per week',
				'leveringsvorm' => 'ZIN',
				'aanbiederAgbCode' => '01234567',
				'gemeentecode' => 'GM0344',
				'caseReference' => 'case-uuid-1',
				'caseRegister' => 'procest',
				'caseSchema' => 'toewijzing',
			],
			$overrides
		);

	}//end toewijzingInput()

	/**
	 * resolveProvider() defaults to the log/sandbox binding.
	 *
	 * @return void
	 */
	public function testResolveProviderDefaultsToLog(): void {
		$this->assertSame($this->logProvider, $this->service->resolveProvider([]));

	}//end testResolveProviderDefaultsToLog()

	/**
	 * resolveProvider() selects the REST binding when configured.
	 *
	 * @return void
	 */
	public function testResolveProviderSelectsRest(): void {
		$this->assertSame($this->restProvider, $this->service->resolveProvider(['provider' => 'rest']));

	}//end testResolveProviderSelectsRest()

	/**
	 * resolveActiveSource() throws when no active source is configured.
	 *
	 * @return void
	 */
	public function testResolveActiveSourceThrowsWhenNoneConfigured(): void {
		$this->expectException(IwmoIjwProviderException::class);
		$this->service->resolveActiveSource();

	}//end testResolveActiveSourceThrowsWhenNoneConfigured()

	/**
	 * sendBericht() with no active source throws before any translation.
	 *
	 * @return void
	 */
	public function testSendBerichtThrowsWhenNoSourceConfigured(): void {
		$this->expectException(IwmoIjwProviderException::class);
		$this->service->sendMessage($this->toewijzingInput());

	}//end testSendBerichtThrowsWhenNoSourceConfigured()

	/**
	 * A successful outbound send (log provider) persists a sent record with its ref.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/iwmo-ijw-adapter/specs/iwmo-ijw-adapter/spec.md#scenario-a-successful-outbound-send-persists-a-sent-record-with-its-ref
	 */
	public function testSendBerichtPersistsSentRecordOnSuccess(): void {
		$this->sources[] = $this->sourceEntity();
		$this->logProvider->method('send')->willReturn('MOCK-IWMO-1');

		$result = $this->service->sendMessage($this->toewijzingInput());

		$this->assertSame('Wmo303', $result['berichttype']);
		$this->assertNotSame('', $result['ref']);

		$this->assertCount(1, $this->saved[IwmoIjwSyncService::SCHEMA_MESSAGE]);
		$saved = $this->saved[IwmoIjwSyncService::SCHEMA_MESSAGE][0]['object'];
		$this->assertSame('outbound', $saved['direction']);
		$this->assertSame('sent', $saved['status']);
		$this->assertSame($result['ref'], $saved['ref']);
		$this->assertSame('case-uuid-1', $saved['caseReference']);

	}//end testSendBerichtPersistsSentRecordOnSuccess()

	/**
	 * A raw BSN in the push payload is hashed before persistence, never stored raw.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/iwmo-ijw-adapter/specs/iwmo-ijw-adapter/spec.md#scenario-the-sent-envelope-carries-the-raw-bsn-but-the-audit-record-does-not
	 */
	public function testSendBerichtHashesBsnBeforePersistence(): void {
		$this->sources[] = $this->sourceEntity();

		$this->service->sendMessage($this->toewijzingInput());

		$saved = $this->saved[IwmoIjwSyncService::SCHEMA_MESSAGE][0]['object'];
		$this->assertArrayNotHasKey('bsn', $saved);
		$this->assertSame(hash('sha256', '999995571'), $saved['bsnHash']);
		$this->assertNotSame('999995571', $saved['bsnHash']);

	}//end testSendBerichtHashesBsnBeforePersistence()

	/**
	 * A provider send failure persists a failed record with the error, then rethrows.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/iwmo-ijw-adapter/specs/iwmo-ijw-adapter/spec.md#scenario-a-failed-outbound-send-persists-a-failed-record-with-the-error-and-is-retried-later
	 */
	public function testSendBerichtPersistsFailedRecordAndRethrows(): void {
		$this->sources[] = $this->sourceEntity(['provider' => 'rest']);
		$this->restProvider->method('send')->willThrowException(
			new IwmoIjwProviderException('transport unreachable')
		);

		try {
			$this->service->sendMessage($this->toewijzingInput());
			$this->fail('Expected IwmoIjwProviderException was not thrown.');
		} catch (IwmoIjwProviderException $exception) {
			$this->assertSame('transport unreachable', $exception->getMessage());
		}

		$saved = $this->saved[IwmoIjwSyncService::SCHEMA_MESSAGE][0]['object'];
		$this->assertSame('failed', $saved['status']);
		$this->assertSame('transport unreachable', $saved['error']);

	}//end testSendBerichtPersistsFailedRecordAndRethrows()

	/**
	 * A missing required field raises IwmoIjwTranslationException with no message persisted.
	 *
	 * @return void
	 */
	public function testSendBerichtWithIncompletePayloadRaisesTranslationExceptionAndPersistsNothing(): void {
		$this->sources[] = $this->sourceEntity();

		$incomplete = $this->toewijzingInput();
		unset($incomplete['productcode']);

		$this->expectException(IwmoIjwTranslationException::class);
		try {
			$this->service->sendMessage($incomplete);
		} finally {
			$this->assertArrayNotHasKey(IwmoIjwSyncService::SCHEMA_MESSAGE, $this->saved);
		}

	}//end testSendBerichtWithIncompletePayloadRaisesTranslationExceptionAndPersistsNothing()

	/**
	 * receiveRetour() with a resolvable kenmerk single-write-path updates the linked case
	 * under a namespaced iwmoIjw sub-object, preserving the case's own fields.
	 *
	 * @return void
	 */
	public function testReceiveRetourUpdatesLinkedCase(): void {
		$this->messages[] = $this->entity(
			[
				'direction' => 'outbound',
				'ref' => 'WMO-ref-1',
				'caseReference' => 'case-uuid-1',
				'caseRegister' => 'procest',
				'caseSchema' => 'toewijzing',
			],
			'msg-1'
		);
		$this->cases['procest:toewijzing:case-uuid-1'] = $this->entity(
			['ownField' => 'do-not-touch'],
			'case-uuid-1'
		);

		$xml = '<Bericht><stuurgegevens><berichtcode>Wmo304</berichtcode>'
			. '<kenmerk>WMO-ref-1</kenmerk></stuurgegevens>'
			. '<body><resultaat>akkoord</resultaat></body></Bericht>';

		$this->service->receiveReturn($xml);

		$this->assertCount(1, $this->saved['toewijzing']);
		$updated = $this->saved['toewijzing'][0]['object'];
		$this->assertSame('do-not-touch', $updated['ownField']);
		$this->assertSame('accepted', $updated['iwmoIjw']['status']);

		$this->assertCount(1, $this->saved[IwmoIjwSyncService::SCHEMA_MESSAGE]);
		$savedMessage = $this->saved[IwmoIjwSyncService::SCHEMA_MESSAGE][0]['object'];
		$this->assertSame('inbound', $savedMessage['direction']);
		$this->assertSame('case-uuid-1', $savedMessage['caseReference']);

	}//end testReceiveRetourUpdatesLinkedCase()

	/**
	 * receiveRetour() with an unresolvable kenmerk logs a warning and never crashes or
	 * writes to any case.
	 *
	 * @return void
	 */
	public function testReceiveRetourWithUnresolvedKenmerkLogsAndDoesNotCrash(): void {
		$this->logger->expects($this->atLeastOnce())->method('warning');

		$xml = '<Bericht><stuurgegevens><berichtcode>Wmo304</berichtcode>'
			. '<kenmerk>UNKNOWN-REF</kenmerk></stuurgegevens>'
			. '<body><resultaat>akkoord</resultaat></body></Bericht>';

		$this->service->receiveReturn($xml);

		$this->assertArrayNotHasKey('toewijzing', $this->saved);

	}//end testReceiveRetourWithUnresolvedKenmerkLogsAndDoesNotCrash()

	/**
	 * receiveRetour() with malformed XML logs a warning and never throws out of the method.
	 *
	 * @return void
	 */
	public function testReceiveRetourWithMalformedXmlLogsAndDoesNotThrow(): void {
		$this->logger->expects($this->atLeastOnce())->method('warning');

		$this->service->receiveReturn('not-xml-at-all');

		$this->assertArrayNotHasKey(IwmoIjwSyncService::SCHEMA_MESSAGE, $this->saved);

	}//end testReceiveRetourWithMalformedXmlLogsAndDoesNotThrow()

	/**
	 * retryFailed() with no eligible rows is a clean no-op.
	 *
	 * @return void
	 */
	public function testRetryFailedWithNoEligibleRowsIsCleanNoOp(): void {
		$retried = $this->service->retryFailed();
		$this->assertSame(0, $retried);

	}//end testRetryFailedWithNoEligibleRowsIsCleanNoOp()

	/**
	 * retryFailed() re-attempts a failed row via the currently configured provider and
	 * marks it sent.
	 *
	 * @return void
	 */
	public function testRetryFailedRetriesFailedRow(): void {
		$this->sources[] = $this->sourceEntity();
		$this->messages[] = $this->entity(
			['direction' => 'outbound', 'status' => 'failed', 'berichttype' => 'Wmo303', 'ref' => 'WMO-ref-x'],
			'msg-failed-1'
		);
		$this->logProvider->method('send')->willReturn('MOCK-IWMO-9');

		$retried = $this->service->retryFailed();

		$this->assertSame(1, $retried);
		$saved = $this->saved[IwmoIjwSyncService::SCHEMA_MESSAGE][0];
		$this->assertSame('sent', $saved['object']['status']);
		$this->assertSame('msg-failed-1', $saved['uuid']);

	}//end testRetryFailedRetriesFailedRow()

	/**
	 * One failing retry does not abort the sweep — the other eligible row is still retried.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/iwmo-ijw-adapter/specs/iwmo-ijw-adapter/spec.md#scenario-one-failing-retry-does-not-abort-the-sweep
	 */
	public function testOneFailingRetryDoesNotAbortSweep(): void {
		$this->sources[] = $this->sourceEntity(['provider' => 'rest']);
		$this->messages[] = $this->entity(
			['direction' => 'outbound', 'status' => 'failed', 'berichttype' => 'Wmo303', 'ref' => 'WMO-fail'],
			'msg-fail'
		);
		$this->messages[] = $this->entity(
			['direction' => 'outbound', 'status' => 'failed', 'berichttype' => 'Wmo321', 'ref' => 'WMO-ok'],
			'msg-ok'
		);

		$this->restProvider->method('send')->willReturnCallback(
			function ($config, $berichttype, $xml) {
				if ($berichttype === 'Wmo303') {
					throw new IwmoIjwProviderException('still down');
				}

				return 'MOCK-IWMO-ok';
			}
		);

		$retried = $this->service->retryFailed();

		$this->assertSame(1, $retried);
		$this->assertCount(1, $this->saved[IwmoIjwSyncService::SCHEMA_MESSAGE]);
		$this->assertSame('msg-ok', $this->saved[IwmoIjwSyncService::SCHEMA_MESSAGE][0]['uuid']);

	}//end testOneFailingRetryDoesNotAbortSweep()

	/**
	 * retryFailed() skips rows whose status is neither failed nor pending.
	 *
	 * @return void
	 */
	public function testRetryFailedSkipsSentRows(): void {
		$this->sources[] = $this->sourceEntity();
		$this->messages[] = $this->entity(
			['direction' => 'outbound', 'status' => 'sent', 'berichttype' => 'Wmo303', 'ref' => 'WMO-sent'],
			'msg-sent'
		);

		$retried = $this->service->retryFailed();

		$this->assertSame(0, $retried);
		$this->assertArrayNotHasKey(IwmoIjwSyncService::SCHEMA_MESSAGE, $this->saved);

	}//end testRetryFailedSkipsSentRows()
}//end class

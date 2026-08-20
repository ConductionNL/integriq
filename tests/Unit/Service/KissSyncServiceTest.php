<?php

/**
 * Unit tests for KissSyncService.
 *
 * @category Test
 * @package  OCA\OpenConnector\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 *
 * @spec openspec/changes/kiss-kcc-bridge/tasks.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenConnector\Tests\Unit\Service;

use OCA\OpenConnector\Exception\KissProviderException;
use OCA\OpenConnector\Service\Kiss\KlantinteractiesClient;
use OCA\OpenConnector\Service\Kiss\LogKlantinteractiesProvider;
use OCA\OpenConnector\Service\KissSyncService;
use OCA\OpenConnector\Service\Security\RawSourceResolver;
use OCA\OpenConnector\Tests\Helpers\ObjectServiceMockBuilder;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService as ORObjectService;
use OCP\IL10N;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Tests for the KISS pull/push sync orchestration (cursor semantics, per-record
 * isolation, onderwerpobject-to-case mapping, provider selection).
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 * @SuppressWarnings(PHPMD.TooManyPublicMethods)
 *
 * @spec openspec/changes/kiss-kcc-bridge/specs/kiss-kcc-bridge/spec.md
 */
class KissSyncServiceTest extends TestCase {

	/**
	 * @var ORObjectService|\PHPUnit\Framework\MockObject\MockObject
	 */
	private $objectService;

	/**
	 * @var LogKlantinteractiesProvider|\PHPUnit\Framework\MockObject\MockObject
	 */
	private $logProvider;

	/**
	 * @var KlantinteractiesClient|\PHPUnit\Framework\MockObject\MockObject
	 */
	private $restProvider;

	/**
	 * @var IL10N|\PHPUnit\Framework\MockObject\MockObject
	 */
	private $l;

	/**
	 * @var LoggerInterface|\PHPUnit\Framework\MockObject\MockObject
	 */
	private $logger;

	/**
	 * @var KissSyncService
	 */
	private KissSyncService $service;

	/**
	 * Every saveObject invocation captured as [schema => list of {object, uuid}].
	 *
	 * @var array<string, array<int, array{object: array, uuid: string|null}>>
	 */
	private array $saved = [];

	/**
	 * Pre-seeded existing kiss_klantcontact rows, keyed by kissId — makes findByKissId() return
	 * a match so the corresponding upsert exercises the "changed" (update) path.
	 *
	 * @var array<string, array>
	 */
	private array $existingByKissId = [];

	/**
	 * Pre-seeded openconnector `source` rows returned for schema=source lookups.
	 *
	 * @var array<int, ObjectEntity>
	 */
	private array $sources = [];

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
		$this->logProvider = $this->createMock(LogKlantinteractiesProvider::class);
		$this->restProvider = $this->createMock(KlantinteractiesClient::class);
		$this->l = $this->createMock(IL10N::class);
		$this->l->method('t')->willReturnArgument(0);
		$this->logger = $this->createMock(LoggerInterface::class);

		$this->saved = [];
		$this->existingByKissId = [];
		$this->sources = [];

		$this->objectService->method('findAll')->willReturnCallback(
			function (array $config): array {
				$filters = ($config['filters'] ?? []);
				$schema = ($filters['schema'] ?? null);

				if ($schema === KissSyncService::SCHEMA_SOURCE) {
					return ['results' => $this->sources];
				}

				if ($schema === KissSyncService::SCHEMA_KLANTCONTACT) {
					$kissId = ($filters['kissId'] ?? null);
					if ($kissId !== null && isset($this->existingByKissId[$kissId]) === true) {
						return ['results' => [$this->entity($this->existingByKissId[$kissId], 'existing-' . $kissId)]];
					}

					return ['results' => []];
				}

				return ['results' => []];
			}
		);

		$this->objectService->method('saveObject')->willReturnCallback(
			function ($object, $register = null, $schema = null, $uuid = null): ObjectEntity {
				$key = (string)$schema;
				$this->saved[$key][] = ['object' => $object, 'uuid' => $uuid];
				return $this->entity($object, ($uuid ?? 'saved-uuid-' . count($this->saved[$key])));
			}
		);

		$this->service = new KissSyncService(
			$this->objectService,
			$this->logProvider,
			$this->restProvider,
			$this->l,
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
	 * A KISS source entity (type kiss, rest provider by default).
	 *
	 * @param array $configuration Extra configuration merged over the default.
	 * @param string $uuid Entity uuid.
	 *
	 * @return ObjectEntity
	 */
	private function sourceEntity(array $configuration = [], string $uuid = 'source-1'): ObjectEntity {
		return $this->entity(
			[
				'type' => 'kiss',
				'isEnabled' => true,
				'configuration' => array_merge(['provider' => 'rest'], $configuration),
			],
			$uuid
		);

	}//end sourceEntity()

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
	 * pullAll() with no configured KISS source is a clean no-op (0 processed, no saves).
	 *
	 * @return void
	 */
	public function testPullAllWithNoSourceIsCleanNoOp(): void {
		$processed = $this->service->pullAll();

		$this->assertSame(0, $processed);
		$this->assertArrayNotHasKey(KissSyncService::SCHEMA_KLANTCONTACT, $this->saved);

	}//end testPullAllWithNoSourceIsCleanNoOp()

	/**
	 * pullSource() upserts new klantcontacten and advances the cursor to the page's max registratiedatum.
	 *
	 * @return void
	 */
	public function testPullSourceUpsertsNewRecordsAndAdvancesCursor(): void {
		$source = $this->sourceEntity();

		$this->restProvider->method('listCustomerContacts')->willReturn(
			[
				'items' => [
					['uuid' => 'kc-a', 'onderwerp' => 'Vraag A', 'registratiedatum' => '2026-07-01T10:00:00+00:00'],
					['uuid' => 'kc-b', 'onderwerp' => 'Vraag B', 'registratiedatum' => '2026-07-02T10:00:00+00:00'],
				],
				'nextCursor' => '2026-07-02T10:00:00+00:00',
			]
		);

		$outcome = $this->service->pullSource(source: $source);

		$this->assertSame(2, $outcome['processed']);
		$this->assertSame(0, $outcome['skipped']);
		$this->assertSame('2026-07-02T10:00:00+00:00', $outcome['cursor']);

		$this->assertCount(2, $this->saved[KissSyncService::SCHEMA_KLANTCONTACT]);
		// New records: saved without a uuid (create, not update).
		$this->assertNull($this->saved[KissSyncService::SCHEMA_KLANTCONTACT][0]['uuid']);

		$sourceSave = $this->saved[KissSyncService::SCHEMA_SOURCE][0];
		$this->assertSame('source-1', $sourceSave['uuid']);
		$this->assertSame(
			'2026-07-02T10:00:00+00:00',
			$sourceSave['object']['configuration']['cursor']['lastRegistratiedatum']
		);

	}//end testPullSourceUpsertsNewRecordsAndAdvancesCursor()

	/**
	 * pullSource() passes the source's persisted cursor as `since` to the provider.
	 *
	 * @return void
	 */
	public function testPullSourcePassesPersistedCursorAsSince(): void {
		$source = $this->sourceEntity(['cursor' => ['lastRegistratiedatum' => '2026-06-15T00:00:00+00:00']]);

		$this->restProvider->expects($this->once())
			->method('listCustomerContacts')
			->with(
				$this->anything(),
				$this->equalTo('2026-06-15T00:00:00+00:00'),
				$this->anything()
			)
			->willReturn(['items' => [], 'nextCursor' => null]);

		$this->service->pullSource(source: $source);

	}//end testPullSourcePassesPersistedCursorAsSince()

	/**
	 * An already-seen kissId is updated in place (changed record), not duplicated.
	 *
	 * @return void
	 */
	public function testPullSourceUpdatesExistingRecordInPlace(): void {
		$this->existingByKissId['kc-a'] = ['kissId' => 'kc-a', 'onderwerp' => 'Old subject'];

		$source = $this->sourceEntity();
		$this->restProvider->method('listCustomerContacts')->willReturn(
			[
				'items' => [
					['uuid' => 'kc-a', 'onderwerp' => 'New subject', 'registratiedatum' => '2026-07-01T10:00:00+00:00'],
				],
				'nextCursor' => '2026-07-01T10:00:00+00:00',
			]
		);

		$this->service->pullSource(source: $source);

		$save = $this->saved[KissSyncService::SCHEMA_KLANTCONTACT][0];
		$this->assertSame('existing-kc-a', $save['uuid']);
		$this->assertSame('New subject', $save['object']['onderwerp']);

	}//end testPullSourceUpdatesExistingRecordInPlace()

	/**
	 * Per-record isolation: one malformed/unpersistable klantcontact is skipped without aborting the rest of the page.
	 *
	 * @return void
	 */
	public function testPullSourceIsolatesOneFailingRecord(): void {
		$source = $this->sourceEntity();
		$this->restProvider->method('listCustomerContacts')->willReturn(
			[
				'items' => [
					['uuid' => 'kc-good-1', 'registratiedatum' => '2026-07-01T10:00:00+00:00'],
					['uuid' => '', 'registratiedatum' => '2026-07-01T11:00:00+00:00'],
					['uuid' => 'kc-good-2', 'registratiedatum' => '2026-07-01T12:00:00+00:00'],
				],
				'nextCursor' => '2026-07-01T12:00:00+00:00',
			]
		);

		$outcome = $this->service->pullSource(source: $source);

		$this->assertSame(2, $outcome['processed']);
		$this->assertSame(1, $outcome['skipped']);
		// Cursor still advances to the page max despite the one skipped record.
		$this->assertSame('2026-07-01T12:00:00+00:00', $outcome['cursor']);
		$this->logger->expects($this->never())->method('error');

	}//end testPullSourceIsolatesOneFailingRecord()

	/**
	 * An empty page (no new/changed klantcontacten) does not advance the cursor or write to the source.
	 *
	 * @return void
	 */
	public function testPullSourceWithEmptyPageDoesNotAdvanceCursor(): void {
		$source = $this->sourceEntity();
		$this->restProvider->method('listCustomerContacts')->willReturn(['items' => [], 'nextCursor' => null]);

		$outcome = $this->service->pullSource(source: $source);

		$this->assertSame(0, $outcome['processed']);
		$this->assertNull($outcome['cursor']);
		$this->assertArrayNotHasKey(KissSyncService::SCHEMA_SOURCE, $this->saved);

	}//end testPullSourceWithEmptyPageDoesNotAdvanceCursor()

	/**
	 * A klantcontact whose onderwerpobjecten identify a zaak maps to caseReference/caseObjectType.
	 *
	 * @return void
	 */
	public function testMapsValidZaakOnderwerpobjectToCaseReference(): void {
		$source = $this->sourceEntity();
		$this->restProvider->method('listCustomerContacts')->willReturn(
			[
				'items' => [
					[
						'uuid' => 'kc-a',
						'registratiedatum' => '2026-07-01T10:00:00+00:00',
						'onderwerpobjecten' => [
							[
								'onderwerpobjectidentificator' => [
									'objectId' => '11111111-2222-3333-4444-555555555555',
									'codeObjecttype' => 'zaak',
								],
							],
						],
					],
				],
				'nextCursor' => '2026-07-01T10:00:00+00:00',
			]
		);

		$this->service->pullSource(source: $source);

		$saved = $this->saved[KissSyncService::SCHEMA_KLANTCONTACT][0]['object'];
		$this->assertSame('11111111-2222-3333-4444-555555555555', $saved['caseReference']);
		$this->assertSame('zaak', $saved['caseObjectType']);

	}//end testMapsValidZaakOnderwerpobjectToCaseReference()

	/**
	 * A klantcontact with no onderwerpobjecten maps to a null caseReference.
	 *
	 * @return void
	 */
	public function testMissingOnderwerpobjectenMapsToNullCaseReference(): void {
		$source = $this->sourceEntity();
		$this->restProvider->method('listCustomerContacts')->willReturn(
			[
				'items' => [['uuid' => 'kc-a', 'registratiedatum' => '2026-07-01T10:00:00+00:00']],
				'nextCursor' => '2026-07-01T10:00:00+00:00',
			]
		);

		$this->service->pullSource(source: $source);

		$saved = $this->saved[KissSyncService::SCHEMA_KLANTCONTACT][0]['object'];
		$this->assertNull($saved['caseReference']);
		$this->assertNull($saved['caseObjectType']);

	}//end testMissingOnderwerpobjectenMapsToNullCaseReference()

	/**
	 * An onderwerpobject identifying a "foreign" (non-case) object type is not misattributed as a case.
	 *
	 * @return void
	 */
	public function testForeignOnderwerpobjectIsNotMappedAsCase(): void {
		$source = $this->sourceEntity();
		$this->restProvider->method('listCustomerContacts')->willReturn(
			[
				'items' => [
					[
						'uuid' => 'kc-a',
						'registratiedatum' => '2026-07-01T10:00:00+00:00',
						'onderwerpobjecten' => [
							[
								'onderwerpobjectidentificator' => [
									'objectId' => 'partij-uuid-1',
									'codeObjecttype' => 'partij',
								],
							],
						],
					],
				],
				'nextCursor' => '2026-07-01T10:00:00+00:00',
			]
		);

		$this->service->pullSource(source: $source);

		$saved = $this->saved[KissSyncService::SCHEMA_KLANTCONTACT][0]['object'];
		$this->assertNull($saved['caseReference']);
		$this->assertNull($saved['caseObjectType']);
		// The raw onderwerpobjecten are still preserved verbatim.
		$this->assertSame('partij', $saved['onderwerpobjecten'][0]['onderwerpobjectidentificator']['codeObjecttype']);

	}//end testForeignOnderwerpobjectIsNotMappedAsCase()

	/**
	 * A raw BSN in betrokkenen.partijIdentificator is SHA-256-hashed before storage.
	 *
	 * @return void
	 */
	public function testRawBsnInBetrokkenenIsHashedBeforeStorage(): void {
		$source = $this->sourceEntity();
		$this->restProvider->method('listCustomerContacts')->willReturn(
			[
				'items' => [
					[
						'uuid' => 'kc-a',
						'registratiedatum' => '2026-07-01T10:00:00+00:00',
						// Wire name: this fixture stands in for what KISS returns.
						'betrokkenen' => [
							[
								'rol' => 'klant',
								'partijIdentificator' => ['codeSoortObjectId' => 'bsn', 'objectId' => '123456789'],
							],
						],
					],
				],
				'nextCursor' => '2026-07-01T10:00:00+00:00',
			]
		);

		$this->service->pullSource(source: $source);

		$saved = $this->saved[KissSyncService::SCHEMA_KLANTCONTACT][0]['object'];
		$storedValue = $saved['involvedParties'][0]['partijIdentificator']['objectId'];
		$this->assertNotSame('123456789', $storedValue);
		$this->assertSame(hash('sha256', '123456789'), $storedValue);

	}//end testRawBsnInBetrokkenenIsHashedBeforeStorage()

	/**
	 * pushKlantcontact() without any active KISS source raises KissProviderException.
	 *
	 * @return void
	 */
	public function testPushWithoutActiveSourceThrows(): void {
		$this->expectException(KissProviderException::class);

		$this->service->pushCustomerContact(input: ['onderwerp' => 'Vraag', 'channel' => 'telefoon']);

	}//end testPushWithoutActiveSourceThrows()

	/**
	 * pushKlantcontact() with a caseReference creates the klantcontact, links the onderwerpobject,
	 * and returns the KISS id + local record uuid.
	 *
	 * @return void
	 */
	public function testPushWithCaseReferenceCreatesAndLinks(): void {
		$this->sources[] = $this->sourceEntity();

		$this->restProvider->expects($this->once())
			->method('createCustomerContact')
			->willReturn('kiss-id-1');
		$this->restProvider->expects($this->once())
			->method('linkOnderwerpobject')
			->with($this->anything(), 'kiss-id-1', 'case-uuid-1', 'zaak')
			->willReturn('obj-id-1');

		$result = $this->service->pushCustomerContact(
			input: [
				'onderwerp' => 'Melding',
				'channel' => 'telefoon',
				'caseReference' => 'case-uuid-1',
				'sourceApp' => 'procest',
			]
		);

		$this->assertSame('kiss-id-1', $result['id']);
		$this->assertNotEmpty($result['localUuid']);

		$saved = $this->saved[KissSyncService::SCHEMA_KLANTCONTACT][0]['object'];
		$this->assertSame('kiss-id-1', $saved['kissId']);
		$this->assertSame('pushed', $saved['direction']);
		$this->assertSame('procest', $saved['sourceApp']);
		$this->assertSame('case-uuid-1', $saved['caseReference']);

	}//end testPushWithCaseReferenceCreatesAndLinks()

	/**
	 * pushKlantcontact() without a caseReference creates the klantcontact but never links an onderwerpobject.
	 *
	 * @return void
	 */
	public function testPushWithoutCaseReferenceSkipsLinking(): void {
		$this->sources[] = $this->sourceEntity();

		$this->restProvider->method('createCustomerContact')->willReturn('kiss-id-2');
		$this->restProvider->expects($this->never())->method('linkOnderwerpobject');

		$result = $this->service->pushCustomerContact(input: ['onderwerp' => 'Melding', 'channel' => 'e-mail']);

		$this->assertSame('kiss-id-2', $result['id']);
		$saved = $this->saved[KissSyncService::SCHEMA_KLANTCONTACT][0]['object'];
		$this->assertNull($saved['caseReference']);

	}//end testPushWithoutCaseReferenceSkipsLinking()
}//end class

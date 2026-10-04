<?php

/**
 * Unit tests for DsoIngestService.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 *
 * @spec openspec/changes/dso-connector-adapter/tasks.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Service;

use OCA\Integriq\BackgroundJob\FetchDsoAttachmentsJob;
use OCA\Integriq\Exception\DsoProviderException;
use OCA\Integriq\Exception\DsoTranslationException;
use OCA\Integriq\Service\Dso\DsoActivityMapper;
use OCA\Integriq\Service\Dso\DsoActivityTable;
use OCA\Integriq\Service\Dso\DsoClient;
use OCA\Integriq\Service\Dso\DsoIdentity;
use OCA\Integriq\Service\Dso\DsoRequestTranslator;
use OCA\Integriq\Service\Dso\LogDsoConnectorProvider;
use OCA\Integriq\Service\DsoIngestService;
use OCA\Integriq\Service\DSOParserService;
use OCA\Integriq\Service\Security\RawSourceResolver;
use OCA\Integriq\Tests\Helpers\RegisterSchemaValidator;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Exception\HandoffException;
use OCA\OpenRegister\Service\Handoff\HandoffService;
use OCA\OpenRegister\Service\ObjectService as ORObjectService;
use OCP\BackgroundJob\IJobList;
use OCP\IUser;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Tests for verzoek ingest (persist + translate), the authenticated handoff
 * trigger, and the outbound status/besluit post — including per-verzoek/
 * per-message failure isolation and the not-configured outbound path.
 *
 * @spec openspec/changes/dso-connector-adapter/specs/dso-connector-adapter/spec.md
 */
class DsoIngestServiceTest extends TestCase {

	/**
	 * In-memory `dso_verzoek` store keyed by uuid.
	 *
	 * @var array<string, ObjectEntity>
	 */
	private array $requestStore = [];

	/**
	 * In-memory `dso_message` store (append-only, indexed).
	 *
	 * @var array<int, ObjectEntity>
	 */
	private array $messageStore = [];

	/**
	 * In-memory `source` fixtures.
	 *
	 * @var array<int, ObjectEntity>
	 */
	private array $sourceFixtures = [];

	/**
	 * In-memory `dso_activity_mapping` rows. Only an engine read (`_rbac`
	 * false) sees them, as on a live instance where the intake account is no
	 * administrator.
	 *
	 * @var array<int, ObjectEntity>
	 */
	private array $mappingRows = [];

	/**
	 * How often the mapping table was read.
	 *
	 * @var int
	 */
	private int $mappingReads = 0;

	private int $uuidCounter = 0;

	private HandoffService $handoffService;

	private DsoClient $restProvider;

	private IJobList $jobList;

	/**
	 * Every IJobList::add() call, as [class, argument].
	 *
	 * @var array<int, array{0: string, 1: mixed}>
	 */
	private array $queued = [];

	private function buildEntity(array $data, string $uuid): ObjectEntity {
		$entity = new ObjectEntity();
		$entity->setUuid($uuid);
		$entity->setObject($data);

		return $entity;
	}//end buildEntity()

	/**
	 * @return ORObjectService|\PHPUnit\Framework\MockObject\MockObject
	 */
	private function buildObjectService() {
		$mock = $this->getMockBuilder(ORObjectService::class)
			->disableOriginalConstructor()
			->getMock();

		$mock->method('saveObject')->willReturnCallback(
			function ($object, ?string $register = null, ?string $schema = null, ?string $uuid = null) {
				if ($schema === DsoIngestService::SCHEMA_MESSAGE) {
					$entity = $this->buildEntity((array)$object, 'message-' . (++$this->uuidCounter));
					$this->messageStore[] = $entity;
					return $entity;
				}

				if ($schema !== DsoIngestService::SCHEMA_VERZOEK) {
					return $this->buildEntity((array)$object, ($uuid ?? 'other-' . (++$this->uuidCounter)));
				}

				$resolvedUuid = ($uuid ?? 'verzoek-' . (++$this->uuidCounter));
				$entity = $this->buildEntity((array)$object, $resolvedUuid);
				$this->requestStore[$resolvedUuid] = $entity;

				return $entity;
			}
		);

		$mock->method('find')->willReturnCallback(
			function ($id, ?string $register = null, ?string $schema = null) {
				if ($schema === DsoIngestService::SCHEMA_VERZOEK) {
					return ($this->requestStore[(string)$id] ?? null);
				}

				// RawSourceResolver re-reads the located source by uuid with
				// `_render: false` (ocon#242). The fake must model that read, or
				// the synthetic fallback below silently REPLACES the source with
				// a credential-free stub and the assertions stop meaning anything.
				if ($schema === DsoIngestService::SCHEMA_SOURCE) {
					foreach ($this->sourceFixtures as $sourceFixture) {
						if ($sourceFixture->getUuid() === (string)$id) {
							return $sourceFixture;
						}
					}

					return null;
				}

				// Handoff-target lookups: any non-verzoek find() returns a synthetic target entity.
				return $this->buildEntity(['title' => 'Case'], (string)$id);
			}
		);

		$mock->method('findAll')->willReturnCallback(
			function (array $config = [], bool $_rbac = true) {
				$schema = ($config['filters']['schema'] ?? null);
				if ($schema === DsoActivityTable::SCHEMA) {
					$this->mappingReads++;
					if ($_rbac !== false) {
						return ['results' => [], 'total' => 0];
					}

					return ['results' => $this->mappingRows, 'total' => count($this->mappingRows)];
				}

				if ($schema === DsoIngestService::SCHEMA_SOURCE) {
					return ['results' => $this->sourceFixtures, 'total' => count($this->sourceFixtures)];
				}

				if ($schema === DsoIngestService::SCHEMA_VERZOEK) {
					$status = ($config['filters']['status'] ?? null);
					$results = array_values($this->requestStore);
					if ($status !== null) {
						$results = array_values(
							array_filter(
								$results,
								static fn (ObjectEntity $e): bool => (($e->getObject()['status'] ?? null) === $status)
							)
						);
					}

					return ['results' => $results, 'total' => count($results)];
				}

				return ['results' => [], 'total' => 0];
			}
		);

		return $mock;
	}//end buildObjectService()

	private function buildService(): DsoIngestService {
		$objectService = $this->buildObjectService();

		$this->handoffService = $this->getMockBuilder(HandoffService::class)
			->disableOriginalConstructor()
			->getMock();

		$this->restProvider = $this->getMockBuilder(DsoClient::class)
			->disableOriginalConstructor()
			->getMock();

		$this->queued = [];
		$this->jobList = $this->createMock(IJobList::class);
		$this->jobList->method('add')->willReturnCallback(
			function ($job, $argument = null): void {
				$this->queued[] = [$job, $argument];
			}
		);

		return new DsoIngestService(
			objectService: $objectService,
			handoffService: $this->handoffService,
			translator: new DsoRequestTranslator(),
			logProvider: new LogDsoConnectorProvider(),
			restProvider: $this->restProvider,
			logger: $this->createMock(LoggerInterface::class),
			rawSourceResolver: new RawSourceResolver($objectService, $this->createMock(LoggerInterface::class)),
			jobList: $this->jobList,
			activityMapper: new DsoActivityMapper(new DsoActivityTable($objectService))
		);

	}//end buildService()

	/**
	 * Store one `dso_activity_mapping` row.
	 *
	 * @param string $uuid The row's uuid.
	 * @param array<string, mixed> $row The row.
	 *
	 * @return array<string, mixed> The row as stored.
	 */
	private function addMappingRow(string $uuid, array $row): array {
		$this->assertSame([], RegisterSchemaValidator::errors(schemaSlug: DsoActivityTable::SCHEMA, object: $row), 'The fixture row is one the schema accepts');
		$this->mappingRows[] = $this->buildEntity($row, $uuid);

		return $row;
	}//end addMappingRow()

	/**
	 * A verzoek with these activiteiten, run through the real parser.
	 *
	 * @param string $verzoekId The verzoek id.
	 * @param array<int, array<string, mixed>> $activiteiten The raw activiteiten.
	 *
	 * @return array<string, mixed> The parsed verzoek.
	 */
	private function parsed(string $verzoekId, array $activiteiten): array {
		return (new DSOParserService(new NullLogger()))->parseRequest(
			payload: ['verzoekId' => $verzoekId, 'type' => 'aanvraag', 'activiteiten' => $activiteiten]
		);
	}//end parsed()

	/**
	 * The request fields the activity mapping writes, plus the required ones,
	 * so the schema check judges exactly what the mapping adds.
	 *
	 * @param array<string, mixed> $data The saved request.
	 *
	 * @return array<string, mixed> The subset.
	 */
	private function mappingFields(array $data): array {
		$keys = ['verzoekId', 'status', 'mappedActivities', 'mappedCaseTypes', 'samenloopStrategy', 'activityUnmapped'];

		return array_intersect_key($data, array_flip($keys));
	}//end mappingFields()

	private function addSourceFixture(array $configuration, bool $enabled = true): void {
		$this->sourceFixtures[] = $this->buildEntity(
			['type' => 'dso', 'isEnabled' => $enabled, 'configuration' => $configuration],
			'source-dso'
		);

	}//end addSourceFixture()

	/**
	 * @spec openspec/changes/dso-connector-adapter/specs/dso-connector-adapter/spec.md#scenario-successful-ingest-reaches-mapped
	 */
	public function testIngestReachesMappedForValidVerzoek(): void {
		$service = $this->buildService();

		$request = $service->ingest(
			parsedRequest: [
				'verzoekId' => 'dso-1',
				'type' => 'aanvraag',
				'activiteiten' => [['activityId' => 'Demo-0000-Bouwen', 'activityName' => 'Bouwen van een woning']],
			]
		);

		$this->assertSame('mapped', $request->getObject()['status']);
		$this->assertSame('Bouwen van een woning', $request->getObject()['mappedTitle']);

	}//end testIngestReachesMappedForValidVerzoek()

	/**
	 * @spec openspec/changes/dso-connector-adapter/specs/dso-connector-adapter/spec.md#scenario-a-verzoek-with-no-verzoekid-is-refused-not-fabricated
	 */
	public function testIngestFailsClosedForMissingVerzoekId(): void {
		$service = $this->buildService();

		$request = $service->ingest(parsedRequest: ['type' => 'aanvraag']);

		$this->assertSame('failed', $request->getObject()['status']);
		$this->assertStringContainsString('verzoekId', (string)$request->getObject()['errorDetail']);

	}//end testIngestFailsClosedForMissingVerzoekId()

	/**
	 * Intake writes every bijlage reference as a `pending` attachment entry,
	 * and the register accepts what it writes.
	 *
	 * @spec openspec/changes/dso-attachments-on-the-request/specs/dso-omgevingsloket/spec.md#scenario-the-endpoint-does-not-wait-for-the-bijlagen
	 */
	public function testIngestWritesBijlagenAsPendingAttachments(): void {
		$service = $this->buildService();

		$request = $service->ingest(
			parsedRequest: [
				'verzoekId' => 'dso-3',
				'type' => 'aanvraag',
				'bijlagen' => [
					['name' => 'bouwtekening.pdf', 'url' => 'https://dso-lv.nl/docs/abc123'],
					['name' => 'constructie.pdf', 'url' => 'https://dso-lv.nl/docs/def456'],
				],
			]
		);

		$attachments = $request->getObject()['attachments'];
		$this->assertSame(
			[
				['name' => 'bouwtekening.pdf', 'url' => 'https://dso-lv.nl/docs/abc123', 'status' => 'pending', 'attempts' => 0],
				['name' => 'constructie.pdf', 'url' => 'https://dso-lv.nl/docs/def456', 'status' => 'pending', 'attempts' => 0],
			],
			$attachments
		);
		$this->assertSame(
			[],
			RegisterSchemaValidator::errors(
				schemaSlug: 'dso_verzoek',
				object: ['verzoekId' => 'dso-3', 'status' => 'mapped', 'attachments' => $attachments]
			)
		);

	}//end testIngestWritesBijlagenAsPendingAttachments()

	/**
	 * Two bijlagen with the same name get distinct file names, because they
	 * land in the same object folder.
	 *
	 * @spec openspec/changes/dso-attachments-on-the-request/specs/dso-omgevingsloket/spec.md#scenario-multiple-bijlagen-downloaded-and-linked
	 */
	public function testIngestMakesAttachmentNamesUnique(): void {
		$service = $this->buildService();

		$request = $service->ingest(
			parsedRequest: [
				'verzoekId' => 'dso-4',
				'type' => 'aanvraag',
				'bijlagen' => [
					['name' => 'tekening.pdf', 'url' => 'https://dso-lv.nl/docs/1'],
					['name' => 'Tekening.pdf', 'url' => 'https://dso-lv.nl/docs/2'],
					['name' => 'tekening.pdf', 'url' => 'https://dso-lv.nl/docs/3'],
					['name' => 'notitie', 'url' => 'https://dso-lv.nl/docs/4'],
					['name' => 'notitie', 'url' => 'https://dso-lv.nl/docs/5'],
				],
			]
		);

		$this->assertSame(
			['tekening.pdf', 'Tekening (2).pdf', 'tekening (3).pdf', 'notitie', 'notitie (2)'],
			array_column($request->getObject()['attachments'], 'name')
		);

	}//end testIngestMakesAttachmentNamesUnique()

	/**
	 * Intake queues one download job for a request with bijlagen, carrying
	 * the request's uuid, and none for a request without.
	 *
	 * @spec openspec/changes/dso-attachments-on-the-request/specs/dso-omgevingsloket/spec.md#scenario-the-endpoint-does-not-wait-for-the-bijlagen
	 */
	public function testIngestQueuesTheDownloadOnlyWhenThereAreBijlagen(): void {
		$service = $this->buildService();

		$without = $service->ingest(parsedRequest: ['verzoekId' => 'dso-5', 'type' => 'aanvraag']);
		$this->assertSame([], $this->queued);
		$this->assertSame([], $without->getObject()['attachments']);

		$with = $service->ingest(
			parsedRequest: [
				'verzoekId' => 'dso-6',
				'type' => 'aanvraag',
				'bijlagen' => [['name' => 'tekening.pdf', 'url' => 'https://dso-lv.nl/docs/1']],
			]
		);
		$this->assertSame(
			[[FetchDsoAttachmentsJob::class, ['requestUuid' => $with->getUuid(), 'actingUserId' => '']]],
			$this->queued
		);
		$this->assertSame('pending', $with->getObject()['attachments'][0]['status']);

		$failed = $service->ingest(
			parsedRequest: ['type' => 'aanvraag', 'bijlagen' => [['name' => 'a.pdf', 'url' => 'https://dso-lv.nl/docs/2']]]
		);
		$this->assertSame('failed', $failed->getObject()['status']);
		$this->assertCount(2, $this->queued, 'A request whose mapping failed still gets its bijlagen.');

	}//end testIngestQueuesTheDownloadOnlyWhenThereAreBijlagen()

	/**
	 * One of the demo rows the mock register ships, without its `@self`.
	 *
	 * @param string $slug The demo row's slug.
	 *
	 * @return array<string, mixed> The row.
	 */
	private function demoRow(string $slug): array {
		$mock = json_decode((string)file_get_contents(__DIR__ . '/../../../lib/Settings/integriq_mock_register.json'), true);
		foreach ($mock['components']['objects'] as $object) {
			if (($object['@self']['slug'] ?? null) === $slug) {
				unset($object['@self']);
				return $object;
			}
		}

		$this->fail('No demo row ' . $slug);
	}//end demoRow()

	/**
	 * Intake maps an activiteit on its imowId through the table in
	 * OpenRegister: the entry keeps the STAM identifiers and gains the row's
	 * case types and strategy, and the register accepts what intake writes.
	 *
	 * @spec openspec/changes/dso-activity-mapping-table/specs/dso-omgevingsloket/spec.md#scenario-one-to-one-activiteit-mapping-creates-zaak
	 */
	public function testIngestMapsAnActiviteitOnItsImowId(): void {
		$this->addMappingRow('row-bouwen', $this->demoRow('dso-activity-mapping-demo-bouwen'));
		$service = $this->buildService();

		$data = $service->ingest(
			parsedRequest: $this->parsed(
				'dso-map-1',
				[['imowId' => 'nl.imow-gm0000.activiteit.DemoBouwen', 'activityName' => 'Bouwen van een woning', 'volgnr' => 1]]
			)
		)->getObject();

		$this->assertSame(
			[
				[
					'imowId' => 'nl.imow-gm0000.activiteit.DemoBouwen',
					'activityName' => 'Bouwen van een woning',
					'volgnr' => '1',
					'mapped' => true,
					'matchedOn' => 'imowId',
					'mappingRow' => 'row-bouwen',
					'caseTypes' => [['reference' => 'DEMO-ZAAKTYPE-BOUWEN', 'title' => 'Demo: omgevingsvergunning bouwen', 'department' => 'Demo: team bouwen']],
					'samenloopStrategy' => 'deelzaken',
				],
			],
			$data['mappedActivities']
		);
		$this->assertSame(['DEMO-ZAAKTYPE-BOUWEN'], $data['mappedCaseTypes']);
		$this->assertSame('deelzaken', $data['samenloopStrategy']);
		$this->assertFalse($data['activityUnmapped']);
		$this->assertSame('mapped', $data['status']);
		$this->assertSame([], RegisterSchemaValidator::errors(schemaSlug: 'dso_verzoek', object: $this->mappingFields(data: $data)));

	}//end testIngestMapsAnActiviteitOnItsImowId()

	/**
	 * Without an imowId the activityId matches; an old push with only `code`
	 * is read as that activityId.
	 *
	 * @spec openspec/changes/dso-activity-mapping-table/specs/dso-omgevingsloket/spec.md#scenario-the-fallback-identifier-matches
	 */
	public function testIngestFallsBackOnTheActivityId(): void {
		$this->addMappingRow(
			'row-uitrit',
			[
				'activityId' => 'Demo-0000-Uitrit',
				'activityName' => 'Demo: uitrit aanleggen',
				'caseTypes' => [['reference' => 'DEMO-ZAAKTYPE-UITRIT']],
				'samenloopStrategy' => 'gecombineerd',
			]
		);
		$service = $this->buildService();

		$data = $service->ingest(
			parsedRequest: $this->parsed('dso-map-2', [['code' => 'Demo-0000-Uitrit', 'omschrijving' => 'Uitrit']])
		)->getObject();

		$this->assertSame(
			[
				'activityId' => 'Demo-0000-Uitrit',
				'activityName' => 'Uitrit',
				'mapped' => true,
				'matchedOn' => 'activityId',
				'mappingRow' => 'row-uitrit',
				'caseTypes' => [['reference' => 'DEMO-ZAAKTYPE-UITRIT']],
				'samenloopStrategy' => 'gecombineerd',
			],
			$data['mappedActivities'][0]
		);
		$this->assertSame('gecombineerd', $data['samenloopStrategy']);
		$this->assertSame('Uitrit', $data['mappedTitle'], 'The title still reads the activity name');
		$this->assertSame([], RegisterSchemaValidator::errors(schemaSlug: 'dso_verzoek', object: $this->mappingFields(data: $data)));

	}//end testIngestFallsBackOnTheActivityId()

	/**
	 * The onderliggende activiteit is tried first: its row decides, even
	 * when the parent activity has a row too.
	 *
	 * @spec openspec/changes/dso-activity-mapping-table/specs/dso-omgevingsloket/spec.md#scenario-the-onderliggende-activiteit-wins
	 */
	public function testTheUnderlyingActiviteitWins(): void {
		$this->addMappingRow('row-milieu', $this->demoRow('dso-activity-mapping-demo-milieu'));
		$this->addMappingRow(
			'row-tankstation',
			[
				'imowId' => 'nl.imow-gm0000.activiteit.DemoTankstation',
				'activityName' => 'Demo: tankstation',
				'caseTypes' => [['reference' => 'DEMO-ZAAKTYPE-TANKSTATION', 'department' => 'Demo: team externe veiligheid']],
				'samenloopStrategy' => 'deelzaken',
			]
		);
		$service = $this->buildService();

		$data = $service->ingest(
			parsedRequest: $this->parsed(
				'dso-map-3',
				[
					[
						'imowId' => 'nl.imow-gm0000.activiteit.DemoMilieu',
						'activityName' => 'Milieubelastende activiteit',
						'underlying' => ['imowId' => 'nl.imow-gm0000.activiteit.DemoTankstation', 'activityName' => 'Tankstation'],
					],
				]
			)
		)->getObject();

		$entry = $data['mappedActivities'][0];
		$this->assertSame('underlying.imowId', $entry['matchedOn']);
		$this->assertSame('row-tankstation', $entry['mappingRow']);
		$this->assertSame(['imowId' => 'nl.imow-gm0000.activiteit.DemoTankstation', 'activityName' => 'Tankstation'], $entry['underlying']);
		$this->assertSame(['DEMO-ZAAKTYPE-TANKSTATION'], $data['mappedCaseTypes']);
		$this->assertSame([], RegisterSchemaValidator::errors(schemaSlug: 'dso_verzoek', object: $this->mappingFields(data: $data)));

	}//end testTheUnderlyingActiviteitWins()

	/**
	 * An inactive row is ignored, an activiteit no row maps is kept and
	 * flags the verzoek, and the mapped one still gets its case type.
	 *
	 * @spec openspec/specs/dso-omgevingsloket/spec.md#scenario-mixed-mapped-and-unmapped-activiteiten
	 */
	public function testAnInactiveRowIsIgnoredAndAnUnmappedActiviteitFlagsTheVerzoek(): void {
		$this->addMappingRow('row-bouwen', $this->demoRow('dso-activity-mapping-demo-bouwen'));
		$this->addMappingRow('row-kappen', (['isActive' => false] + $this->demoRow('dso-activity-mapping-demo-kappen')));
		$service = $this->buildService();

		$data = $service->ingest(
			parsedRequest: $this->parsed(
				'dso-map-4',
				[
					['imowId' => 'nl.imow-gm0000.activiteit.DemoBouwen'],
					['imowId' => 'nl.imow-gm0000.activiteit.DemoKappen', 'activityName' => 'Kappen'],
					['activityName' => 'Zonder identificatie'],
				]
			)
		)->getObject();

		$this->assertSame(
			[
				['imowId' => 'nl.imow-gm0000.activiteit.DemoKappen', 'activityName' => 'Kappen', 'mapped' => false],
				['activityName' => 'Zonder identificatie', 'mapped' => false],
			],
			array_slice($data['mappedActivities'], 1)
		);
		$this->assertTrue($data['mappedActivities'][0]['mapped']);
		$this->assertSame(['DEMO-ZAAKTYPE-BOUWEN'], $data['mappedCaseTypes']);
		$this->assertTrue($data['activityUnmapped']);
		$this->assertSame([], RegisterSchemaValidator::errors(schemaSlug: 'dso_verzoek', object: $this->mappingFields(data: $data)));

	}//end testAnInactiveRowIsIgnoredAndAnUnmappedActiviteitFlagsTheVerzoek()

	/**
	 * An empty table maps nothing, and nothing is built in to fall back on.
	 * The case type list is stored empty, not null.
	 *
	 * @spec openspec/changes/dso-activity-mapping-table/specs/dso-omgevingsloket/spec.md#scenario-a-fresh-install-ships-no-activity-codes
	 * @spec openspec/changes/dso-activity-mapping-table/specs/dso-omgevingsloket/spec.md#scenario-a-verzoek-that-maps-nothing-stores-an-empty-case-type-list
	 */
	public function testAnEmptyTableMapsNothing(): void {
		$service = $this->buildService();

		$data = $service->ingest(
			parsedRequest: $this->parsed('dso-map-5', [['code' => 'bouwen', 'omschrijving' => 'Bouwen']])
		)->getObject();

		$this->assertSame([['activityId' => 'bouwen', 'activityName' => 'Bouwen', 'mapped' => false]], $data['mappedActivities']);
		$this->assertSame([], $data['mappedCaseTypes']);
		$this->assertTrue($data['activityUnmapped']);
		$this->assertArrayNotHasKey('samenloopStrategy', $data);

	}//end testAnEmptyTableMapsNothing()

	/**
	 * One activity with two case types: the entry records both with their
	 * departments, and a case type two activities share is listed once.
	 *
	 * @spec openspec/changes/dso-activity-mapping-table/specs/dso-omgevingsloket/spec.md#scenario-one-to-many-mapping-creates-multiple-deelzaken
	 */
	public function testOneActivityMapsToSeveralCaseTypes(): void {
		$this->addMappingRow('row-milieu', $this->demoRow('dso-activity-mapping-demo-milieu'));
		$this->addMappingRow('row-bouwen', $this->demoRow('dso-activity-mapping-demo-bouwen'));
		$service = $this->buildService();

		$data = $service->ingest(
			parsedRequest: $this->parsed(
				'dso-map-6',
				[
					['imowId' => 'nl.imow-gm0000.activiteit.DemoMilieu'],
					['imowId' => 'nl.imow-gm0000.activiteit.DemoBouwen'],
				]
			)
		)->getObject();

		$this->assertSame(
			[
				['reference' => 'DEMO-ZAAKTYPE-MILIEU', 'title' => 'Demo: omgevingsvergunning milieu', 'department' => 'Demo: team milieu'],
				['reference' => 'DEMO-ZAAKTYPE-BOUWEN', 'title' => 'Demo: omgevingsvergunning bouwen', 'department' => 'Demo: team bouwen'],
			],
			$data['mappedActivities'][0]['caseTypes']
		);
		$this->assertSame(['DEMO-ZAAKTYPE-MILIEU', 'DEMO-ZAAKTYPE-BOUWEN'], $data['mappedCaseTypes']);
		$this->assertSame([], RegisterSchemaValidator::errors(schemaSlug: 'dso_verzoek', object: $this->mappingFields(data: $data)));

	}//end testOneActivityMapsToSeveralCaseTypes()

	/**
	 * A samenloop rule decides its pair: the demo kappen row combines with
	 * bouwen although both rows say deelzaken.
	 *
	 * @spec openspec/changes/dso-activity-mapping-table/specs/dso-omgevingsloket/spec.md#scenario-a-samenloop-rule-combines-a-pair
	 */
	public function testASamenloopRuleCombinesAPair(): void {
		$this->addMappingRow('row-bouwen', $this->demoRow('dso-activity-mapping-demo-bouwen'));
		$this->addMappingRow('row-kappen', $this->demoRow('dso-activity-mapping-demo-kappen'));
		$service = $this->buildService();

		$data = $service->ingest(
			parsedRequest: $this->parsed(
				'dso-map-7',
				[
					['imowId' => 'nl.imow-gm0000.activiteit.DemoBouwen'],
					['imowId' => 'nl.imow-gm0000.activiteit.DemoKappen'],
				]
			)
		)->getObject();

		$this->assertSame('gecombineerd', $data['samenloopStrategy']);

	}//end testASamenloopRuleCombinesAPair()

	/**
	 * One deelzaken rule splits a pair whose rows both say gecombineerd.
	 * Without rules, gecombineerd needs every row to say so.
	 *
	 * @spec openspec/changes/dso-activity-mapping-table/specs/dso-omgevingsloket/spec.md#requirement-samenloop-handling-req-dso-011
	 */
	public function testADeelzakenRuleSplitsAndWithoutRulesEveryRowDecides(): void {
		$row = static fn (string $id, string $strategy, array $rules = []): array => [
			'imowId' => 'nl.imow-gm0000.activiteit.' . $id,
			'activityName' => 'Demo: ' . $id,
			'caseTypes' => [['reference' => 'DEMO-' . $id]],
			'samenloopStrategy' => $strategy,
			'samenloopRules' => $rules,
		];
		$this->addMappingRow('row-a', $row('A', 'gecombineerd', [['withImowId' => 'nl.imow-gm0000.activiteit.B', 'strategy' => 'deelzaken']]));
		$this->addMappingRow('row-b', $row('B', 'gecombineerd'));
		$this->addMappingRow('row-c', $row('C', 'gecombineerd'));
		$this->addMappingRow('row-d', $row('D', 'deelzaken'));
		$service = $this->buildService();
		$strategy = fn (string $verzoekId, array $ids): string => $service->ingest(
			parsedRequest: $this->parsed(
				$verzoekId,
				array_map(static fn (string $id): array => ['imowId' => 'nl.imow-gm0000.activiteit.' . $id], $ids)
			)
		)->getObject()['samenloopStrategy'];

		$this->assertSame('deelzaken', $strategy('dso-rule-1', ['A', 'B']), 'The rule of row A decides the pair');
		$this->assertSame('deelzaken', $strategy('dso-rule-2', ['B', 'A']), 'Whichever row holds the rule');
		$this->assertSame('gecombineerd', $strategy('dso-rule-3', ['B', 'C']), 'No rule: both rows combine');
		$this->assertSame('deelzaken', $strategy('dso-rule-4', ['C', 'D']), 'No rule: one row splits');
		$this->assertSame('gecombineerd', $strategy('dso-rule-5', ['C']), 'One activity: its own row');

	}//end testADeelzakenRuleSplitsAndWithoutRulesEveryRowDecides()

	/**
	 * A row an administrator changes is used for the next verzoek, and the
	 * table is read once per verzoek.
	 *
	 * @spec openspec/changes/dso-activity-mapping-table/specs/dso-omgevingsloket/spec.md#scenario-modified-mapping-applied-to-next-verzoek
	 */
	public function testAChangedRowAppliesToTheNextVerzoek(): void {
		$this->addMappingRow('row-bouwen', $this->demoRow('dso-activity-mapping-demo-bouwen'));
		$service = $this->buildService();
		$activiteiten = [['imowId' => 'nl.imow-gm0000.activiteit.DemoBouwen'], ['imowId' => 'nl.imow-gm0000.activiteit.DemoBouwen']];

		$before = $service->ingest(parsedRequest: $this->parsed('dso-change-1', $activiteiten))->getObject();
		$this->mappingRows[0]->setObject(
			array_merge($this->mappingRows[0]->getObject(), ['caseTypes' => [['reference' => 'DEMO-ZAAKTYPE-BOUWEN-2']]])
		);
		$after = $service->ingest(parsedRequest: $this->parsed('dso-change-2', $activiteiten))->getObject();

		$this->assertSame(['DEMO-ZAAKTYPE-BOUWEN'], $before['mappedCaseTypes']);
		$this->assertSame(['DEMO-ZAAKTYPE-BOUWEN-2'], $after['mappedCaseTypes']);
		$this->assertSame(2, $this->mappingReads, 'One table read per verzoek, not per activiteit');

	}//end testAChangedRowAppliesToTheNextVerzoek()

	/**
	 * A request without activiteiten maps to nothing and is not flagged; it
	 * gets no samenloop strategy, because there is nothing to combine.
	 *
	 * @spec openspec/specs/dso-omgevingsloket/spec.md#requirement-activiteiten-to-zaaktype-mapping-req-dso-010
	 */
	public function testIngestWithoutActiviteitenMapsNothing(): void {
		$service = $this->buildService();

		$data = $service->ingest(parsedRequest: ['verzoekId' => 'dso-map-4', 'type' => 'melding'])->getObject();

		$this->assertSame([], $data['mappedActivities']);
		$this->assertSame([], $data['mappedCaseTypes']);
		$this->assertFalse($data['activityUnmapped']);
		$this->assertArrayNotHasKey('samenloopStrategy', $data);
		$this->assertSame([], RegisterSchemaValidator::errors(schemaSlug: 'dso_verzoek', object: $this->mappingFields(data: $data)));

	}//end testIngestWithoutActiviteitenMapsNothing()

	/**
	 * The register refuses an activity entry it does not declare, so a wrong
	 * field name in intake cannot pass the tests above by accident.
	 *
	 * @spec openspec/specs/dso-omgevingsloket/spec.md#requirement-activiteiten-to-zaaktype-mapping-req-dso-010
	 */
	public function testRegisterRefusesAnUndeclaredActivityField(): void {
		$this->assertNotSame(
			[],
			RegisterSchemaValidator::errors(
				schemaSlug: 'dso_verzoek',
				object: [
					'verzoekId' => 'dso-map-5',
					'status' => 'mapped',
					'mappedActivities' => [['activityId' => 'Demo-0000-Bouwen', 'mapped' => true, 'zaaktypeIdentificatie' => 'X']],
				]
			)
		);
		$this->assertNotSame(
			[],
			RegisterSchemaValidator::errors(
				schemaSlug: 'dso_verzoek',
				object: ['verzoekId' => 'dso-map-6', 'status' => 'mapped', 'samenloopStrategy' => 'samen']
			)
		);

	}//end testRegisterRefusesAnUndeclaredActivityField()

	/**
	 * Per-verzoek isolation: a translation failure on one verzoek MUST NOT
	 * affect a second, valid verzoek ingested after it.
	 *
	 * @spec openspec/changes/dso-connector-adapter/specs/dso-connector-adapter/spec.md#requirement-dso_verzoek-lifecycle-with-per-verzoek-isolation-req-003
	 */
	public function testTranslationFailureIsolatesToOneVerzoek(): void {
		$service = $this->buildService();

		$first = $service->ingest(parsedRequest: ['type' => 'aanvraag']);
		$second = $service->ingest(parsedRequest: ['verzoekId' => 'dso-2', 'type' => 'melding']);

		$this->assertSame('failed', $first->getObject()['status']);
		$this->assertSame('mapped', $second->getObject()['status']);
		$this->assertNotSame($first->getUuid(), $second->getUuid());

	}//end testTranslationFailureIsolatesToOneVerzoek()

	/**
	 * @spec openspec/changes/dso-connector-adapter/specs/dso-connector-adapter/spec.md#requirement-rest-surface-to-list-and-complete-mapped-verzoeken-req-004
	 */
	public function testListVerzoekenFiltersByStatus(): void {
		$service = $this->buildService();

		$service->ingest(parsedRequest: ['verzoekId' => 'dso-1', 'type' => 'aanvraag']);
		$service->ingest(parsedRequest: ['type' => 'aanvraag']);

		$mapped = $service->listVerzoeken(status: 'mapped');
		$failed = $service->listVerzoeken(status: 'failed');

		$this->assertCount(1, $mapped);
		$this->assertCount(1, $failed);

	}//end testListVerzoekenFiltersByStatus()

	/**
	 * @spec openspec/changes/dso-connector-adapter/specs/dso-connector-adapter/spec.md#requirement-declared-ns-case-handoff-executed-by-a-real-authenticated-actor-req-005
	 */
	public function testHandoffSucceedsAndUpdatesVerzoek(): void {
		$service = $this->buildService();
		$request = $service->ingest(parsedRequest: ['verzoekId' => 'dso-1', 'type' => 'aanvraag']);
		$this->assertSame('mapped', $request->getObject()['status']);

		$this->handoffService->method('execute')->willReturn(
			[
				'status' => 'executed',
				'target' => ['register' => 'procest', 'schema' => 'case', 'uuid' => 'case-uuid-1'],
				'correlationId' => 'corr-1',
			]
		);

		$result = $service->handoff(uuid: $request->getUuid());

		$this->assertSame('executed', $result['status']);
		$stored = $this->requestStore[$request->getUuid()]->getObject();
		$this->assertSame('corr-1', $stored['correlationId']);
		$this->assertSame('case-uuid-1', $stored['targetCase']['uuid']);

	}//end testHandoffSucceedsAndUpdatesVerzoek()

	/**
	 * @spec openspec/changes/dso-connector-adapter/specs/dso-connector-adapter/spec.md#requirement-declared-ns-case-handoff-executed-by-a-real-authenticated-actor-req-005
	 */
	public function testHandoffRejectsWhenVerzoekNotYetMapped(): void {
		$service = $this->buildService();
		$this->requestStore['v-received'] = $this->buildEntity(['status' => 'received'], 'v-received');

		$this->expectException(DsoTranslationException::class);

		$service->handoff(uuid: 'v-received');

	}//end testHandoffRejectsWhenVerzoekNotYetMapped()

	/**
	 * @spec openspec/changes/dso-connector-adapter/specs/dso-connector-adapter/spec.md#requirement-declared-ns-case-handoff-executed-by-a-real-authenticated-actor-req-005
	 */
	public function testHandoffFailureMarksVerzoekFailedAndRethrows(): void {
		$service = $this->buildService();
		$request = $service->ingest(parsedRequest: ['verzoekId' => 'dso-1', 'type' => 'aanvraag']);

		$this->handoffService->method('execute')->willThrowException(
			new HandoffException(errorCode: HandoffException::PROVIDER_UNAVAILABLE, message: 'no provider')
		);

		$this->expectException(HandoffException::class);

		try {
			$service->handoff(uuid: $request->getUuid());
		} finally {
			$this->assertSame('failed', $this->requestStore[$request->getUuid()]->getObject()['status']);
		}

	}//end testHandoffFailureMarksVerzoekFailedAndRethrows()

	/**
	 * @spec openspec/changes/dso-connector-adapter/specs/dso-connector-adapter/spec.md#requirement-outbound-status-besluit-post-with-per-message-audit-req-006
	 */
	public function testPostOutboundThrowsWhenNotConfigured(): void {
		$service = $this->buildService();
		$request = $service->ingest(parsedRequest: ['verzoekId' => 'dso-1', 'type' => 'aanvraag']);

		$this->expectException(DsoProviderException::class);
		$this->expectExceptionMessageMatches('/No active DSO source/');

		$service->postOutbound(requestUuid: $request->getUuid(), type: 'status', fields: ['status' => 'in_behandeling']);

	}//end testPostOutboundThrowsWhenNotConfigured()

	/**
	 * @spec openspec/changes/dso-connector-adapter/specs/dso-connector-adapter/spec.md#requirement-outbound-status-besluit-post-with-per-message-audit-req-006
	 */
	public function testPostOutboundDispatchesViaLogProviderByDefault(): void {
		$this->addSourceFixture(configuration: []);
		$service = $this->buildService();
		$request = $service->ingest(parsedRequest: ['verzoekId' => 'dso-1', 'type' => 'aanvraag']);

		$result = $service->postOutbound(requestUuid: $request->getUuid(), type: 'status', fields: ['status' => 'in_behandeling']);

		$this->assertSame('sent', $result['status']);
		$this->assertStringStartsWith('MOCK-DSO-', $result['ref']);
		$this->assertCount(1, $this->messageStore);
		$this->assertSame('status', $this->messageStore[0]->getObject()['type']);
		$this->assertSame($request->getUuid(), $this->messageStore[0]->getObject()['verzoekUuid']);

	}//end testPostOutboundDispatchesViaLogProviderByDefault()

	/**
	 * @spec openspec/changes/dso-connector-adapter/specs/dso-connector-adapter/spec.md#requirement-outbound-status-besluit-post-with-per-message-audit-req-006
	 */
	public function testPostOutboundPersistsFailedMessageAndRethrowsOnProviderFailure(): void {
		$this->addSourceFixture(configuration: ['provider' => 'rest']);
		$service = $this->buildService();
		$request = $service->ingest(parsedRequest: ['verzoekId' => 'dso-1', 'type' => 'aanvraag']);

		$this->restProvider->method('send')->willThrowException(new DsoProviderException(message: 'transport down'));

		$this->expectException(DsoProviderException::class);

		try {
			$service->postOutbound(requestUuid: $request->getUuid(), type: 'besluit', fields: ['besluit' => 'verleend']);
		} finally {
			$this->assertCount(1, $this->messageStore);
			$this->assertSame('failed', $this->messageStore[0]->getObject()['status']);
			$this->assertSame('transport down', $this->messageStore[0]->getObject()['error']);
		}

	}//end testPostOutboundPersistsFailedMessageAndRethrowsOnProviderFailure()

	/**
	 * postOutbound() rejects an unrecognised message type before touching
	 * any source/provider.
	 *
	 * @return void
	 */
	public function testPostOutboundRejectsUnknownType(): void {
		$service = $this->buildService();
		$request = $service->ingest(parsedRequest: ['verzoekId' => 'dso-1', 'type' => 'aanvraag']);

		$this->expectException(DsoTranslationException::class);

		$service->postOutbound(requestUuid: $request->getUuid(), type: 'onbekend', fields: []);

	}//end testPostOutboundRejectsUnknownType()

	/**
	 * An identity for the DSO connection's account.
	 *
	 * @return DsoIdentity
	 */
	private function identity(): DsoIdentity {
		$account = $this->createMock(IUser::class);
		$account->method('getUID')->willReturn('dso-intake');

		return new DsoIdentity(account: $account, consumerUuid: 'consumer-dso');
	}//end identity()

	/**
	 * The job carries the uid the intake acted as, and the record says which
	 * connection delivered it; the register accepts receivedVia.
	 *
	 * @spec openspec/changes/dso-intake-through-an-integriq-connection/tasks.md#task-3
	 */
	public function testIngestHandsTheActingAccountToTheJobAndRecordsReceivedVia(): void {
		$service = $this->buildService();

		$stored = $service->ingest(
			parsedRequest: [
				'verzoekId' => 'dso-via-1',
				'type' => 'aanvraag',
				'bijlagen' => [['name' => 'tekening.pdf', 'url' => 'https://dso-lv.nl/docs/1']],
			],
			identity: $this->identity()
		);

		$this->assertSame(
			[[FetchDsoAttachmentsJob::class, ['requestUuid' => $stored->getUuid(), 'actingUserId' => 'dso-intake']]],
			$this->queued
		);
		$this->assertSame(['consumer' => 'consumer-dso', 'account' => 'dso-intake'], $stored->getObject()['receivedVia']);
		$this->assertSame(
			[],
			RegisterSchemaValidator::errors(
				schemaSlug: 'dso_verzoek',
				object: ['verzoekId' => 'dso-via-1', 'status' => 'mapped', 'receivedVia' => $stored->getObject()['receivedVia']]
			)
		);
		$this->assertNotSame(
			[],
			RegisterSchemaValidator::errors(
				schemaSlug: 'dso_verzoek',
				object: ['verzoekId' => 'dso-via-2', 'status' => 'mapped', 'receivedVia' => ['consumer' => 'c', 'user' => 'x']]
			),
			'receivedVia declares exactly consumer and account'
		);

	}//end testIngestHandsTheActingAccountToTheJobAndRecordsReceivedVia()

	/**
	 * A repeated delivery of a mapped or failed verzoek writes nothing and
	 * returns the stored record.
	 *
	 * @spec openspec/changes/dso-intake-through-an-integriq-connection/specs/dso-omgevingsloket/spec.md#scenario-a-repeated-delivery-creates-no-second-record
	 */
	public function testARepeatedDeliveryOfAMappedVerzoekWritesNothing(): void {
		$service = $this->buildService();
		$verzoek = [
			'verzoekId' => 'dso-123',
			'type' => 'aanvraag',
			'bijlagen' => [['name' => 'a.pdf', 'url' => 'https://dso-lv.nl/docs/a']],
		];

		$first = $service->ingest(parsedRequest: $verzoek, identity: $this->identity());
		$this->assertSame('mapped', $first->getObject()['status']);
		$storedBefore = $this->requestStore;
		$queuedBefore = $this->queued;

		$second = $service->ingest(parsedRequest: $verzoek, identity: $this->identity());

		$this->assertSame($first->getUuid(), $second->getUuid());
		$this->assertCount(1, $this->requestStore);
		$this->assertSame($storedBefore, $this->requestStore);
		$this->assertSame($queuedBefore, $this->queued, 'No second bijlage job');

	}//end testARepeatedDeliveryOfAMappedVerzoekWritesNothing()

	/**
	 * A verzoek left `received` (a crash after the first save) is finished on
	 * the next delivery, not created twice.
	 *
	 * @spec openspec/changes/dso-intake-through-an-integriq-connection/tasks.md#task-2
	 */
	public function testARepeatedDeliveryFinishesAReceivedVerzoek(): void {
		$service = $this->buildService();
		$this->requestStore['verzoek-crashed'] = $this->buildEntity(
			['verzoekId' => 'dso-crash', 'status' => 'received', 'attachments' => []],
			'verzoek-crashed'
		);

		$finished = $service->ingest(parsedRequest: ['verzoekId' => 'dso-crash', 'type' => 'aanvraag'], identity: $this->identity());

		$this->assertSame('verzoek-crashed', $finished->getUuid());
		$this->assertSame('mapped', $finished->getObject()['status']);
		$this->assertCount(1, $this->requestStore);

	}//end testARepeatedDeliveryFinishesAReceivedVerzoek()

	/**
	 * A different verzoekId is a new verzoek.
	 *
	 * @spec openspec/changes/dso-intake-through-an-integriq-connection/tasks.md#task-2
	 */
	public function testADifferentVerzoekIdIsCreated(): void {
		$service = $this->buildService();

		$service->ingest(parsedRequest: ['verzoekId' => 'dso-a', 'type' => 'aanvraag'], identity: $this->identity());
		$service->ingest(parsedRequest: ['verzoekId' => 'dso-b', 'type' => 'aanvraag'], identity: $this->identity());

		$this->assertCount(2, $this->requestStore);

	}//end testADifferentVerzoekIdIsCreated()
}//end class

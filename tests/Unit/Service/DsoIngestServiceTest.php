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
use OCA\Integriq\Service\Dso\DsoClient;
use OCA\Integriq\Service\Dso\DsoRequestTranslator;
use OCA\Integriq\Service\Dso\LogDsoConnectorProvider;
use OCA\Integriq\Service\DsoIngestService;
use OCA\Integriq\Service\Security\RawSourceResolver;
use OCA\Integriq\Tests\Helpers\RegisterSchemaValidator;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Exception\HandoffException;
use OCA\OpenRegister\Service\Handoff\HandoffService;
use OCA\OpenRegister\Service\ObjectService as ORObjectService;
use OCP\BackgroundJob\IJobList;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

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
			function (array $config = []) {
				$schema = ($config['filters']['schema'] ?? null);
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
			activityMapper: new DsoActivityMapper()
		);

	}//end buildService()

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
				'activiteiten' => [['code' => 'bouwen-01', 'omschrijving' => 'Bouwen van een woning']],
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
		$this->assertSame([[FetchDsoAttachmentsJob::class, ['requestUuid' => $with->getUuid()]]], $this->queued);
		$this->assertSame('pending', $with->getObject()['attachments'][0]['status']);

		$failed = $service->ingest(
			parsedRequest: ['type' => 'aanvraag', 'bijlagen' => [['name' => 'a.pdf', 'url' => 'https://dso-lv.nl/docs/2']]]
		);
		$this->assertSame('failed', $failed->getObject()['status']);
		$this->assertCount(2, $this->queued, 'A request whose mapping failed still gets its bijlagen.');

	}//end testIngestQueuesTheDownloadOnlyWhenThereAreBijlagen()

	/**
	 * Intake runs the activiteiten through DsoActivityMapper: each mapped
	 * activiteit carries its zaaktype, the request lists its zaaktypen, and the
	 * register accepts what intake writes.
	 *
	 * @spec openspec/specs/dso-omgevingsloket/spec.md#scenario-one-to-one-activiteit-mapping-creates-zaak
	 */
	public function testIngestMapsActiviteitenToCaseTypes(): void {
		$service = $this->buildService();

		$request = $service->ingest(
			parsedRequest: [
				'verzoekId' => 'dso-map-1',
				'type' => 'aanvraag',
				'activiteiten' => [
					['code' => 'bouwen-01', 'omschrijving' => 'Bouwen van een woning'],
					['code' => 'kappen-01', 'omschrijving' => 'Kappen van een boom'],
				],
			]
		);

		$data = $request->getObject();
		$this->assertSame(
			[
				['code' => 'bouwen-01', 'description' => 'Bouwen van een woning', 'mapped' => true, 'caseType' => 'ZAAKTYPE-BOUWEN-2024', 'samenloopStrategy' => 'deelzaken'],
				['code' => 'kappen-01', 'description' => 'Kappen van een boom', 'mapped' => true, 'caseType' => 'ZAAKTYPE-KAPPEN-2024', 'samenloopStrategy' => 'gecombineerd'],
			],
			$data['mappedActivities']
		);
		$this->assertSame(['ZAAKTYPE-BOUWEN-2024', 'ZAAKTYPE-KAPPEN-2024'], $data['mappedCaseTypes']);
		$this->assertSame('deelzaken', $data['samenloopStrategy']);
		$this->assertFalse($data['activityUnmapped']);
		$this->assertSame('mapped', $data['status']);
		$this->assertSame([], RegisterSchemaValidator::errors(schemaSlug: 'dso_verzoek', object: $this->mappingFields(data: $data)));

	}//end testIngestMapsActiviteitenToCaseTypes()

	/**
	 * Samenloop: when every mapped activiteit is `gecombineerd`, the request is
	 * `gecombineerd`; one zaaktype shared by two activiteiten is listed once.
	 *
	 * @spec openspec/specs/dso-omgevingsloket/spec.md#scenario-gecombineerd-strategy-creates-one-combined-zaak
	 */
	public function testIngestDecidesGecombineerdWhenEveryActiviteitCombines(): void {
		$service = $this->buildService();

		$request = $service->ingest(
			parsedRequest: [
				'verzoekId' => 'dso-map-2',
				'type' => 'aanvraag',
				'activiteiten' => [
					['code' => 'kappen-01', 'omschrijving' => 'Kappen'],
					['code' => 'uitrit-01', 'omschrijving' => 'Uitrit'],
					['code' => 'kappen-01', 'omschrijving' => 'Nog een boom'],
				],
			]
		);

		$data = $request->getObject();
		$this->assertSame('gecombineerd', $data['samenloopStrategy']);
		$this->assertSame(['ZAAKTYPE-KAPPEN-2024', 'ZAAKTYPE-UITRIT-2024'], $data['mappedCaseTypes']);

	}//end testIngestDecidesGecombineerdWhenEveryActiviteitCombines()

	/**
	 * An activiteit without a mapping is kept, marked unmapped, and flags the
	 * request for triage. The mapped one still gets its zaaktype.
	 *
	 * @spec openspec/specs/dso-omgevingsloket/spec.md#scenario-mixed-mapped-and-unmapped-activiteiten
	 */
	public function testIngestFlagsAnUnmappedActiviteitForTriage(): void {
		$service = $this->buildService();

		$request = $service->ingest(
			parsedRequest: [
				'verzoekId' => 'dso-map-3',
				'type' => 'aanvraag',
				'activiteiten' => [
					['code' => 'bouwen-01', 'omschrijving' => 'Bouwen'],
					['code' => 'experimenteel-gebruik-2025', 'omschrijving' => null],
					['code' => null, 'omschrijving' => 'Zonder code'],
				],
			]
		);

		$data = $request->getObject();
		$this->assertSame(
			[
				['code' => 'bouwen-01', 'description' => 'Bouwen', 'mapped' => true, 'caseType' => 'ZAAKTYPE-BOUWEN-2024', 'samenloopStrategy' => 'deelzaken'],
				['code' => 'experimenteel-gebruik-2025', 'description' => '', 'mapped' => false],
				['code' => '', 'description' => 'Zonder code', 'mapped' => false],
			],
			$data['mappedActivities']
		);
		$this->assertSame(['ZAAKTYPE-BOUWEN-2024'], $data['mappedCaseTypes']);
		$this->assertTrue($data['activityUnmapped']);
		$this->assertSame([], RegisterSchemaValidator::errors(schemaSlug: 'dso_verzoek', object: $this->mappingFields(data: $data)));

	}//end testIngestFlagsAnUnmappedActiviteitForTriage()

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
					'mappedActivities' => [['code' => 'bouwen-01', 'mapped' => true, 'zaaktypeIdentificatie' => 'X']],
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
}//end class

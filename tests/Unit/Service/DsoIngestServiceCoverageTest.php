<?php

/**
 * Unit tests for DsoIngestService: redelivery matching, bijlage edge cases,
 * the single-verzoek read and the engine read of the active DSO source.
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

use OCA\Integriq\BackgroundJob\AttachmentFetchSchedulerInterface;
use OCA\Integriq\Exception\DsoProviderException;
use OCA\Integriq\Exception\DsoTranslationException;
use OCA\Integriq\Service\Dso\DsoActivityMapper;
use OCA\Integriq\Service\Dso\DsoClient;
use OCA\Integriq\Service\Dso\DsoRequestTranslator;
use OCA\Integriq\Service\Dso\LogDsoConnectorProvider;
use OCA\Integriq\Service\DsoIngestService;
use OCA\Integriq\Service\Security\RawSourceResolver;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService as ORObjectService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Covers the redelivery match, bijlage edge cases, getRequest() and
 * resolveActiveSourceAsEngine().
 *
 * @covers \OCA\Integriq\Service\DsoIngestService
 * @uses \OCA\Integriq\Exception\DsoProviderException
 * @uses \OCA\Integriq\Exception\DsoTranslationException
 *
 * @spec openspec/changes/dso-connector-adapter/specs/dso-connector-adapter/spec.md
 */
class DsoIngestServiceCoverageTest extends TestCase {

	/**
	 * @var ORObjectService&MockObject
	 */
	private ORObjectService&MockObject $objectService;

	/**
	 * @var DsoRequestTranslator&MockObject
	 */
	private DsoRequestTranslator&MockObject $translator;

	/**
	 * @var LoggerInterface&MockObject
	 */
	private LoggerInterface&MockObject $logger;

	/**
	 * @var AttachmentFetchSchedulerInterface&MockObject
	 */
	private AttachmentFetchSchedulerInterface&MockObject $attachmentFetches;

	/**
	 * @var DsoActivityMapper&MockObject
	 */
	private DsoActivityMapper&MockObject $activityMapper;

	/**
	 * Every saveObject() call, as [object, schema, uuid].
	 *
	 * @var array<int, array{0: array<string, mixed>, 1: string|null, 2: string|null}>
	 */
	private array $saves = [];

	/**
	 * Set up the mocks.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->saves = [];
		$this->objectService = $this->createMock(ORObjectService::class);
		$this->translator = $this->createMock(DsoRequestTranslator::class);
		$this->logger = $this->createMock(LoggerInterface::class);
		$this->attachmentFetches = $this->createMock(AttachmentFetchSchedulerInterface::class);
		$this->activityMapper = $this->createMock(DsoActivityMapper::class);

	}//end setUp()

	/**
	 * Build the service over the mocks.
	 *
	 * @return DsoIngestService
	 */
	private function buildService(): DsoIngestService {
		return new DsoIngestService(
			objectService: $this->objectService,
			translator: $this->translator,
			logProvider: $this->createMock(LogDsoConnectorProvider::class),
			restProvider: $this->createMock(DsoClient::class),
			logger: $this->logger,
			rawSourceResolver: $this->createMock(RawSourceResolver::class),
			attachmentFetches: $this->attachmentFetches,
			activityMapper: $this->activityMapper
		);

	}//end buildService()

	/**
	 * An ObjectEntity with the given body and uuid.
	 *
	 * @param array<string, mixed> $data The body.
	 * @param string|null $uuid The uuid.
	 *
	 * @return ObjectEntity
	 */
	private function entity(array $data, ?string $uuid): ObjectEntity {
		$entity = new ObjectEntity();
		if ($uuid !== null) {
			$entity->setUuid($uuid);
		}

		$entity->setObject($data);

		return $entity;

	}//end entity()

	/**
	 * Make saveObject() record each call and echo the object back as an entity.
	 *
	 * @return void
	 */
	private function recordSaves(): void {
		$this->objectService->method('saveObject')->willReturnCallback(
			function ($object, ?string $register = null, ?string $schema = null, ?string $uuid = null): ObjectEntity {
				$this->saves[] = [(array)$object, $schema, $uuid];
				return $this->entity((array)$object, ($uuid ?? 'verzoek-new'));
			}
		);

	}//end recordSaves()

	/**
	 * Make the translator and the activity mapper answer a plain mapping.
	 *
	 * @return void
	 */
	private function translatesPlainly(): void {
		$this->translator->method('translate')->willReturn(
			[
				'mappedTitle' => 'Bouwen',
				'mappedSummary' => 'Bouwen van een woning',
				'mappedChannel' => 'dso',
				'mappedPriority' => 'normal',
				'requester' => [],
			]
		);
		$this->activityMapper->method('mapRequest')->willReturn([]);

	}//end translatesPlainly()

	/**
	 * Stored rows that are no ObjectEntity, or that carry another volgnummer,
	 * are not the delivery: a new verzoek is created.
	 *
	 * @return void
	 */
	public function testARedeliveryWithAnotherVolgnummerIsANewVerzoek(): void {
		$this->objectService->expects($this->once())
			->method('findAll')
			->with(['filters' => ['register' => 'integriq', 'schema' => 'dso_verzoek', 'verzoekId' => 'dso-7'], 'limit' => 10])
			->willReturn(
				[
					'results' => [
						['verzoekId' => 'dso-7'],
						$this->entity(['verzoekId' => 'dso-7', 'status' => 'mapped', 'rawRequest' => ['volgnummer' => 1]], 'verzoek-old'),
					],
				]
			);
		$this->recordSaves();
		$this->translatesPlainly();

		$request = $this->buildService()->ingest(parsedRequest: ['verzoekId' => 'dso-7', 'volgnummer' => 2, 'type' => 'aanvraag']);

		$this->assertSame('verzoek-new', $request->getUuid());
		$this->assertSame('mapped', $request->getObject()['status']);
		$this->assertCount(2, $this->saves);
		$this->assertNull($this->saves[0][2], 'The first save creates a record');
		$this->assertSame('received', $this->saves[0][0]['status']);
		$this->assertSame(2, $this->saves[0][0]['rawRequest']['volgnummer']);
		$this->assertSame('verzoek-new', $this->saves[1][2]);

	}//end testARedeliveryWithAnotherVolgnummerIsANewVerzoek()

	/**
	 * A redelivery with the same volgnummer is matched and writes nothing.
	 *
	 * @return void
	 */
	public function testARedeliveryWithTheSameVolgnummerIsMatched(): void {
		$stored = $this->entity(['verzoekId' => 'dso-7', 'status' => 'mapped', 'rawRequest' => ['volgnummer' => '2']], 'verzoek-old');
		$this->objectService->method('findAll')->willReturn([$stored]);
		$this->objectService->expects($this->never())->method('saveObject');

		$request = $this->buildService()->ingest(parsedRequest: ['verzoekId' => 'dso-7', 'volgnummer' => 2]);

		$this->assertSame($stored, $request);

	}//end testARedeliveryWithTheSameVolgnummerIsMatched()

	/**
	 * A bijlage reference that is no array is skipped, and a scheduler failure
	 * is logged without failing the intake.
	 *
	 * @return void
	 */
	public function testAFailedBijlageQueueIsLoggedAndTheRequestStillReturned(): void {
		$this->objectService->method('findAll')->willReturn(['results' => []]);
		$this->recordSaves();
		$this->translatesPlainly();
		$this->attachmentFetches->expects($this->once())
			->method('scheduleFetch')
			->with('verzoek-new', '')
			->willThrowException(new RuntimeException('job list down'));
		$this->logger->expects($this->once())
			->method('warning')
			->with(
				'[DsoIngestService] could not queue the bijlage download for verzoek verzoek-new',
				['exception' => 'job list down']
			);

		$request = $this->buildService()->ingest(
			parsedRequest: [
				'verzoekId' => 'dso-8',
				'bijlagen' => ['not-a-reference', ['name' => 'a.pdf', 'url' => 'https://dso-lv.nl/docs/a']],
			]
		);

		$this->assertSame('verzoek-new', $request->getUuid());
		$this->assertSame(
			[['name' => 'a.pdf', 'url' => 'https://dso-lv.nl/docs/a', 'status' => 'pending', 'attempts' => 0]],
			$request->getObject()['attachments']
		);

	}//end testAFailedBijlageQueueIsLoggedAndTheRequestStillReturned()

	/**
	 * A `bijlagen` value that is no list yields no attachments and no job.
	 *
	 * @return void
	 */
	public function testANonListBijlagenYieldsNoAttachments(): void {
		$this->objectService->method('findAll')->willReturn(['results' => []]);
		$this->recordSaves();
		$this->translatesPlainly();
		$this->attachmentFetches->expects($this->never())->method('scheduleFetch');

		$request = $this->buildService()->ingest(parsedRequest: ['verzoekId' => 'dso-9', 'bijlagen' => 'tekening.pdf']);

		$this->assertSame([], $request->getObject()['attachments']);
		$this->assertSame([], $this->saves[0][0]['attachments']);

	}//end testANonListBijlagenYieldsNoAttachments()

	/**
	 * getRequest() answers the stored body plus its id.
	 *
	 * @return void
	 */
	public function testGetRequestReturnsTheBodyWithItsId(): void {
		$this->objectService->expects($this->once())
			->method('find')
			->with('verzoek-1', 'integriq', 'dso_verzoek')
			->willReturn($this->entity(['verzoekId' => 'dso-1', 'status' => 'mapped'], 'verzoek-1'));

		$this->assertSame(
			['verzoekId' => 'dso-1', 'status' => 'mapped', 'id' => 'verzoek-1'],
			$this->buildService()->getRequest(uuid: 'verzoek-1')
		);

	}//end testGetRequestReturnsTheBodyWithItsId()

	/**
	 * getRequest() refuses an unknown uuid.
	 *
	 * @return void
	 */
	public function testGetRequestRefusesAnUnknownUuid(): void {
		$this->objectService->method('find')->willReturn(null);

		$this->expectException(DsoTranslationException::class);
		$this->expectExceptionMessage('No dso_verzoek found for uuid "missing".');

		$this->buildService()->getRequest(uuid: 'missing');

	}//end testGetRequestRefusesAnUnknownUuid()

	/**
	 * postOutbound() refuses an unknown verzoek before resolving a source.
	 *
	 * @return void
	 */
	public function testPostOutboundRefusesAnUnknownVerzoek(): void {
		$this->objectService->method('find')->willReturn(null);
		$this->objectService->expects($this->never())->method('findAll');
		$this->objectService->expects($this->never())->method('saveObject');

		$this->expectException(DsoTranslationException::class);
		$this->expectExceptionMessage('No dso_verzoek found for uuid "missing".');

		$this->buildService()->postOutbound(requestUuid: 'missing', type: 'status');

	}//end testPostOutboundRefusesAnUnknownVerzoek()

	/**
	 * The engine read refuses when no active DSO source exists.
	 *
	 * @return void
	 */
	public function testTheEngineReadRefusesWithoutAnActiveSource(): void {
		$this->objectService->expects($this->once())
			->method('findAll')
			->with(
				[
					'filters' => ['register' => 'integriq', 'schema' => 'source', 'type' => 'dso', 'isEnabled' => true],
					'limit' => 1,
				],
				false,
				false
			)
			->willReturn(['results' => []]);

		$this->expectException(DsoProviderException::class);
		$this->expectExceptionMessage('No active DSO source is configured');

		$this->buildService()->resolveActiveSourceAsEngine();

	}//end testTheEngineReadRefusesWithoutAnActiveSource()

	/**
	 * A located source without a uuid is answered as found, without a re-read.
	 *
	 * @return void
	 */
	public function testTheEngineReadReturnsAUuidLessSourceAsFound(): void {
		$located = $this->entity(['type' => 'dso'], null);
		$this->objectService->method('findAll')->willReturn([$located]);
		$this->objectService->expects($this->never())->method('find');

		$this->assertSame($located, $this->buildService()->resolveActiveSourceAsEngine());

	}//end testTheEngineReadReturnsAUuidLessSourceAsFound()

	/**
	 * The located source is re-read raw, as the engine.
	 *
	 * @return void
	 */
	public function testTheEngineReadReReadsTheSourceRaw(): void {
		$located = $this->entity(['type' => 'dso', 'configuration' => ['token' => '***']], 'source-dso');
		$raw = $this->entity(['type' => 'dso', 'configuration' => ['token' => 'secret']], 'source-dso');
		$this->objectService->method('findAll')->willReturn(['results' => [$located]]);
		$this->objectService->expects($this->once())
			->method('find')
			->with('source-dso', 'integriq', 'source', false, false, false)
			->willReturn($raw);

		$this->assertSame($raw, $this->buildService()->resolveActiveSourceAsEngine());

	}//end testTheEngineReadReReadsTheSourceRaw()

	/**
	 * When the raw re-read finds nothing, the located source is answered.
	 *
	 * @return void
	 */
	public function testTheEngineReadFallsBackToTheLocatedSource(): void {
		$located = $this->entity(['type' => 'dso'], 'source-dso');
		$this->objectService->method('findAll')->willReturn(['results' => [$located]]);
		$this->objectService->expects($this->once())->method('find')->willReturn(null);

		$this->assertSame($located, $this->buildService()->resolveActiveSourceAsEngine());

	}//end testTheEngineReadFallsBackToTheLocatedSource()
}//end class

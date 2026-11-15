<?php

/**
 * Unit tests for the Edurep source over the recorded SRU responses.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Adapters\LearningMaterial
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 *
 * @spec openspec/specs/edurep-source/spec.md#requirement-recorded-fixtures-no-live-calls-in-tests
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Adapters\LearningMaterial;

use OCA\Integriq\Adapters\LearningMaterial\EdurepFixtureTransport;
use OCA\Integriq\Adapters\LearningMaterial\EdurepSource;
use OCA\Integriq\Adapters\LearningMaterial\EdurepSourceMock;
use OCA\Integriq\Adapters\LearningMaterial\MaterialRecord;
use OCA\Integriq\Adapters\LearningMaterial\SourceUnavailableException;
use OCA\Integriq\Adapters\LearningMaterial\SruTransport;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * The recorded Edurep answers normalise as the spec's scenarios say, with no HTTP call.
 */
class EdurepSourceMockTest extends TestCase {
	/**
	 * @param LoggerInterface|null $logger The logger.
	 *
	 * @return EdurepSourceMock
	 */
	private function source(?LoggerInterface $logger = null): EdurepSourceMock {
		return new EdurepSourceMock(logger: ($logger ?? $this->createMock(LoggerInterface::class)));
	}//end source()

	/**
	 * @param list<MaterialRecord> $records The records.
	 * @param string $sourceId The identifier.
	 *
	 * @return MaterialRecord
	 */
	private function bySourceId(array $records, string $sourceId): MaterialRecord {
		foreach ($records as $record) {
			if ($record->sourceId === $sourceId) {
				return $record;
			}
		}

		$this->fail('No record ' . $sourceId);
	}//end bySourceId()

	/**
	 * @return void
	 */
	public function testFacetCountsComeBackWithTheResults(): void {
		$result = $this->source()->search(query: 'betoog', filters: [], page: 1);

		$this->assertSame(242, $result->total);
		$this->assertSame(1, $result->page);
		$this->assertSame(10, $result->pageSize);
		$this->assertCount(10, $result->records);
		$collections = array_column($result->facets['meta.repository.id'], 'count', 'value');
		$this->assertSame(['artik' => 86, 'metaplus' => 43, 'wikiwijsmaken' => 35, 'KlasCement' => 32, 'WikiwijsDelen' => 21], array_slice($collections, 0, 5, true));
		$this->assertSame(2, $collections['Schooltv']);
		$this->assertSame(2, $collections['surfsharekit']);
		$licences = array_column($result->facets['lom.rights.copyrightandotherrestrictions'], 'count', 'value');
		$this->assertSame(['yes' => 138, 'cc-by-40' => 36, 'cc-by-30' => 30, 'cc-by-sa-40' => 25, 'cc-by-nc-nd-30' => 9], array_slice($licences, 0, 5, true));
	}//end testFacetCountsComeBackWithTheResults()

	/**
	 * The recorded source, writing every request it sends to the given list.
	 *
	 * @param array<int,array<string,string>> $requests The requests sent, filled by the source.
	 *
	 * @return EdurepSource
	 */
	private function recordingSource(array &$requests): EdurepSource {
		$transport = new class($requests) implements SruTransport {
			/**
			 * @var array<int,array<string,string>>
			 */
			private array $requests;

			/**
			 * @param array<int,array<string,string>> $requests The requests sent.
			 */
			public function __construct(array &$requests) {
				$this->requests = &$requests;
			}//end __construct()

			/**
			 * @param array<string,string> $parameters The SRU parameters.
			 *
			 * @return string
			 */
			public function fetch(array $parameters): string {
				$this->requests[] = $parameters;

				return (new EdurepFixtureTransport())->fetch(parameters: $parameters);
			}//end fetch()

			/**
			 * @return string|null
			 */
			public function lastSuccess(): ?string {
				return null;
			}//end lastSuccess()
		};

		return new EdurepSource(transport: $transport, logger: $this->createMock(LoggerInterface::class));
	}//end recordingSource()

	/**
	 * @return void
	 */
	public function testASearchWithoutFiltersIsOneCall(): void {
		$requests = [];
		$this->recordingSource(requests: $requests)->search(query: 'betoog', filters: [], page: 1);

		$this->assertCount(1, $requests);
	}//end testASearchWithoutFiltersIsOneCall()

	/**
	 * The live pass: with VO on the other levels stayed, and KlasCement off still had a row.
	 *
	 * @return void
	 */
	public function testAFilteredGroupCountsWithoutItsOwnFilter(): void {
		$requests = [];
		$source = $this->recordingSource(requests: $requests);
		$result = $source->search(query: 'betoog', filters: ['levels' => ['VO'], 'excludeCollections' => ['KlasCement']], page: 1);

		$this->assertSame(13, $result->total);
		$this->assertCount(10, $result->records);
		$levels = array_column($result->facets['lom.educational.context'], 'count', 'value');
		$this->assertSame(['WO' => 15, 'HO' => 14, 'VO' => 13, 'HBO' => 1], $levels);
		$collections = array_column($result->facets['meta.repository.id'], 'count', 'value');
		$this->assertSame(25, $collections['KlasCement']);
		$licences = array_column($result->facets['lom.rights.copyrightandotherrestrictions'], 'count', 'value');
		$this->assertSame(['cc-by-nc-nd-30' => 9, 'yes' => 4], $licences);

		$this->assertCount(3, $requests);
		$this->assertSame('lom.rights.copyrightandotherrestrictions:20,lom.technical.format:12', $requests[0]['x-term-drilldown']);
		$this->assertSame(['10', '0', '0'], array_column($requests, 'maximumRecords'));
		$this->assertSame('meta.repository.id:20', $requests[1]['x-term-drilldown']);
		$this->assertSame('lom.educational.context:12', $requests[2]['x-term-drilldown']);
		$this->assertStringNotContainsString('KlasCement', $requests[1]['query']);
		$this->assertStringContainsString('lom.educational.context=VO', $requests[1]['query']);
		$this->assertStringNotContainsString('lom.educational.context', $requests[2]['query']);
		$this->assertStringContainsString('KlasCement', $requests[2]['query']);
	}//end testAFilteredGroupCountsWithoutItsOwnFilter()

	/**
	 * The live pass: unticking one licence took 210 results to 93.
	 *
	 * @return void
	 */
	public function testUntickingOneLicenceLeavesOutOnlyThatLicence(): void {
		$requests = [];
		$source = $this->recordingSource(requests: $requests);
		$result = $source->search(query: 'betoog', filters: ['excludeLicenses' => ['CC-BY-NC-ND-3.0'], 'excludeCollections' => ['KlasCement']], page: 1);

		$this->assertSame(201, $result->total);
		$licences = array_column($result->facets['lom.rights.copyrightandotherrestrictions'], 'count', 'value');
		$this->assertSame(9, $licences['cc-by-nc-nd-30']);
		$collections = array_column($result->facets['meta.repository.id'], 'count', 'value');
		$this->assertSame(32, $collections['KlasCement']);
		$this->assertCount(3, $requests);
	}//end testUntickingOneLicenceLeavesOutOnlyThatLicence()

	/**
	 * Turning a page keeps the filters, so its counts have not changed.
	 *
	 * @return void
	 */
	public function testAPageWithoutFacetsIsOneCallWithoutADrilldown(): void {
		$requests = [];
		$source = $this->recordingSource(requests: $requests);
		$result = $source->search(query: 'betoog', filters: ['excludeCollections' => ['KlasCement'], 'facets' => false], page: 2);

		$this->assertCount(1, $requests);
		$this->assertArrayNotHasKey('x-term-drilldown', $requests[0]);
		$this->assertSame('11', $requests[0]['startRecord']);
		$this->assertSame(210, $result->total);
	}//end testAPageWithoutFacetsIsOneCallWithoutADrilldown()

	/**
	 * @return void
	 */
	public function testAFailedDrilldownCallIsASourceFailure(): void {
		$recorded = new EdurepFixtureTransport();
		$transport = $this->createMock(SruTransport::class);
		$transport->expects($this->exactly(2))
			->method('fetch')
			->willReturnCallback(static function (array $parameters) use ($recorded): string {
				if ($parameters['maximumRecords'] === '0') {
					throw new SourceUnavailableException(source: 'edurep', lastSuccess: null);
				}

				return $recorded->fetch(parameters: $parameters);
			});

		$this->expectException(SourceUnavailableException::class);
		(new EdurepSource(transport: $transport, logger: $this->createMock(LoggerInterface::class)))
			->search(query: 'betoog', filters: ['levels' => ['VO']], page: 1);
	}//end testAFailedDrilldownCallIsASourceFailure()

	/**
	 * @return void
	 */
	public function testAWikiwijsRecordIsNormalised(): void {
		$record = $this->source()->search(query: 'betoog', filters: ['collection' => ['wikiwijsmaken']], page: 1)->records[0];

		$this->assertSame('urn:wikiwijsmaken:194413', $record->sourceId);
		$this->assertSame('Het schrijven van een betoog', $record->title);
		$this->assertSame('https://maken.wikiwijs.nl/194413/Het_schrijven_van_een_betoog', $record->url);
		$this->assertSame('CC-BY-4.0', $record->license->value);
		$this->assertSame('https://creativecommons.org/licenses/by/4.0/', $record->licenseUrl);
		$this->assertSame(['Kirstin Beekman'], $record->authors);
		$this->assertSame('application/x-Wikiwijs-Arrangement', $record->materialType);
		$this->assertSame('wikiwijsmaken', $record->collection);
		$this->assertSame('2023-01-29', $record->published);
		$this->assertSame('OpenAccess', $record->accessRights);
		$this->assertSame(['BVE'], $record->levels);
		$this->assertContains('Nederlands', $record->disciplines);
		$this->assertSame([], $record->files);
		$this->assertNull($record->embedUrl);
		$this->assertSame('edurep', $record->source);
	}//end testAWikiwijsRecordIsNormalised()

	/**
	 * @return void
	 */
	public function testARecordCarriesExactlyTheSpecFields(): void {
		$fields = array_keys($this->source()->find(id: 'urn:wikiwijsmaken:194413')?->toArray() ?? []);
		sort($fields);

		$this->assertSame(
			['accessRights', 'authors', 'collection', 'description', 'disciplines', 'embedUrl', 'files', 'language', 'levels', 'license', 'licenseUrl',
				'materialType', 'published', 'source', 'sourceId', 'title', 'url'],
			$fields
		);
	}//end testARecordCarriesExactlyTheSpecFields()

	/**
	 * @return void
	 */
	public function testRightsYesTakesTheLicenceFromTheUrlInTheDescription(): void {
		$records = $this->source()->search(query: 'betoog', filters: [], page: 1)->records;

		$krant = $this->bySourceId(records: $records, sourceId: $records[0]->sourceId);
		$this->assertSame('krantindeklas', $krant->collection);
		$this->assertSame('CC-BY-NC-ND-3.0', $krant->license->value);
		$this->assertSame('http://creativecommons.org/licenses/by-nc-nd/3.0/nl/', $krant->licenseUrl);

		$signaalwoorden = $this->source()->search(query: 'signaalwoorden', filters: [], page: 1)->records;
		$delen = $this->bySourceId(records: $signaalwoorden, sourceId: 'urn:uuid:9a568d3d-519d-4f0f-bfde-623d9c5b0f98');
		$this->assertSame('unknown', $delen->license->value);
		$this->assertNull($delen->licenseUrl);
	}//end testRightsYesTakesTheLicenceFromTheUrlInTheDescription()

	/**
	 * @return void
	 */
	public function testASurfsharekitFileCarriesItsFormatAndAccessRights(): void {
		$records = $this->source()->search(query: 'betoog', filters: ['collection' => ['surfsharekit']], page: 1)->records;
		$record = $this->bySourceId(records: $records, sourceId: 'urn:uuid:65fc6ffd-be4c-463d-9311-c201479a0c95');

		$this->assertSame('CC-BY-NC-4.0', $record->license->value);
		$this->assertSame(['HBO'], $record->levels);
		$this->assertSame(['J. Roenhorst'], $record->authors);
		$this->assertSame('2019-08-20', $record->published);
		$this->assertSame(
			[['url' => 'https://surfsharekit.nl/objectstore/a44ef2be-701c-45c0-9531-95dac8b2ee6f', 'format' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'accessRights' => 'OpenAccess']],
			$record->files
		);
	}//end testASurfsharekitFileCarriesItsFormatAndAccessRights()

	/**
	 * @return void
	 */
	public function testASchooltvRecordIsFoundById(): void {
		$record = $this->source()->find(id: '/NTR/POMS/WO_NTR_20306689');

		$this->assertNotNull($record);
		$this->assertSame('/NTR/POMS/WO_NTR_20306689', $record->sourceId);
		$this->assertSame('Schooltv', $record->collection);
		$this->assertSame('rights-reserved', $record->license->value);
		$this->assertSame('video/H264', $record->materialType);
		$this->assertStringStartsWith('https://schooltv.nl/', $record->url);
		$this->assertSame(['Schooltv'], $record->authors);
		$this->assertNull($record->embedUrl);
		$this->assertSame(['PO', 'VO'], $record->levels);
	}//end testASchooltvRecordIsFoundById()

	/**
	 * @return void
	 */
	public function testAWikiwijsRecordIsFoundByItsFullIdentifier(): void {
		$this->assertSame('Het schrijven van een betoog', $this->source()->find(id: 'urn:wikiwijsmaken:194413')?->title);
	}//end testAWikiwijsRecordIsFoundByItsFullIdentifier()

	/**
	 * @return void
	 */
	public function testAnUnknownIdFindsNothing(): void {
		$this->assertNull($this->source()->find(id: 'urn:none:0'));
		$this->assertNull($this->source()->find(id: ''));
	}//end testAnUnknownIdFindsNothing()

	/**
	 * @return void
	 */
	public function testAnEmptyResultHasNoRecords(): void {
		$result = $this->source()->search(query: 'xyzzyqwertyplugh', filters: [], page: 1);

		$this->assertSame(0, $result->total);
		$this->assertSame([], $result->records);
	}//end testAnEmptyResultHasNoRecords()

	/**
	 * @return void
	 */
	public function testAMalformedRecordIsSkippedAndLoggedWithItsPosition(): void {
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())
			->method('warning')
			->with($this->stringContains('skipped'), $this->callback(static fn (array $context): bool => $context['position'] === 1));

		$result = $this->source(logger: $logger)->search(query: 'kapot', filters: [], page: 1);

		$this->assertCount(1, $result->records);
		$this->assertSame(2, $result->total);
	}//end testAMalformedRecordIsSkippedAndLoggedWithItsPosition()

	/**
	 * @return void
	 */
	public function testAQueryWithoutARecordingFailsLoudly(): void {
		$this->expectException(SourceUnavailableException::class);

		$this->source()->search(query: 'not recorded', filters: [], page: 1);
	}//end testAQueryWithoutARecordingFailsLoudly()
}//end class

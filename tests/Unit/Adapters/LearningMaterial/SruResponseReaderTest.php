<?php

/**
 * Unit tests for reading an Edurep SRU response.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Adapters\LearningMaterial
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Adapters\LearningMaterial;

use OCA\Integriq\Adapters\LearningMaterial\LicenseCode;
use OCA\Integriq\Adapters\LearningMaterial\LomDocument;
use OCA\Integriq\Adapters\LearningMaterial\LomRecordReader;
use OCA\Integriq\Adapters\LearningMaterial\MaterialRecord;
use OCA\Integriq\Adapters\LearningMaterial\SafeXml;
use OCA\Integriq\Adapters\LearningMaterial\SearchResult;
use OCA\Integriq\Adapters\LearningMaterial\SruResponseReader;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Records, totals and drilldown facets from recorded responses.
 */
#[CoversClass(SruResponseReader::class)]
#[UsesClass(LicenseCode::class)]
#[UsesClass(LomDocument::class)]
#[UsesClass(LomRecordReader::class)]
#[UsesClass(MaterialRecord::class)]
#[UsesClass(SafeXml::class)]
#[UsesClass(SearchResult::class)]
class SruResponseReaderTest extends TestCase {
	/**
	 * @param string $name The recording's file name.
	 *
	 * @return string
	 */
	private function recording(string $name): string {
		return (string)file_get_contents(__DIR__ . '/../../../fixtures/edurep/' . $name);
	}//end recording()

	/**
	 * @return void
	 */
	public function testARecordedResponseReadsItsTotalRecordsAndFacets(): void {
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->never())->method('warning');
		$result = (new SruResponseReader(logger: $logger))->read(xml: $this->recording(name: 'betoog-nl-facets.xml'), paging: ['page' => 1, 'pageSize' => 10]);

		$this->assertNotNull($result);
		$this->assertSame(242, $result->total);
		$this->assertSame(1, $result->page);
		$this->assertSame(10, $result->pageSize);
		$this->assertCount(10, $result->records);
		// The record's own LOM identifier wins over the SRU record identifier.
		$this->assertSame('https://www.nieuwsindeklas.nl/lesmateriaal/confronterende-nieuwsfotos-betoog/', $result->records[0]->sourceId);
		$this->assertSame(
			['lom.rights.copyrightandotherrestrictions', 'meta.repository.id', 'lom.educational.context', 'lom.technical.format'],
			array_keys($result->facets)
		);
		$this->assertCount(12, $result->facets['meta.repository.id']);
		$this->assertSame(['value' => 'VO', 'count' => 38], $result->facets['lom.educational.context'][0]);
	}//end testARecordedResponseReadsItsTotalRecordsAndFacets()

	/**
	 * @return void
	 */
	public function testAnEmptyResponseIsAResultWithoutRecords(): void {
		$result = (new SruResponseReader(logger: $this->createMock(LoggerInterface::class)))->read(xml: $this->recording(name: 'empty.xml'), paging: ['page' => 2, 'pageSize' => 5]);

		$this->assertNotNull($result);
		$this->assertSame(0, $result->total);
		$this->assertSame([], $result->records);
		$this->assertSame(2, $result->page);
	}//end testAnEmptyResponseIsAResultWithoutRecords()

	/**
	 * @return void
	 */
	public function testAMalformedRecordIsSkippedAndLogged(): void {
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('warning')->with(
			'Integriq: skipped an Edurep record that is no LOM record',
			['position' => 1, 'recordIdentifier' => 'surfsharekit:oai:surfsharekit.nl:65fc6ffd-be4c-463d-9311-c201479a0c95']
		);
		$result = (new SruResponseReader(logger: $logger))->read(xml: $this->recording(name: 'malformed.xml'), paging: ['page' => 1, 'pageSize' => 10]);

		$this->assertNotNull($result);
		$this->assertSame(2, $result->total);
		$this->assertCount(1, $result->records);
		$this->assertSame('urn:uuid:eb45a1a9-6872-48db-b54b-97676fbf310b', $result->records[0]->sourceId);
	}//end testAMalformedRecordIsSkippedAndLogged()

	/**
	 * @return void
	 */
	public function testADiagnosticsResponseIsNoResult(): void {
		$xml = '<?xml version="1.0"?><srw:searchRetrieveResponse xmlns:srw="http://www.loc.gov/zing/srw/" xmlns:diag="http://www.loc.gov/zing/srw/diagnostic/">'
			. '<srw:numberOfRecords>0</srw:numberOfRecords><srw:diagnostics><diag:diagnostic><diag:message>Query syntax error</diag:message></diag:diagnostic></srw:diagnostics>'
			. '</srw:searchRetrieveResponse>';

		$this->assertNull((new SruResponseReader(logger: $this->createMock(LoggerInterface::class)))->read(xml: $xml, paging: ['page' => 1, 'pageSize' => 10]));
	}//end testADiagnosticsResponseIsNoResult()

	/**
	 * @return void
	 */
	public function testSomethingThatIsNoSruResponseIsNoResult(): void {
		$reader = new SruResponseReader(logger: $this->createMock(LoggerInterface::class));

		$this->assertNull($reader->read(xml: 'not xml at all', paging: ['page' => 1, 'pageSize' => 10]));
		$this->assertNull($reader->read(xml: '<?xml version="1.0"?><other xmlns="urn:x"/>', paging: ['page' => 1, 'pageSize' => 10]));
	}//end testSomethingThatIsNoSruResponseIsNoResult()
}//end class

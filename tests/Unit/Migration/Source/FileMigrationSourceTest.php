<?php

/**
 * Integriq — delivered-file migration source tests.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Migration\Source
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://www.integriq.nl
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Migration\Source;

use InvalidArgumentException;
use OCA\Integriq\Migration\ColumnMapping;
use OCA\Integriq\Migration\ColumnMappingValidator;
use OCA\Integriq\Migration\MigrationRecord;
use OCA\Integriq\Migration\Source\FileMigrationSource;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * REQ-MSA-002 and REQ-MSA-005.
 *
 * @spec openspec/changes/migration-source-adapters/specs/migration-sources/spec.md#requirement-a-file-is-read-through-a-stored-column-mapping-req-msa-002
 */
class FileMigrationSourceTest extends TestCase {
	/**
	 * The delivered file, twice in the same shape.
	 */
	private const DELIVERY = "zaaknummer,naam,plaats\nZ-1,De Vries,Utrecht\nZ-2,Jansen,Amsterdam\n";

	/**
	 * The second delivery, same columns, different rows.
	 */
	private const SECOND_DELIVERY = "zaaknummer,naam,plaats\nZ-3,Bakker,Veenendaal\n";

	/**
	 * A stored mapping, authored once.
	 *
	 * @return array<string,mixed> The stored mapping.
	 */
	private function storedMapping(): array {
		return [
			'name' => 'Zaken uit het oude systeem',
			'kind' => 'case',
			'version' => 2,
			'identifierColumn' => 'zaaknummer',
			'columns' => ['zaaknummer' => 'reference', 'naam' => 'requesterName', 'plaats' => 'city'],
		];
	}//end storedMapping()

	/**
	 * The adapter under test.
	 *
	 * @return FileMigrationSource The adapter.
	 */
	private function source(): FileMigrationSource {
		return new FileMigrationSource(
			$this->createMock(IRootFolder::class),
			new ColumnMappingValidator(),
			$this->createMock(LoggerInterface::class)
		);
	}//end source()

	/**
	 * An adapter whose root folder answers a FOLDER for every path.
	 *
	 * @return FileMigrationSource The adapter.
	 */
	private function sourceOverAFolder(): FileMigrationSource {
		$root = $this->createMock(IRootFolder::class);
		$root->method('get')->willReturn($this->createMock(Folder::class));

		return new FileMigrationSource(
			$root,
			new ColumnMappingValidator(),
			$this->createMock(LoggerInterface::class)
		);
	}//end sourceOverAFolder()

	/**
	 * 🔴 A path that names a folder is refused as a folder, not as unreadable.
	 *
	 * `IRootFolder::get()` answers a Node, and only a File can be read. The
	 * folder case used to fall through to `getContent()`, which Node does not
	 * declare, and the catch-all below turned that into "could not be read" —
	 * the sentence for a corrupt delivery. An operator who typed a directory
	 * path was sent looking at the file.
	 *
	 * @return void
	 */
	public function testAPathNamingAFolderIsRefusedAsAFolder(): void {
		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage('is a folder, not a file');

		$this->sourceOverAFolder()->read('case', ['path' => '/deliveries', 'mapping' => $this->storedMapping()]);
	}//end testAPathNamingAFolderIsRefusedAsAFolder()

	/**
	 * A second delivery of the same shape is read with the stored mapping,
	 * and no column is mapped again.
	 *
	 * @return void
	 */
	public function testASecondDeliveryReusesTheStoredMapping(): void {
		$source = $this->source();
		$mapping = $this->storedMapping();

		$first = $source->read('case', ['content' => self::DELIVERY, 'mapping' => $mapping]);
		$second = $source->read('case', ['content' => self::SECOND_DELIVERY, 'mapping' => $mapping]);

		$this->assertCount(2, $first);
		$this->assertCount(1, $second);
		$this->assertSame('Bakker', $second[0]->getData()['requesterName']);
		$this->assertSame($mapping, $this->storedMapping(), 'Reading a delivery does not rewrite the mapping.');
	}//end testASecondDeliveryReusesTheStoredMapping()

	/**
	 * Columns land on the target fields the mapping names, and nothing else
	 * comes along.
	 *
	 * @return void
	 */
	public function testColumnsLandOnTheNamedTargetFields(): void {
		$records = $this->source()->read('case', ['content' => self::DELIVERY, 'mapping' => $this->storedMapping()]);

		$data = $records[0]->getData();
		$this->assertSame(['reference', 'requesterName', 'city'], array_keys($data));
		$this->assertSame('Z-1', $data['reference']);
		$this->assertSame('Utrecht', $data['city']);
	}//end testColumnsLandOnTheNamedTargetFields()

	/**
	 * Every yielded record carries its foreign identity, so a second run
	 * matches rather than duplicating.
	 *
	 * @return void
	 */
	public function testEveryRecordCarriesItsForeignIdentity(): void {
		$source = $this->source();
		$config = ['content' => self::DELIVERY, 'mapping' => $this->storedMapping()];

		$first = $source->read('case', $config);
		$again = $source->read('case', $config);

		$this->assertSame('Z-1', $first[0]->getForeignId());
		$this->assertTrue($first[0]->isMatchable());
		$this->assertSame(
			$first[0]->getForeignId(),
			$again[0]->getForeignId(),
			'A second run of the same source has to match on the same pair.'
		);

		$shape = $first[0]->toArray();
		$this->assertSame('file', $shape['provenance']['provider']);
		$this->assertSame('Z-1', $shape['provenance']['sourceIdentifier']);
		$this->assertTrue($shape['match']['matchable']);
	}//end testEveryRecordCarriesItsForeignIdentity()

	/**
	 * A mapping with no identifier column yields records that say they cannot
	 * be matched, rather than inventing a key.
	 *
	 * @return void
	 */
	public function testAFileWithNoStableKeySaysSoRatherThanInventingOne(): void {
		$mapping = $this->storedMapping();
		$mapping['identifierColumn'] = '';

		$records = $this->source()->read('case', ['content' => self::DELIVERY, 'mapping' => $mapping]);

		$this->assertNull($records[0]->getForeignId());
		$this->assertFalse($records[0]->isMatchable());
	}//end testAFileWithNoStableKeySaysSoRatherThanInventingOne()

	/**
	 * A mapping onto a field the schema does not have is refused, naming the
	 * field.
	 *
	 * @return void
	 */
	public function testAMappingOntoAMissingFieldIsRefusedNamingTheField(): void {
		$mapping = $this->storedMapping();
		$mapping['columns']['plaats'] = 'woonplaats';

		$refusals = $this->source()->preflight(
			['content' => self::DELIVERY, 'mapping' => $mapping],
			['reference', 'requesterName', 'city'],
			[]
		);

		$this->assertCount(1, $refusals);
		$this->assertStringContainsString('woonplaats', $refusals[0]);
	}//end testAMappingOntoAMissingFieldIsRefusedNamingTheField()

	/**
	 * A required field left unmapped stops the run before a row is read, and
	 * names the field.
	 *
	 * @return void
	 */
	public function testARequiredFieldLeftUnmappedStopsTheRunBeforeItStarts(): void {
		$refusals = $this->source()->preflight(
			['content' => self::DELIVERY, 'mapping' => $this->storedMapping()],
			['reference', 'requesterName', 'city', 'caseType'],
			['reference', 'caseType']
		);

		$this->assertCount(1, $refusals);
		$this->assertStringContainsString('caseType', $refusals[0]);
		$this->assertStringContainsString('not mapped', $refusals[0]);
	}//end testARequiredFieldLeftUnmappedStopsTheRunBeforeItStarts()

	/**
	 * A mapped column the delivered file does not carry is refused too.
	 *
	 * @return void
	 */
	public function testAMappedColumnTheFileDoesNotCarryIsRefused(): void {
		$mapping = $this->storedMapping();
		$mapping['columns']['behandelaar'] = 'assignee';

		$refusals = $this->source()->preflight(
			['content' => self::DELIVERY, 'mapping' => $mapping],
			['reference', 'requesterName', 'city', 'assignee'],
			[]
		);

		$this->assertCount(1, $refusals);
		$this->assertStringContainsString('behandelaar', $refusals[0]);
	}//end testAMappedColumnTheFileDoesNotCarryIsRefused()

	/**
	 * A valid mapping against a schema that has every field refuses nothing.
	 *
	 * @return void
	 */
	public function testAValidMappingRefusesNothing(): void {
		$this->assertSame(
			[],
			$this->source()->preflight(
				['content' => self::DELIVERY, 'mapping' => $this->storedMapping()],
				['reference', 'requesterName', 'city'],
				['reference']
			)
		);
	}//end testAValidMappingRefusesNothing()

	/**
	 * The count of a delivered file is complete: a file is all there or it is
	 * not there at all.
	 *
	 * @return void
	 */
	public function testTheCountOfADeliveredFileIsComplete(): void {
		$counted = $this->source()->count('case', ['content' => self::DELIVERY, 'mapping' => $this->storedMapping()]);

		$this->assertSame(2, $counted['count']);
		$this->assertTrue($counted['complete']);
	}//end testTheCountOfADeliveredFileIsComplete()

	/**
	 * A sample is bounded by the limit it was asked for.
	 *
	 * @return void
	 */
	public function testASampleIsBounded(): void {
		$sample = $this->source()->sample('case', ['content' => self::DELIVERY, 'mapping' => $this->storedMapping()], 1);

		$this->assertCount(1, $sample);
		$this->assertInstanceOf(MigrationRecord::class, $sample[0]);
	}//end testASampleIsBounded()

	/**
	 * A mapping carrying no columns cannot read a file, and says so.
	 *
	 * @return void
	 */
	public function testAMappingWithNoColumnsIsRefused(): void {
		$this->expectException(\InvalidArgumentException::class);

		ColumnMapping::fromArray(['name' => 'leeg', 'columns' => []]);
	}//end testAMappingWithNoColumnsIsRefused()

	/**
	 * The record kind comes from the stored mapping, not from the kind the
	 * caller asked to read.
	 *
	 * Every other test in this file passes 'case' on both sides, so both
	 * readings produce the same answer and neither can be told apart. They
	 * differ here on purpose: reading the caller's kind instead of the
	 * mapping's would send every record to the wrong target.
	 *
	 * @return void
	 */
	public function testTheRecordKindComesFromTheMapping(): void {
		$mapping = $this->storedMapping();
		$mapping['kind'] = 'besluit';

		$records = $this->source()->read('case', ['content' => self::DELIVERY, 'mapping' => $mapping]);

		$this->assertSame('besluit', $records[0]->getKind());
	}//end testTheRecordKindComesFromTheMapping()

	/**
	 * A mapping that names no kind produces plain rows.
	 *
	 * @return void
	 */
	public function testAMappingWithoutAKindProducesRows(): void {
		$mapping = $this->storedMapping();
		$mapping['kind'] = '';

		$records = $this->source()->read('case', ['content' => self::DELIVERY, 'mapping' => $mapping]);

		$this->assertSame('row', $records[0]->getKind());
	}//end testAMappingWithoutAKindProducesRows()
}//end class

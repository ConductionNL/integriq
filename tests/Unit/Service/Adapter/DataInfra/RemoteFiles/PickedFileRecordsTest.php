<?php

/**
 * Unit tests for PickedFileRecords and MappingRecordStore.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Service\Adapter\DataInfra\RemoteFiles
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/sources-sftp-adapter/specs/data-infra-connectors/spec.md#requirement-new-files-are-picked-up-and-archived-after-a-safe-local-write-req-sftp-002
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Service\Adapter\DataInfra\RemoteFiles;

use OCA\Integriq\Service\Adapter\DataInfra\RemoteFiles\MappingRecordStore;
use OCA\Integriq\Service\Adapter\DataInfra\RemoteFiles\PickedFileRecords;
use OCA\Integriq\Service\Adapter\DataInfra\RemoteFiles\RemoteFileException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

/**
 * The records a pickup hands to the mapping, per file format.
 *
 * @spec openspec/changes/sources-sftp-adapter/specs/data-infra-connectors/spec.md#requirement-new-files-are-picked-up-and-archived-after-a-safe-local-write-req-sftp-002
 */
#[CoversClass(PickedFileRecords::class)]
#[CoversClass(MappingRecordStore::class)]
#[UsesClass(RemoteFileException::class)]
final class PickedFileRecordsTest extends TestCase {

	/**
	 * CSV: the header names the columns, a BOM and blank lines are dropped, quoted delimiters stay.
	 *
	 * @return void
	 */
	public function testCsvRowsBecomeRecordsByHeader(): void {
		$csv = "\xEF\xBB\xBFid,title,body\n1,\"Besluit, eerste\",raad\n\n2,Tweede,college\n";

		$records = PickedFileRecords::read(name: 'besluiten-1.csv', content: $csv, config: []);

		$this->assertSame(
			[
				['id' => '1', 'title' => 'Besluit, eerste', 'body' => 'raad'],
				['id' => '2', 'title' => 'Tweede', 'body' => 'college'],
			],
			$records
		);
	}//end testCsvRowsBecomeRecordsByHeader()

	/**
	 * CSV with a configured delimiter, including the written-out tab.
	 *
	 * @return void
	 */
	public function testCsvDelimiterIsConfigurable(): void {
		$this->assertSame(
			[['a' => '1', 'b' => '2']],
			PickedFileRecords::read(name: 'x.csv', content: "a;b\n1;2\n", config: ['csvDelimiter' => ';'])
		);
		$this->assertSame(
			[['a' => '1', 'b' => '2']],
			PickedFileRecords::read(name: 'x.txt', content: "a\tb\n1\t2\n", config: ['csvDelimiter' => '\t', 'fileFormat' => 'csv'])
		);
		$this->assertSame(
			[['a' => '1', 'b' => '2']],
			PickedFileRecords::read(name: 'x.tsv', content: "a\tb\n1\t2\n", config: ['csvDelimiter' => 'TAB', 'fileFormat' => 'csv'])
		);
	}//end testCsvDelimiterIsConfigurable()

	/**
	 * A CSV row with another column count than the header refuses the whole file, naming the line.
	 *
	 * @return void
	 */
	public function testCsvRowWithWrongColumnCountRefusesTheFile(): void {
		$this->expectException(RemoteFileException::class);
		$this->expectExceptionMessage('"x.csv" line 3 has 1 columns, the header has 2.');

		PickedFileRecords::read(name: 'x.csv', content: "a,b\n1,2\n3\n", config: []);
	}//end testCsvRowWithWrongColumnCountRefusesTheFile()

	/**
	 * JSON: a list gives one record per element, an object one record, lines one per line.
	 *
	 * @return void
	 */
	public function testJsonListObjectAndLines(): void {
		$this->assertSame(
			[['id' => 1], ['id' => 2]],
			PickedFileRecords::read(name: 'x.json', content: '[{"id":1},{"id":2}]', config: [])
		);
		$this->assertSame(
			[['id' => 1, 'tags' => ['a']]],
			PickedFileRecords::read(name: 'x.json', content: '{"id":1,"tags":["a"]}', config: [])
		);
		$this->assertSame(
			[['id' => 1], ['id' => 2]],
			PickedFileRecords::read(name: 'x.ndjson', content: "{\"id\":1}\n\n{\"id\":2}\n", config: [])
		);
	}//end testJsonListObjectAndLines()

	/**
	 * JSON that is broken, scalar, or a list of non-objects refuses the file.
	 *
	 * @return void
	 */
	public function testJsonThatIsNotObjectsIsRefused(): void {
		$cases = [
			'broken'  => ['{"id":', 'is not valid JSON'],
			'scalar'  => ['42', 'is not a JSON object or list'],
			'numbers' => ['[1,2]', 'record 1 is not an object'],
			'lists'   => ['[{"id":1},[1]]', 'record 2 is not an object'],
		];
		foreach ($cases as $case => [$json, $message]) {
			try {
				PickedFileRecords::read(name: 'x.json', content: $json, config: []);
				$this->fail($case . ' was read');
			} catch (RemoteFileException $exception) {
				$this->assertStringContainsString($message, $exception->getMessage(), $case);
			}
		}
	}//end testJsonThatIsNotObjectsIsRefused()

	/**
	 * XML: every child of the root is a record; a text-only child is a value.
	 *
	 * @return void
	 */
	public function testXmlChildrenOfTheRootAreRecords(): void {
		$xml = '<besluiten><besluit><id>1</id><titel>Eerste</titel></besluit><besluit><id>2</id><titel>Tweede</titel></besluit><opmerking>los</opmerking></besluiten>';

		$this->assertSame(
			[
				['id' => '1', 'titel' => 'Eerste'],
				['id' => '2', 'titel' => 'Tweede'],
				['value' => 'los'],
			],
			PickedFileRecords::read(name: 'x.xml', content: $xml, config: [])
		);
	}//end testXmlChildrenOfTheRootAreRecords()

	/**
	 * XML that declares a document type (and so could declare entities) is refused, as is broken XML.
	 *
	 * @return void
	 */
	public function testXmlWithADocumentTypeOrBrokenXmlIsRefused(): void {
		$xml = '<?xml version="1.0"?><!DOCTYPE r [<!ENTITY e SYSTEM "file:///etc/hostname">]><r><a>&e;</a></r>';
		try {
			PickedFileRecords::read(name: 'x.xml', content: $xml, config: []);
			$this->fail('an XML file with a document type was read');
		} catch (RemoteFileException $exception) {
			$this->assertSame('"x.xml" declares a document type, which a pickup does not read.', $exception->getMessage());
		}

		$this->expectException(RemoteFileException::class);
		$this->expectExceptionMessage('"y.xml" is not well-formed XML.');
		PickedFileRecords::read(name: 'y.xml', content: '<r><a></r>', config: []);
	}//end testXmlWithADocumentTypeOrBrokenXmlIsRefused()

	/**
	 * The format comes from the configuration first, else the extension; an unknown one is refused.
	 *
	 * @return void
	 */
	public function testFormatFromConfigurationOrExtension(): void {
		$this->assertSame('xml', PickedFileRecords::formatOf(name: 'a.XML', config: []));
		$this->assertSame('jsonl', PickedFileRecords::formatOf(name: 'a.jsonl', config: ['fileFormat' => 'auto']));
		$this->assertSame('json', PickedFileRecords::formatOf(name: 'a.dat', config: ['fileFormat' => 'JSON']));

		foreach ([['a.dat', []], ['a.csv', ['fileFormat' => 'xlsx']], ['a.csv', ['csvDelimiter' => ';;']]] as [$name, $config]) {
			try {
				PickedFileRecords::read(name: $name, content: "a\n1\n", config: $config);
				$this->fail($name . ' was read');
			} catch (RemoteFileException $exception) {
				$this->assertMatchesRegularExpression('/fileFormat|csvDelimiter/', $exception->getMessage());
			}
		}
	}//end testFormatFromConfigurationOrExtension()

	/**
	 * A file larger than the limit is not read.
	 *
	 * @return void
	 */
	public function testTooLargeFileIsRefused(): void {
		$this->expectException(RemoteFileException::class);
		$this->expectExceptionMessage('is larger than ' . PickedFileRecords::MAX_BYTES . ' bytes');

		PickedFileRecords::read(name: 'big.json', content: str_repeat(' ', (PickedFileRecords::MAX_BYTES + 1)), config: []);
	}//end testTooLargeFileIsRefused()

	/**
	 * The store gathers the records of every file in order and names where they went.
	 *
	 * @return void
	 */
	public function testStoreGathersRecordsAcrossFiles(): void {
		$store = new MappingRecordStore(config: ['csvDelimiter' => ';']);

		$this->assertSame('mapping:one.csv (2 records)', $store->store(folder: '', name: 'one.csv', stream: $this->stream("id;t\n1;a\n2;b\n")));
		$this->assertSame('mapping:two.json (1 records)', $store->store(folder: '', name: 'two.json', stream: $this->stream('{"id":"3"}')));

		$this->assertSame([['id' => '1', 't' => 'a'], ['id' => '2', 't' => 'b'], ['id' => '3']], $store->records());
	}//end testStoreGathersRecordsAcrossFiles()

	/**
	 * A file the store cannot read throws and adds nothing.
	 *
	 * @return void
	 */
	public function testStoreThrowsOnUnreadableFileAndKeepsNothing(): void {
		$store = new MappingRecordStore(config: []);
		try {
			$store->store(folder: '', name: 'bad.json', stream: $this->stream('[1]'));
			$this->fail('bad.json was stored');
		} catch (RemoteFileException $exception) {
			$this->assertStringContainsString('record 1 is not an object', $exception->getMessage());
		}

		$this->assertSame([], $store->records());
	}//end testStoreThrowsOnUnreadableFileAndKeepsNothing()

	/**
	 * A stream holding the given content, at its start.
	 *
	 * @param string $content The content.
	 *
	 * @return resource
	 */
	private function stream(string $content) {
		$stream = fopen('php://temp', 'w+b');
		fwrite($stream, $content);
		rewind($stream);
		return $stream;
	}//end stream()
}//end class

<?php

/**
 * Integriq — the vendored Logius Berichtenbox contract stays byte for byte.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Adapters\Berichtenbox
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

namespace OCA\Integriq\Tests\Unit\Adapters\Berichtenbox;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * REQ-DPA-013: every vendored file matches the sha256 its SOURCE.md records.
 *
 * @spec openspec/changes/berichtenbox-client/specs/digital-post-adapter/spec.md#requirement-tests-and-live-proofs-run-against-a-fake-built-from-the-official-files-req-dpa-013
 */
class VendoredContractTest extends TestCase {

	private const CONTRACT_DIR = __DIR__ . '/../../../../lib/Adapters/Berichtenbox/Logius';

	private const FIXTURE_DIR = __DIR__ . '/../../../fixtures/berichtenbox/logius';

	/**
	 * The sha256 per file, as SOURCE.md records it.
	 *
	 * @return array<string,string> Relative path => sha256.
	 */
	private function recorded(): array {
		$source = (string)file_get_contents(self::CONTRACT_DIR . '/SOURCE.md');
		preg_match_all('/^\| `([^`]+)` \|(?: [^|]* \|)? `([0-9a-f]{64})` \|$/m', $source, $matches, PREG_SET_ORDER);

		$recorded = [];
		foreach ($matches as $match) {
			$recorded[$match[1]] = $match[2];
		}

		return $recorded;
	}//end recorded()

	/**
	 * Every file in a directory, relative to it.
	 *
	 * @param string $dir The directory.
	 *
	 * @return array<int,string> Relative paths.
	 */
	private function filesIn(string $dir): array {
		$files = [];
		$iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS));
		foreach ($iterator as $file) {
			$relative = substr((string)$file->getPathname(), strlen($dir) + 1);
			if ($relative !== 'SOURCE.md') {
				$files[] = $relative;
			}
		}

		sort($files);

		return $files;
	}//end filesIn()

	/**
	 * Every vendored schema matches its recorded hash, and none is unrecorded.
	 *
	 * @return void
	 */
	public function testEveryVendoredSchemaMatchesItsRecordedHash(): void {
		$recorded = $this->recorded();
		$files = $this->filesIn(dir: self::CONTRACT_DIR);

		$this->assertNotEmpty($files);
		foreach ($files as $file) {
			$this->assertArrayHasKey($file, $recorded, $file . ' is vendored but SOURCE.md does not record it.');
			$this->assertSame(
				$recorded[$file],
				hash_file('sha256', self::CONTRACT_DIR . '/' . $file),
				$file . ' changed without SOURCE.md changing. The Logius contract is copied byte for byte.'
			);
		}
	}//end testEveryVendoredSchemaMatchesItsRecordedHash()

	/**
	 * The example messages and the original zips match too.
	 *
	 * @return void
	 */
	public function testEveryFixtureMatchesItsRecordedHash(): void {
		$recorded = $this->recorded();
		$files = $this->filesIn(dir: self::FIXTURE_DIR);

		$this->assertContains('logius-mijnoverheid-berichtenbox-xsd-2026.zip', $files);
		foreach ($files as $file) {
			$this->assertArrayHasKey($file, $recorded, $file . ' is a fixture but SOURCE.md does not record it.');
			$this->assertSame($recorded[$file], hash_file('sha256', self::FIXTURE_DIR . '/' . $file), $file . ' changed.');
		}
	}//end testEveryFixtureMatchesItsRecordedHash()

	/**
	 * The import copy is identical to the file Logius ships.
	 *
	 * @return void
	 */
	public function testTheImportCopyIsIdenticalToTheShippedTypesFile(): void {
		$dir = self::CONTRACT_DIR . '/BerichtVerwerkService/Request/';

		$this->assertFileEquals($dir . 'GLOBEBatchRequestTypes_128ch.xsd', $dir . 'GLOBEBatchRequestTypes.xsd');
	}//end testTheImportCopyIsIdenticalToTheShippedTypesFile()

	/**
	 * The Logius example letter validates against the vendored schema, so the schema loads at all.
	 *
	 * The example ships placeholders (`batchID`, `berichtId1`), and its `BerichtType` value
	 * `Berichttype` has 11 characters where the same package's XSD allows 8. So it fails, on exactly
	 * those three elements and on nothing structural. That the official example breaks the official
	 * schema is itself worth knowing (design.md, Q3).
	 *
	 * @return void
	 */
	public function testTheVendoredSchemaLoadsAndJudgesTheLogiusExample(): void {
		$document = new \DOMDocument();
		$document->load(self::FIXTURE_DIR . '/GLOBE-R-BV-Request.xml');

		$previous = libxml_use_internal_errors(true);
		$valid = $document->schemaValidate(self::CONTRACT_DIR . '/BerichtVerwerkService/Request/GLOBEBatchRequest.xsd');
		$errors = libxml_get_errors();
		libxml_clear_errors();
		libxml_use_internal_errors($previous);

		$this->assertFalse($valid);
		$elements = [];
		foreach ($errors as $error) {
			preg_match('/\}(\w+)\'/', $error->message, $match);
			$elements[] = ($match[1] ?? $error->message);
		}

		sort($elements);
		$this->assertSame(['BatchID', 'BatchID', 'BerichtID', 'BerichtType'], $elements);
	}//end testTheVendoredSchemaLoadsAndJudgesTheLogiusExample()
}//end class

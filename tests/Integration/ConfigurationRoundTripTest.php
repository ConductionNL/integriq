<?php

/**
 * Integration test: a configuration exported as a directory, previewed and
 * imported into a clean instance, exports there again without drift.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * This drives the real `occ` commands of two live instances, so it self-SKIPS
 * (never fails) unless all of the following are set:
 *   INTEGRIQ_IAC_SOURCE_OCC            command that runs occ on the seeded instance,
 *                                      e.g. "docker exec -u www-data nextcloud php occ"
 *   INTEGRIQ_IAC_TARGET_OCC            command that runs occ on a clean instance
 *                                      that does not hold the configuration yet
 *   INTEGRIQ_IAC_CONFIGURATION         the configuration id on the source instance
 *   INTEGRIQ_IAC_TARGET_CONFIGURATION  the id that configuration has on the target
 *                                      after the import
 *   INTEGRIQ_IAC_WORKDIR               a directory both occ processes and this test
 *                                      read and write (a shared mount); the test
 *                                      writes only into fresh subdirectories of it
 *
 * Run it for real:
 *   INTEGRIQ_IAC_SOURCE_OCC="docker exec -u www-data nc-a php occ" \
 *   INTEGRIQ_IAC_TARGET_OCC="docker exec -u www-data nc-b php occ" \
 *   INTEGRIQ_IAC_CONFIGURATION=demo INTEGRIQ_IAC_TARGET_CONFIGURATION=demo \
 *   INTEGRIQ_IAC_WORKDIR=/shared/iac \
 *   vendor/bin/phpunit -c phpunit-unit.xml --testsuite "Integration Tests" \
 *       --filter ConfigurationRoundTripTest
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Integration
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 *
 * @spec openspec/changes/platform-integrations-as-code/specs/integrations-as-code/spec.md#requirement-export-and-import-round-trip-without-drift-req-iac-004
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Integration;

use OCA\Integriq\Command\Integration\ConfigurationDirectoryCodec;
use PHPUnit\Framework\Attributes\Depends;
use PHPUnit\Framework\TestCase;

/**
 * Export, preview, confirmed import and re-export across two live instances.
 *
 * @spec openspec/changes/platform-integrations-as-code/specs/integrations-as-code/spec.md#requirement-export-and-import-round-trip-without-drift-req-iac-004
 */
class ConfigurationRoundTripTest extends TestCase {

	/**
	 * The environment this test needs, by variable name.
	 *
	 * @var array<string, string>
	 */
	private array $env = [];

	/**
	 * Skip unless both live instances and a shared work directory are configured.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$names = [
			'INTEGRIQ_IAC_SOURCE_OCC',
			'INTEGRIQ_IAC_TARGET_OCC',
			'INTEGRIQ_IAC_CONFIGURATION',
			'INTEGRIQ_IAC_TARGET_CONFIGURATION',
			'INTEGRIQ_IAC_WORKDIR',
		];
		foreach ($names as $name) {
			$value = getenv($name);
			if ($value === false || $value === '') {
				$this->markTestSkipped(
					$name.' is not set: no seeded and clean instance pair is configured '
					.'for this run (the codec half of the round trip is covered by '
					.'ConfigurationDirectoryCodecTest).'
				);
			}

			$this->env[$name] = $value;
		}

		if (is_dir($this->env['INTEGRIQ_IAC_WORKDIR']) === false) {
			$this->markTestSkipped('INTEGRIQ_IAC_WORKDIR is not a directory this test can read.');
		}

	}//end setUp()

	/**
	 * Exporting an unchanged configuration twice writes identical files, and
	 * a preview of that directory on the clean instance writes nothing.
	 *
	 * @return string The exported directory.
	 *
	 * @spec openspec/changes/platform-integrations-as-code/specs/integrations-as-code/spec.md#requirement-a-directory-imports-with-a-preview-and-only-when-confirmed-req-iac-003
	 */
	public function testAPreviewOnTheCleanInstanceWritesNothing(): string {
		$first  = $this->freshDirectory(name: 'source');
		$second = $this->freshDirectory(name: 'source-again');

		$this->assertExit(0, $this->occ('INTEGRIQ_IAC_SOURCE_OCC', ['integriq:config:export', $this->env['INTEGRIQ_IAC_CONFIGURATION'], $first]));
		$this->assertExit(0, $this->occ('INTEGRIQ_IAC_SOURCE_OCC', ['integriq:config:export', $this->env['INTEGRIQ_IAC_CONFIGURATION'], $second]));
		self::assertSame($this->files(directory: $first), $this->files(directory: $second), 'Two exports of an unchanged configuration differ.');

		$preview = $this->occ('INTEGRIQ_IAC_TARGET_OCC', ['integriq:config:import', $first, '--output=json']);
		$this->assertExit(0, $preview);
		$result = json_decode($preview['output'], true);
		self::assertIsArray($result, 'The preview printed no JSON: '.$preview['output']);
		self::assertFalse($result['confirmed']);
		self::assertNull($result['written']);

		$absent = $this->occ('INTEGRIQ_IAC_TARGET_OCC', ['integriq:config:export', $this->env['INTEGRIQ_IAC_TARGET_CONFIGURATION'], $this->freshDirectory(name: 'target-before')]);
		self::assertNotSame(0, $absent['exit'], 'The target already holds the configuration after a preview: '.$absent['output']);

		return $first;

	}//end testAPreviewOnTheCleanInstanceWritesNothing()

	/**
	 * A confirmed import into the clean instance exports there to the same files.
	 *
	 * @param string $exported The directory the source instance exported.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/platform-integrations-as-code/specs/integrations-as-code/spec.md#requirement-export-and-import-round-trip-without-drift-req-iac-004
	 */
	#[Depends('testAPreviewOnTheCleanInstanceWritesNothing')]
	public function testAConfirmedImportRoundTripsWithoutDrift(string $exported): void {
		$import = $this->occ('INTEGRIQ_IAC_TARGET_OCC', ['integriq:config:import', $exported, '--confirm', '--output=json']);
		$this->assertExit(0, $import);
		$result = json_decode($import['output'], true);
		self::assertIsArray($result, 'The import printed no JSON: '.$import['output']);
		self::assertTrue($result['confirmed']);
		self::assertIsArray($result['written']);

		$again = $this->freshDirectory(name: 'target');
		$this->assertExit(0, $this->occ('INTEGRIQ_IAC_TARGET_OCC', ['integriq:config:export', $this->env['INTEGRIQ_IAC_TARGET_CONFIGURATION'], $again]));

		$before = $this->files(directory: $exported);
		$after  = $this->files(directory: $again);

		// The index names the configuration it came from, which is the one field
		// allowed to differ between the instances; the file list must not.
		$indexBefore = json_decode($before[ConfigurationDirectoryCodec::INDEX_FILE], true);
		$indexAfter  = json_decode($after[ConfigurationDirectoryCodec::INDEX_FILE], true);
		self::assertSame($this->env['INTEGRIQ_IAC_CONFIGURATION'], $indexBefore['configuration']);
		self::assertSame($this->env['INTEGRIQ_IAC_TARGET_CONFIGURATION'], $indexAfter['configuration']);
		unset($indexBefore['configuration'], $indexAfter['configuration']);
		self::assertSame($indexBefore, $indexAfter);

		unset($before[ConfigurationDirectoryCodec::INDEX_FILE], $after[ConfigurationDirectoryCodec::INDEX_FILE]);
		self::assertNotSame([], $before, 'The source configuration exported no entity files.');
		self::assertSame(array_keys($before), array_keys($after), 'The round trip changed which files exist.');
		foreach ($before as $path => $contents) {
			self::assertSame($contents, $after[$path], $path.' drifted in the round trip.');
		}

	}//end testAConfirmedImportRoundTripsWithoutDrift()

	/**
	 * Run occ on one of the instances.
	 *
	 * @param string       $instance The environment variable holding the occ command.
	 * @param list<string> $args     The occ arguments.
	 *
	 * @return array{exit: int, output: string}
	 */
	private function occ(string $instance, array $args): array {
		$command = $this->env[$instance].' '.implode(' ', array_map('escapeshellarg', $args)).' 2>&1';
		$lines   = [];
		$exit    = 0;
		exec($command, $lines, $exit);

		return ['exit' => $exit, 'output' => implode("\n", $lines)];

	}//end occ()

	/**
	 * Assert an occ run exited as expected, showing its output when not.
	 *
	 * @param int                              $expected The expected exit code.
	 * @param array{exit: int, output: string} $run      The occ run.
	 *
	 * @return void
	 */
	private function assertExit(int $expected, array $run): void {
		self::assertSame($expected, $run['exit'], 'occ exited '.$run['exit'].': '.$run['output']);

	}//end assertExit()

	/**
	 * A new, empty subdirectory of the shared work directory.
	 *
	 * @param string $name What the directory holds.
	 *
	 * @return string The directory.
	 */
	private function freshDirectory(string $name): string {
		$directory = rtrim($this->env['INTEGRIQ_IAC_WORKDIR'], '/').'/'.$name.'-'.bin2hex(random_bytes(4));
		self::assertTrue(mkdir($directory, 0777, true), 'Cannot create '.$directory);
		chmod($directory, 0777);

		return $directory;

	}//end freshDirectory()

	/**
	 * Every file of an exported directory, by relative path.
	 *
	 * @param string $directory The directory.
	 *
	 * @return array<string, string>
	 */
	private function files(string $directory): array {
		$files    = [];
		$iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS));
		foreach ($iterator as $file) {
			if ($file->isFile() === true) {
				$files[substr($file->getPathname(), (strlen($directory) + 1))] = (string) file_get_contents($file->getPathname());
			}
		}

		ksort($files, SORT_STRING);

		return $files;

	}//end files()

}//end class

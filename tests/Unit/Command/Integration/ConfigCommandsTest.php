<?php

/**
 * Unit tests for the integriq config export and import occ commands.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Command\Integration
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 *
 * @spec openspec/changes/platform-integrations-as-code/tasks.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Command\Integration;

use OCA\Integriq\Command\Integration\ConfigExport;
use OCA\Integriq\Command\Integration\ConfigImport;
use OCA\Integriq\Command\Integration\ConfigurationDirectoryCodec;
use OCA\Integriq\Service\ConfigurationImportPreviewServiceInterface;
use OCA\Integriq\Service\ConfigurationServiceInterface;
use OCA\Integriq\Service\Security\SensitiveFieldRegistry;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Export writes a directory through the screen's export; import previews and writes only with --confirm.
 *
 * @spec openspec/changes/platform-integrations-as-code/specs/integrations-as-code/spec.md#requirement-a-directory-imports-with-a-preview-and-only-when-confirmed-req-iac-003
 */
class ConfigCommandsTest extends TestCase {

	/**
	 * @var ConfigurationServiceInterface&MockObject
	 */
	private $configurations;

	/**
	 * @var ConfigurationImportPreviewServiceInterface&MockObject
	 */
	private $preview;

	/**
	 * The real codec.
	 *
	 * @var ConfigurationDirectoryCodec
	 */
	private ConfigurationDirectoryCodec $codec;

	/**
	 * The work directory of this test, removed afterwards.
	 *
	 * @var string
	 */
	private string $directory;

	/**
	 * Set up the services and a work directory.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->configurations = $this->createMock(ConfigurationServiceInterface::class);
		$this->preview        = $this->createMock(ConfigurationImportPreviewServiceInterface::class);
		$this->codec          = new ConfigurationDirectoryCodec(sensitiveFields: new SensitiveFieldRegistry());
		$this->directory      = sys_get_temp_dir().'/integriq-config-'.bin2hex(random_bytes(6));

	}//end setUp()

	/**
	 * Remove the work directory.
	 *
	 * @return void
	 */
	protected function tearDown(): void {
		foreach (array_merge(glob($this->directory.'/*/*') ?: [], glob($this->directory.'/*') ?: []) as $path) {
			is_dir($path) === true ? rmdir($path) : unlink($path);
		}

		if (is_dir($this->directory) === true) {
			rmdir($this->directory);
		}

		parent::tearDown();

	}//end tearDown()

	/**
	 * An exported configuration with one source and one mapping.
	 *
	 * @param string $rule The mapping's rule.
	 *
	 * @return array<string, mixed>
	 */
	private static function exported(string $rule='title'): array {
		return [
			'components' => [
				'sources' => ['api' => [['slug' => 'brp', 'name' => 'BRP', 'password' => 'hunter2']]],
				'mappings' => ['mapping' => [['slug' => 'woo-index-publication', 'mapping' => ['title' => '{{ '.$rule.' }}']]]],
			],
		];

	}//end exported()

	/**
	 * Run the export command.
	 *
	 * @return CommandTester The finished tester.
	 */
	private function export(): CommandTester {
		$tester = new CommandTester(new ConfigExport(configurations: $this->configurations, codec: $this->codec));
		$tester->execute(['configuration' => 'woo', 'directory' => $this->directory]);

		return $tester;

	}//end export()

	/**
	 * Run the import command.
	 *
	 * @param array<string, mixed> $options The options.
	 *
	 * @return CommandTester The finished tester.
	 */
	private function import(array $options=[]): CommandTester {
		$command = new ConfigImport(configurations: $this->configurations, preview: $this->preview, codec: $this->codec);
		$tester  = new CommandTester($command);
		$tester->execute(['directory' => $this->directory] + $options);

		return $tester;

	}//end import()

	/**
	 * Export writes the files of the screen's export; a second export of an edit changes one file.
	 *
	 * @return void
	 */
	public function testExportWritesTheDirectoryAndReportsChangedFiles(): void {
		$this->configurations->expects($this->exactly(2))->method('exportConfiguration')->with('woo')
			->willReturnOnConsecutiveCalls(self::exported(), self::exported(rule: 'subject'));

		$first = $this->export();
		$this->assertSame(0, $first->getStatusCode());
		$this->assertStringContainsString('3 files in '.$this->directory.', 3 changed.', $first->getDisplay());
		$this->assertStringNotContainsString('hunter2', (string) file_get_contents($this->directory.'/sources/brp.json'));

		$second = $this->export();
		$this->assertSame(0, $second->getStatusCode());
		$this->assertStringContainsString("1 changed.\n  mappings/woo-index-publication.json\n", $second->getDisplay());

	}//end testExportWritesTheDirectoryAndReportsChangedFiles()

	/**
	 * An empty configuration exits 2 and writes nothing; a failing export exits 1.
	 *
	 * @return void
	 */
	public function testExportExitsTwoOnNothingAndOneOnAFailure(): void {
		$this->configurations->method('exportConfiguration')->willReturnOnConsecutiveCalls(
			['components' => ['sources' => [], 'mappings' => []]],
			$this->throwException(new RuntimeException('register down'))
		);

		$empty = $this->export();
		$this->assertSame(2, $empty->getStatusCode());
		$this->assertStringContainsString('Configuration "woo" holds nothing to export.', $empty->getDisplay());
		$this->assertDirectoryDoesNotExist($this->directory);

		$failed = $this->export();
		$this->assertSame(1, $failed->getStatusCode());
		$this->assertStringContainsString('The export failed: register down', $failed->getDisplay());

	}//end testExportExitsTwoOnNothingAndOneOnAFailure()

	/**
	 * An entity without a slug is exported with a warning.
	 *
	 * @return void
	 */
	public function testExportWarnsAboutAnEntityWithoutASlug(): void {
		$this->configurations->method('exportConfiguration')->willReturn(['components' => ['rules' => ['3c2f' => ['name' => 'R']]]]);

		$tester = $this->export();
		$this->assertSame(0, $tester->getStatusCode());
		$this->assertStringContainsString('rules "3c2f" has no slug', $tester->getDisplay());

	}//end testExportWarnsAboutAnEntityWithoutASlug()

	/**
	 * Without --confirm the preview prints and importConfiguration() is never called.
	 *
	 * @return void
	 */
	public function testImportWithoutConfirmPreviewsAndWritesNothing(): void {
		$this->configurations->method('exportConfiguration')->willReturn(self::exported());
		$this->export();

		$joined = $this->codec->join(files: $this->codec->readDirectory(directory: $this->directory));
		$this->preview->expects($this->once())->method('preview')->with($joined)->willReturn(
			[
				'creates' => [],
				'updates' => [['type' => 'mapping', 'slug' => 'woo-index-publication']],
				'collisions' => [],
				'unresolvedReferences' => [],
				'credentialsNeedingReentry' => [['type' => 'source', 'slug' => 'brp', 'fields' => ['password']]],
			]
		);
		$this->configurations->expects($this->never())->method('importConfiguration');

		$tester = $this->import();
		$this->assertSame(0, $tester->getStatusCode());
		$this->assertSame(
			"Creates: 0\nUpdates: 1\n  mapping woo-index-publication\nCollisions: 0\nUnresolved references: 0\n"
				."Credentials to enter again: 1\n  source brp {\"fields\":[\"password\"]}\n"
				."Nothing was written. Run again with --confirm to import.\n",
			$tester->getDisplay()
		);

	}//end testImportWithoutConfirmPreviewsAndWritesNothing()

	/**
	 * With --confirm the joined document goes to importConfiguration() and the written slugs print.
	 *
	 * @return void
	 */
	public function testImportWithConfirmImportsThroughTheScreensPath(): void {
		$this->configurations->method('exportConfiguration')->willReturn(self::exported());
		$this->export();

		$joined = $this->codec->join(files: $this->codec->readDirectory(directory: $this->directory));
		$this->preview->method('preview')->willReturn(['creates' => [['type' => 'source', 'slug' => 'brp']]]);
		$this->configurations->expects($this->exactly(2))->method('importConfiguration')->with($joined)
			->willReturn(['sources' => ['brp' => new \stdClass()], 'mappings' => []]);

		$tester = $this->import(['--confirm' => true]);
		$this->assertSame(0, $tester->getStatusCode());
		$this->assertStringContainsString("Imported sources: brp\nImported mappings: -\n", $tester->getDisplay());
		$this->assertStringNotContainsString('Nothing was written', $tester->getDisplay());

		$json = $this->import(['--confirm' => true, '--output' => 'json']);
		$this->assertSame(
			['confirmed' => true, 'preview' => ['creates' => [['type' => 'source', 'slug' => 'brp']]], 'written' => ['sources' => ['brp'], 'mappings' => []]],
			json_decode($json->getDisplay(), true)
		);

	}//end testImportWithConfirmImportsThroughTheScreensPath()

	/**
	 * A directory without an index, a refused preview and a failed import exit 1.
	 *
	 * @return void
	 */
	public function testImportExitsOneOnAnUnreadableDirectoryOrAFailedImport(): void {
		$missing = $this->import();
		$this->assertSame(1, $missing->getStatusCode());
		$this->assertStringContainsString('has no integriq-configuration.json', $missing->getDisplay());

		$this->configurations->method('exportConfiguration')->willReturn(self::exported());
		$this->export();
		$this->preview->method('preview')->willReturn([]);
		$this->configurations->method('importConfiguration')->willThrowException(new \InvalidArgumentException('personal data'));

		$failed = $this->import(['--confirm' => true]);
		$this->assertSame(1, $failed->getStatusCode());
		$this->assertStringContainsString('The import failed: personal data', $failed->getDisplay());

	}//end testImportExitsOneOnAnUnreadableDirectoryOrAFailedImport()

}//end class

<?php

/**
 * Unit tests for the failure and plain-output paths of the integration occ commands.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Command\Integration
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 *
 * @spec openspec/changes/platform-integrations-as-code/tasks.md#task-1
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Command\Integration;

use OCA\Integriq\Command\Integration\ConfigExport;
use OCA\Integriq\Command\Integration\ConfigImport;
use OCA\Integriq\Command\Integration\ConfigurationDirectoryCodec;
use OCA\Integriq\Command\Integration\IntegrationCommand;
use OCA\Integriq\Command\Integration\IntegrationObjectLookup;
use OCA\Integriq\Command\Integration\JobRun;
use OCA\Integriq\Command\Integration\SourceList;
use OCA\Integriq\Service\ConfigurationImportPreviewServiceInterface;
use OCA\Integriq\Service\ConfigurationServiceInterface;
use OCA\Integriq\Service\JobServiceInterface;
use OCA\OpenRegister\Db\ObjectEntity;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * A list as a table, a job run that throws, a failed export write and a
 * preview entry that is not an object.
 *
 * @covers \OCA\Integriq\Command\Integration\IntegrationListCommand
 * @covers \OCA\Integriq\Command\Integration\SourceList
 * @covers \OCA\Integriq\Command\Integration\JobRun
 * @covers \OCA\Integriq\Command\Integration\IntegrationCommand
 * @covers \OCA\Integriq\Command\Integration\ConfigExport
 * @covers \OCA\Integriq\Command\Integration\ConfigImport
 *
 * @spec openspec/changes/platform-integrations-as-code/specs/integrations-as-code/spec.md#requirement-sources-synchronizations-and-jobs-can-be-listed-run-and-tested-with-occ-req-iac-001
 */
class IntegrationCommandEdgesTest extends TestCase {

	/**
	 * An entity with a uuid and a payload.
	 *
	 * @param string               $uuid The uuid.
	 * @param array<string, mixed> $data The payload.
	 *
	 * @return ObjectEntity
	 */
	private static function entity(string $uuid, array $data=[]): ObjectEntity {
		$entity = new ObjectEntity();
		$entity->setUuid($uuid);
		$entity->setObject($data);

		return $entity;

	}//end entity()

	/**
	 * Without --output json the list renders a table, sorted by slug.
	 *
	 * @return void
	 */
	public function testAListWithoutJsonRendersATable(): void {
		$lookup = $this->createMock(IntegrationObjectLookup::class);
		$lookup->expects($this->once())->method('list')->with('source')->willReturn(
			[
				self::entity('u-2', ['slug' => 'zeta', 'name' => 'Zeta']),
				self::entity('u-1', ['slug' => 'alpha', 'name' => 'Alpha']),
			]
		);

		$tester = new CommandTester(new SourceList($lookup));

		$this->assertSame(IntegrationCommand::EXIT_OK, $tester->execute([]));
		$lines = array_values(array_filter(array_map('trim', explode("\n", $tester->getDisplay())), static fn (string $l): bool => str_starts_with($l, '|')));
		$this->assertCount(3, $lines);
		$this->assertMatchesRegularExpression('/^\|\s*uuid\s*\|\s*slug\s*\|\s*name\s*\|$/', $lines[0]);
		$this->assertMatchesRegularExpression('/^\|\s*u-1\s*\|\s*alpha\s*\|\s*Alpha\s*\|$/', $lines[1]);
		$this->assertMatchesRegularExpression('/^\|\s*u-2\s*\|\s*zeta\s*\|\s*Zeta\s*\|$/', $lines[2]);

	}//end testAListWithoutJsonRendersATable()

	/**
	 * A job run that throws exits 1 and prints the error as a failed result.
	 *
	 * @return void
	 */
	public function testAJobRunThatThrowsExits1(): void {
		$job    = self::entity('j-1');
		$lookup = $this->createMock(IntegrationObjectLookup::class);
		$lookup->method('resolve')->with('job', 'nightly')->willReturn($job);
		$jobs = $this->createMock(JobServiceInterface::class);
		$jobs->expects($this->once())->method('executeJob')->with($job, false)->willThrowException(new RuntimeException('worker gone'));

		$tester = new CommandTester(new JobRun($lookup, $jobs));

		$this->assertSame(IntegrationCommand::EXIT_FAILED, $tester->execute(['job' => 'nightly', '--output' => 'json']));
		$this->assertSame(['passed' => false, 'error' => 'worker gone'], json_decode($tester->getDisplay(), true));

	}//end testAJobRunThatThrowsExits1()

	/**
	 * A directory that cannot be written makes the export exit 1 with the reason.
	 *
	 * @return void
	 */
	public function testAFailedExportWriteExits1(): void {
		$configurations = $this->createMock(ConfigurationServiceInterface::class);
		$configurations->method('exportConfiguration')->with('woo')->willReturn(['components' => []]);
		$codec = $this->createMock(ConfigurationDirectoryCodec::class);
		$codec->method('split')->willReturn(['files' => ['a.json' => '{}', 'b.json' => '{}'], 'warnings' => []]);
		$codec->expects($this->once())->method('writeDirectory')->with('/nowhere', ['a.json' => '{}', 'b.json' => '{}'])
			->willThrowException(new RuntimeException('read-only'));

		$tester = new CommandTester(new ConfigExport(configurations: $configurations, codec: $codec));

		$this->assertSame(IntegrationCommand::EXIT_FAILED, $tester->execute(['configuration' => 'woo', 'directory' => '/nowhere']));
		$this->assertStringContainsString('Writing /nowhere failed: read-only', $tester->getDisplay());

	}//end testAFailedExportWriteExits1()

	/**
	 * A preview entry that is not an object is printed as its JSON value.
	 *
	 * @return void
	 */
	public function testAScalarPreviewEntryIsPrintedAsJson(): void {
		$configurations = $this->createMock(ConfigurationServiceInterface::class);
		$configurations->expects($this->never())->method('importConfiguration');
		$preview = $this->createMock(ConfigurationImportPreviewServiceInterface::class);
		$preview->method('preview')->willReturn(['unresolvedReferences' => ['mappings/a/b', 7]]);
		$codec = $this->createMock(ConfigurationDirectoryCodec::class);
		$codec->method('readDirectory')->willReturn([]);
		$codec->method('join')->willReturn(['components' => []]);

		$tester = new CommandTester(new ConfigImport(configurations: $configurations, preview: $preview, codec: $codec));

		$this->assertSame(IntegrationCommand::EXIT_OK, $tester->execute(['directory' => '/d']));
		$display = $tester->getDisplay();
		$this->assertStringContainsString("Unresolved references: 2\n  \"mappings/a/b\"\n  7\n", $display);
		$this->assertStringContainsString('Nothing was written.', $display);

	}//end testAScalarPreviewEntryIsPrintedAsJson()

}//end class

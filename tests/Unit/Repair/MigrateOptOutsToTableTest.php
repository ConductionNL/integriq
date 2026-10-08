<?php
/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/opt-outs-in-an-app-table-and-routing-rules-read-as-config/specs/outbound-sender-identity/spec.md
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Repair;

use OCA\Integriq\AppInfo\Application;
use OCA\Integriq\Outbound\Identity\OptOutTableMigrator;
use OCA\Integriq\Repair\MigrateOptOutsToTable;
use OCP\IAppConfig;
use OCP\Migration\IOutput;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use RuntimeException;

/**
 * The opt-out copy runs once: a marker is set after a run that copied without failure.
 *
 * @spec openspec/changes/opt-outs-in-an-app-table-and-routing-rules-read-as-config/specs/outbound-sender-identity/spec.md
 */
final class MigrateOptOutsToTableTest extends TestCase {

	/** @var array<string,bool> */
	private array $store = [];


	/**
	 * An IAppConfig over the in-memory store.
	 *
	 * @return IAppConfig
	 */
	private function appConfig(): IAppConfig {
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueBool')->willReturnCallback(
			fn (string $app, string $key, bool $default = false): bool => $this->store[$app . '/' . $key] ?? $default
		);
		$appConfig->method('setValueBool')->willReturnCallback(
			function (string $app, string $key, bool $value): bool {
				$this->store[$app . '/' . $key] = $value;
				return true;
			}
		);

		return $appConfig;
	}//end appConfig()


	/**
	 * A container that resolves the migrator to the given double, or throws.
	 *
	 * @param OptOutTableMigrator|RuntimeException $migrator What the container answers.
	 *
	 * @return ContainerInterface
	 */
	private function container(OptOutTableMigrator|RuntimeException $migrator): ContainerInterface {
		$container = $this->createMock(ContainerInterface::class);
		if ($migrator instanceof RuntimeException) {
			$container->method('get')->willThrowException($migrator);
		} else {
			$container->method('get')->willReturn($migrator);
		}

		return $container;
	}//end container()


	/**
	 * A migrator whose migrate() is expected the given number of times.
	 *
	 * @param int $calls Expected calls.
	 *
	 * @return OptOutTableMigrator
	 */
	private function migrator(int $calls): OptOutTableMigrator {
		$migrator = $this->createMock(OptOutTableMigrator::class);
		$migrator->expects($this->exactly($calls))->method('migrate')->willReturn(['read' => 2, 'copied' => 1, 'present' => 1, 'skipped' => 0]);

		return $migrator;
	}//end migrator()


	/**
	 * A successful copy sets the marker, and the next run does not scan again.
	 *
	 * @return void
	 */
	public function testCopiesOnceAndThenSkips(): void {
		$step = new MigrateOptOutsToTable(container: $this->container($this->migrator(1)), appConfig: $this->appConfig());

		$step->run($this->createMock(IOutput::class));
		$step->run($this->createMock(IOutput::class));

		$this->assertTrue($this->store[Application::APP_ID . '/' . MigrateOptOutsToTable::MARKER_KEY]);
	}//end testCopiesOnceAndThenSkips()


	/**
	 * A run that cannot reach the migrator warns, sets no marker, and is retried.
	 *
	 * @return void
	 */
	public function testAFailedRunLeavesTheMarkerUnsetSoTheNextUpgradeRetries(): void {
		$output = $this->createMock(IOutput::class);
		$output->expects($this->once())->method('warning');

		$step = new MigrateOptOutsToTable(
			container: $this->container(new RuntimeException('OpenRegister is not available')),
			appConfig: $this->appConfig()
		);
		$step->run($output);

		$this->assertArrayNotHasKey(Application::APP_ID . '/' . MigrateOptOutsToTable::MARKER_KEY, $this->store);
	}//end testAFailedRunLeavesTheMarkerUnsetSoTheNextUpgradeRetries()


	/**
	 * A marker already set means no container lookup and no scan at all.
	 *
	 * @return void
	 */
	public function testAnExistingMarkerSkipsTheScan(): void {
		$this->store[Application::APP_ID . '/' . MigrateOptOutsToTable::MARKER_KEY] = true;

		$container = $this->createMock(ContainerInterface::class);
		$container->expects($this->never())->method('get');

		(new MigrateOptOutsToTable(container: $container, appConfig: $this->appConfig()))->run($this->createMock(IOutput::class));
	}//end testAnExistingMarkerSkipsTheScan()
}//end class

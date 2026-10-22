<?php

/**
 * Unit tests for the SyncConnectionDeclarations repair step (connection-registry, umbrella D5).
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Repair
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @spec openspec/specs/connection-registry/spec.md#requirement-the-sync-turns-declaration-files-into-connection-rows-req-conn-001
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Repair;

use OCA\Integriq\Repair\SyncConnectionDeclarations;
use OCA\Integriq\Service\ConnectionRegistryService;
use OCA\Integriq\Service\DefaultConnectorService;
use OCP\IAppConfig;
use OCP\Migration\IOutput;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * 🔴 ON DEMAND ONLY, NEVER ON INSTALL. The step must not touch the registry
 * at all until the operator has opted into default source connectors — a
 * connection-status row for a Source that was never installed is noise.
 */
class SyncConnectionDeclarationsTest extends TestCase {

	/**
	 * An appconfig double answering the default-connectors decision.
	 *
	 * @param string $decision The stored `default_connectors_dataset` value.
	 *
	 * @return IAppConfig
	 */
	private function appConfig(string $decision): IAppConfig {
		$appConfig = $this->createMock(originalClassName: IAppConfig::class);
		$appConfig->method('getValueString')->willReturn($decision);

		return $appConfig;
	}//end appConfig()

	/**
	 * Undecided, declined, or any other value: the sync never runs, and
	 * OpenRegister's presence is never even checked.
	 *
	 * @return void
	 */
	public function testSkipsWhenDefaultConnectorsWereNotOptedInto(): void {
		$container = $this->createMock(originalClassName: ContainerInterface::class);
		$container->expects($this->never())->method('get');
		$output = $this->createMock(originalClassName: IOutput::class);
		$output->expects($this->once())->method('info')->with($this->stringContains(string: 'not opted into'));

		(new SyncConnectionDeclarations(
			container: $container,
			appConfig: $this->appConfig(decision: DefaultConnectorService::NONE_DATASET),
			logger: $this->createMock(originalClassName: LoggerInterface::class)
		))->run($output);
	}//end testSkipsWhenDefaultConnectorsWereNotOptedInto()

	/**
	 * Once opted in, the step runs a full sync and reports the counts.
	 *
	 * @return void
	 */
	public function testRunsFullSyncWhenOptedIn(): void {
		$registry = $this->getMockBuilder(className: ConnectionRegistryService::class)->disableOriginalConstructor()->onlyMethods(['sync'])->getMock();
		$registry->expects($this->once())->method('sync')->with(null)
			->willReturn(['created' => 12, 'updated' => 0, 'deleted' => 0, 'unchanged' => 0, 'skipped' => ['pipelinq']]);
		$container = $this->createMock(originalClassName: ContainerInterface::class);
		$container->method('get')->willReturn($registry);
		$output = $this->createMock(originalClassName: IOutput::class);
		$output->expects($this->once())->method('info')->with($this->stringContains(string: 'created 12'));

		(new SyncConnectionDeclarations(
			container: $container,
			appConfig: $this->appConfig(decision: DefaultConnectorService::ALL_DATASET),
			logger: $this->createMock(originalClassName: LoggerInterface::class)
		))->run($output);
	}//end testRunsFullSyncWhenOptedIn()

	/**
	 * A failing sync is a warning, not an aborted install.
	 *
	 * @return void
	 */
	public function testFailureDoesNotThrow(): void {
		$container = $this->createMock(originalClassName: ContainerInterface::class);
		$container->method('get')->willThrowException(new \RuntimeException('OpenRegister is not ready'));
		$output = $this->createMock(originalClassName: IOutput::class);
		$output->expects($this->once())->method('warning');

		(new SyncConnectionDeclarations(
			container: $container,
			appConfig: $this->appConfig(decision: DefaultConnectorService::ALL_DATASET),
			logger: $this->createMock(originalClassName: LoggerInterface::class)
		))->run($output);
	}//end testFailureDoesNotThrow()
}//end class

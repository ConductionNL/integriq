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
 * @spec openspec/changes/connection-registry/specs/connection-registry/spec.md#requirement-the-sync-turns-declaration-files-into-connection-rows-req-conn-001
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Repair;

use OCA\Integriq\Repair\SyncConnectionDeclarations;
use OCA\Integriq\Service\ConnectionRegistryService;
use OCP\Migration\IOutput;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * The step syncs every app and never throws.
 */
class SyncConnectionDeclarationsTest extends TestCase {

	/**
	 * The step runs a full sync and reports the counts.
	 *
	 * @return void
	 */
	public function testRunsFullSync(): void {
		$registry = $this->getMockBuilder(className: ConnectionRegistryService::class)->disableOriginalConstructor()->onlyMethods(['sync'])->getMock();
		$registry->expects($this->once())->method('sync')->with(null)
			->willReturn(['created' => 12, 'updated' => 0, 'deleted' => 0, 'unchanged' => 0, 'skipped' => ['pipelinq']]);
		$container = $this->createMock(originalClassName: ContainerInterface::class);
		$container->method('get')->willReturn($registry);
		$output = $this->createMock(originalClassName: IOutput::class);
		$output->expects($this->once())->method('info')->with($this->stringContains(string: 'created 12'));

		(new SyncConnectionDeclarations(container: $container, logger: $this->createMock(originalClassName: LoggerInterface::class)))->run($output);
	}//end testRunsFullSync()

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

		(new SyncConnectionDeclarations(container: $container, logger: $this->createMock(originalClassName: LoggerInterface::class)))->run($output);
	}//end testFailureDoesNotThrow()
}//end class

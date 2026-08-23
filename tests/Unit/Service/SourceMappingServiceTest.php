<?php

/**
 * Unit tests for SourceMappingService.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @spec openspec/changes/openconnector-adopt-or-abstractions/tasks.md#task-7
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Service;

use OCA\Integriq\Service\ObjectService;
use OCA\Integriq\Service\SourceMappingService;
use OCP\App\IAppManager;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;

/**
 * Tests for the renamed SourceMappingService (formerly ObjectService).
 */
class SourceMappingServiceTest extends TestCase {

	/**
	 * @var SourceMappingService
	 */
	private SourceMappingService $service;

	/**
	 * Set up test fixtures.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$appManager = $this->createMock(IAppManager::class);
		$container = $this->createMock(ContainerInterface::class);

		$this->service = new SourceMappingService(
			appManager: $appManager,
			container: $container,
		);
	}//end setUp()

	/**
	 * Test that SourceMappingService is instantiated without errors.
	 *
	 * @return void
	 */
	public function testConstructorWiresDependencies(): void {
		$this->assertInstanceOf(SourceMappingService::class, $this->service);
	}//end testConstructorWiresDependencies()

	/**
	 * Test that BASE_OBJECT constant is preserved.
	 *
	 * @return void
	 */
	public function testBaseObjectConstantIsPreserved(): void {
		$this->assertSame('objects', SourceMappingService::BASE_OBJECT['database']);
		$this->assertSame('json', SourceMappingService::BASE_OBJECT['collection']);
	}//end testBaseObjectConstantIsPreserved()

	/**
	 * Test that ObjectService (deprecated alias) extends SourceMappingService.
	 *
	 * Scenario: deprecated alias triggers E_USER_DEPRECATED
	 *
	 * @return void
	 */
	public function testObjectServiceIsDeprecatedAliasOfSourceMappingService(): void {
		$this->assertTrue(is_subclass_of(ObjectService::class, SourceMappingService::class));
	}//end testObjectServiceIsDeprecatedAliasOfSourceMappingService()

	/**
	 * Test that getOpenRegisters returns null when openregister is not installed.
	 *
	 * @return void
	 */
	public function testGetOpenRegistersReturnsNullWhenNotInstalled(): void {
		$appManager = $this->createMock(IAppManager::class);
		$appManager->method('getEnabledApps')->willReturn([]);
		$container = $this->createMock(ContainerInterface::class);

		$service = new SourceMappingService(appManager: $appManager, container: $container);

		$this->assertNull($service->getOpenRegisters());
	}//end testGetOpenRegistersReturnsNullWhenNotInstalled()

	/**
	 * Test that deprecated ObjectService alias fires E_USER_DEPRECATED on instantiation.
	 *
	 * @return void
	 */
	public function testDeprecatedAliasFiresEUserDeprecated(): void {
		$appManager = $this->createMock(IAppManager::class);
		$container = $this->createMock(ContainerInterface::class);

		$caught = false;
		set_error_handler(
			function (int $errno, string $errstr) use (&$caught): bool {
				if ($errno === E_USER_DEPRECATED && str_contains($errstr, 'SourceMappingService') === true) {
					$caught = true;
				}

				return true;
			},
			E_USER_DEPRECATED
		);

		// phpcs:disable CustomSn.Functions.NamedParameters
		new ObjectService($appManager, $container);
		// phpcs:enable CustomSn.Functions.NamedParameters

		restore_error_handler();

		$this->assertTrue($caught, 'ObjectService constructor must fire E_USER_DEPRECATED pointing to SourceMappingService.');
	}//end testDeprecatedAliasFiresEUserDeprecated()
}//end class

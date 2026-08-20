<?php

declare(strict_types=1);

/**
 * OrganisationBridgeServiceTest
 *
 * Unit tests for the OrganisationBridgeService class to verify organization
 * functionality integration with OpenRegister app.
 *
 * @category  Test
 * @package   OpenConnector
 * @author    Conduction <info@conduction.nl>
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   1.0.0
 * @link      https://github.com/ConductionNL/opencatalogi
 */

namespace OCA\OpenConnector\Tests\Unit\Service;

use OCA\OpenConnector\Service\OrganisationBridgeService;
use OCP\App\IAppManager;
use OCP\IContainer;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerExceptionInterface;
use Psr\Log\LoggerInterface;

/**
 * Test class for OrganisationBridgeService
 *
 * Tests the bridge service functionality including fallback behavior
 * when OpenRegister is not available.
 *
 * @psalm-suppress UnusedClass
 */
class OrganisationBridgeServiceTest extends TestCase {
	/**
	 * Test that service returns null when OpenRegister is not installed
	 *
	 * @return void
	 */
	public function testGetOrganisationServiceWhenOpenRegisterNotInstalled(): void {
		// Mock app manager to return false for openregister
		$appManager = $this->createMock(IAppManager::class);
		$appManager->method('getEnabledApps')
			->willReturn(['openconnector', 'files']);

		$container = $this->createMock(IContainer::class);
		$logger = $this->createMock(LoggerInterface::class);

		$service = new OrganisationBridgeService($appManager, $container, $logger);

		$result = $service->getOrganisationService();
		$this->assertNull($result);
	}

	/**
	 * Test that service returns null when OpenRegister is installed but service not available
	 *
	 * @return void
	 */
	public function testGetOrganisationServiceWhenServiceNotAvailable(): void {
		// Mock app manager to return true for openregister
		$appManager = $this->createMock(IAppManager::class);
		$appManager->method('getEnabledApps')
			->willReturn(['openconnector', 'openregister', 'files']);

		$container = $this->createMock(IContainer::class);
		$container->method('get')
			->with('OCA\OpenRegister\Service\OrganisationService')
			->willThrowException(new class extends \Exception implements ContainerExceptionInterface {});

		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())
			->method('warning')
			->with('OpenRegister OrganisationService not available');

		$service = new OrganisationBridgeService($appManager, $container, $logger);

		$result = $service->getOrganisationService();
		$this->assertNull($result);
	}

	/**
	 * Test that service returns correct stats when OpenRegister is not available
	 *
	 * @return void
	 */
	public function testGetUserOrganisationStatsWhenServiceNotAvailable(): void {
		// Mock app manager to return false for openregister
		$appManager = $this->createMock(IAppManager::class);
		$appManager->method('getEnabledApps')
			->willReturn(['openconnector', 'files']);

		$container = $this->createMock(IContainer::class);
		$logger = $this->createMock(LoggerInterface::class);

		$service = new OrganisationBridgeService($appManager, $container, $logger);

		$result = $service->getUserOrganisationStats();

		$this->assertIsArray($result);
		$this->assertEquals(0, $result['total']);
		$this->assertNull($result['active']);
		$this->assertEmpty($result['results']);
		$this->assertFalse($result['available']);
	}

	/**
	 * Test that service returns correct result when setting active organization fails
	 *
	 * @return void
	 */
	public function testSetActiveOrganisationWhenServiceNotAvailable(): void {
		// Mock app manager to return false for openregister
		$appManager = $this->createMock(IAppManager::class);
		$appManager->method('getEnabledApps')
			->willReturn(['openconnector', 'files']);

		$container = $this->createMock(IContainer::class);
		$logger = $this->createMock(LoggerInterface::class);

		$service = new OrganisationBridgeService($appManager, $container, $logger);

		$result = $service->setActiveOrganisation('test-uuid');

		$this->assertIsArray($result);
		$this->assertFalse($result['success']);
		$this->assertEquals('Organization service not available', $result['message']);
		$this->assertFalse($result['available']);
	}

	/**
	 * Test that service correctly identifies when organization service is available
	 *
	 * @return void
	 */
	public function testIsOrganisationServiceAvailable(): void {
		// Mock app manager to return true for openregister
		$appManager = $this->createMock(IAppManager::class);
		$appManager->method('getEnabledApps')
			->willReturn(['openconnector', 'openregister', 'files']);

		$container = $this->createMock(IContainer::class);
		$container->method('get')
			->with('OCA\OpenRegister\Service\OrganisationService')
			->willReturn($this->createMock(\OCA\OpenRegister\Service\OrganisationService::class));

		$logger = $this->createMock(LoggerInterface::class);

		$service = new OrganisationBridgeService($appManager, $container, $logger);

		$result = $service->isOrganisationServiceAvailable();
		$this->assertTrue($result);
	}

	/**
	 * Test that service returns empty array when getting user organizations fails
	 *
	 * @return void
	 */
	public function testGetUserOrganisationsWhenServiceNotAvailable(): void {
		// Mock app manager to return false for openregister
		$appManager = $this->createMock(IAppManager::class);
		$appManager->method('getEnabledApps')
			->willReturn(['openconnector', 'files']);

		$container = $this->createMock(IContainer::class);
		$logger = $this->createMock(LoggerInterface::class);

		$service = new OrganisationBridgeService($appManager, $container, $logger);

		$result = $service->getUserOrganisations();
		$this->assertIsArray($result);
		$this->assertEmpty($result);
	}

	/**
	 * Test that service returns null when getting active organization fails
	 *
	 * @return void
	 */
	public function testGetActiveOrganisationWhenServiceNotAvailable(): void {
		// Mock app manager to return false for openregister
		$appManager = $this->createMock(IAppManager::class);
		$appManager->method('getEnabledApps')
			->willReturn(['openconnector', 'files']);

		$container = $this->createMock(IContainer::class);
		$logger = $this->createMock(LoggerInterface::class);

		$service = new OrganisationBridgeService($appManager, $container, $logger);

		$result = $service->getActiveOrganisation();
		$this->assertNull($result);
	}
}

<?php

/**
 * Tests for the Service component's contract bindings.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.Integriq.nl
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Service;

use OCA\Integriq\Service\CallService;
use OCA\Integriq\Service\CallServiceInterface;
use OCA\Integriq\Service\EndpointService;
use OCA\Integriq\Service\EndpointServiceInterface;
use OCA\Integriq\Service\EventService;
use OCA\Integriq\Service\EventServiceInterface;
use OCA\Integriq\Service\ServiceRegistrar;
use OCA\Integriq\Service\SynchronizationService;
use OCA\Integriq\Service\SynchronizationServiceInterface;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use PHPUnit\Framework\TestCase;

/**
 * The registrar binds every Service contract to the class that implements it.
 */
class ServiceRegistrarTest extends TestCase {
	private const EXPECTED = [
		CallServiceInterface::class            => CallService::class,
		EndpointServiceInterface::class        => EndpointService::class,
		EventServiceInterface::class           => EventService::class,
		SynchronizationServiceInterface::class => SynchronizationService::class,
	];

	/**
	 * Each contract is aliased to its implementation, and nothing else is registered.
	 *
	 * @return void
	 *
	 * @spec exclude container wiring invariant, no requirement states which contracts exist
	 */
	public function testRegistersEachContractAlias(): void {
		$aliases = [];
		$context = $this->createMock(IRegistrationContext::class);
		$context->method('registerServiceAlias')->willReturnCallback(
			function (string $alias, string $target) use (&$aliases): void {
				$aliases[$alias] = $target;
			}
		);
		$context->expects($this->never())->method('registerService');

		(new ServiceRegistrar())->register(context: $context);

		$this->assertSame(self::EXPECTED, $aliases);
	}

	/**
	 * Every bound class implements its contract, so the alias resolves to a valid instance.
	 *
	 * @return void
	 *
	 * @spec exclude container wiring invariant, no requirement states which contracts exist
	 */
	public function testEachImplementationImplementsItsContract(): void {
		foreach (self::EXPECTED as $contract => $implementation) {
			$this->assertTrue(interface_exists($contract), $contract);
			$this->assertContains($contract, class_implements($implementation), $implementation);
		}
	}

	/**
	 * Constants other components read stay reachable through the class as well as the contract.
	 *
	 * @return void
	 *
	 * @spec exclude refactoring guard, the constant values are unchanged from before the contracts existed
	 */
	public function testMovedConstantsKeepTheirValuesOnTheClass(): void {
		$this->assertSame(0.10, SynchronizationService::DEFAULT_DELETION_RATIO_THRESHOLD);
		$this->assertSame('no_contract', SynchronizationService::DESTRUCTION_NO_CONTRACT);
		$this->assertSame(3, SynchronizationService::MIN_CONTRACTS_FOR_DELETION_RATIO_GUARD);
		$this->assertSame('/nextcloud/', EventService::NEXTCLOUD_SOURCE_PREFIX);
		$this->assertSame(5, (new \ReflectionClass(EventService::class))->getConstant('DEFAULT_MAX_RETRIES'));
	}
}

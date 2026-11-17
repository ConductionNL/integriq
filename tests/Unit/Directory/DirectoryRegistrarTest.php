<?php

/**
 * Unit tests for DirectoryRegistrar — each Directory contract is bound to the
 * class that implements it.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Directory
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Directory;

use OCA\Integriq\Directory\DirectoryRegistrar;
use OCA\Integriq\Directory\DirectorySource;
use OCA\Integriq\Directory\DirectorySourceInterface;
use OCA\Integriq\Directory\DirectorySyncService;
use OCA\Integriq\Directory\DirectorySyncServiceInterface;
use OCA\Integriq\Directory\ScimProvisioningService;
use OCA\Integriq\Directory\ScimProvisioningServiceInterface;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Tests for the Directory contract bindings.
 */
#[CoversClass(DirectoryRegistrar::class)]
class DirectoryRegistrarTest extends TestCase {

	/**
	 * The three contracts are aliased to their implementations, in order, and nothing else is registered.
	 *
	 * @return void
	 */
	public function testEachContractIsBoundToItsImplementation(): void {
		$context = $this->createMock(IRegistrationContext::class);
		$bound = [];
		$context->expects($this->exactly(3))->method('registerServiceAlias')->willReturnCallback(
			static function (string $alias, string $target) use (&$bound): void {
				$bound[] = [$alias, $target];
			}
		);
		$context->expects($this->never())->method('registerService');
		$context->expects($this->never())->method('registerEventListener');

		(new DirectoryRegistrar())->register(context: $context);

		$this->assertSame(
			[
				[DirectorySyncServiceInterface::class, DirectorySyncService::class],
				[DirectorySourceInterface::class, DirectorySource::class],
				[ScimProvisioningServiceInterface::class, ScimProvisioningService::class],
			],
			$bound
		);
	}//end testEachContractIsBoundToItsImplementation()
}//end class

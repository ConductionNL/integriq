<?php

/**
 * Unit tests for OsoProviderRegistry.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Service\Oso
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 *
 * @spec openspec/changes/integriq-adapter-oso/tasks.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Service\Oso;

use OCA\Integriq\Service\Oso\LogOsoProvider;
use OCA\Integriq\Service\Oso\OsoProviderRegistry;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Tests for the OSO export provider registry.
 *
 * @spec openspec/changes/integriq-adapter-oso/specs/oso-adapter/spec.md#requirement-req-001-oso-export-provider-abstraction-with-log-and-kennisnet-bindings
 */
class OsoProviderRegistryTest extends TestCase {

	/**
	 * An empty provider id resolves to the `log` binding.
	 *
	 * @return void
	 */
	public function testEmptyProviderIdResolvesToLog(): void {
		$logProvider = new LogOsoProvider();
		$registry = new OsoProviderRegistry([$logProvider]);

		$this->assertSame($logProvider, $registry->get(''));

	}//end testEmptyProviderIdResolvesToLog()

	/**
	 * has() reports whether a binding is registered.
	 *
	 * @return void
	 */
	public function testHasReportsRegisteredIds(): void {
		$registry = new OsoProviderRegistry([new LogOsoProvider()]);

		$this->assertTrue($registry->has('log'));
		$this->assertFalse($registry->has('kennisnet'));

	}//end testHasReportsRegisteredIds()

	/**
	 * An unknown provider id fails naming itself and the ids that do exist.
	 *
	 * @return void
	 */
	public function testUnknownProviderIdFailsNamingItselfAndKnownIds(): void {
		$registry = new OsoProviderRegistry([new LogOsoProvider()]);

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('No OSO provider is registered under "typo-kennisnet"');

		$registry->get('typo-kennisnet');

	}//end testUnknownProviderIdFailsNamingItselfAndKnownIds()

	/**
	 * ids() lists every registered provider id.
	 *
	 * @return void
	 */
	public function testIdsListsEveryRegisteredProvider(): void {
		$registry = new OsoProviderRegistry([new LogOsoProvider()]);
		$this->assertSame(['log'], $registry->ids());

	}//end testIdsListsEveryRegisteredProvider()
}//end class

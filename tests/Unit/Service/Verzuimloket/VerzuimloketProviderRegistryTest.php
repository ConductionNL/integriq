<?php

/**
 * Unit tests for VerzuimloketProviderRegistry.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Service\Verzuimloket
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 *
 * @spec openspec/changes/integriq-adapter-verzuimloket/tasks.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Service\Verzuimloket;

use OCA\Integriq\Service\Verzuimloket\LogVerzuimloketProvider;
use OCA\Integriq\Service\Verzuimloket\VerzuimloketProviderRegistry;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Tests for the Verzuimloket provider registry.
 *
 * @spec openspec/changes/integriq-adapter-verzuimloket/specs/verzuimloket-adapter/spec.md#requirement-req-001-verzuimloket-provider-abstraction-with-log-and-edukoppeling-bindings
 */
class VerzuimloketProviderRegistryTest extends TestCase {

	/**
	 * An empty provider id resolves to the `log` binding.
	 *
	 * @return void
	 */
	public function testEmptyProviderIdResolvesToLog(): void {
		$logProvider = new LogVerzuimloketProvider();
		$registry = new VerzuimloketProviderRegistry([$logProvider]);

		$this->assertSame($logProvider, $registry->get(''));

	}//end testEmptyProviderIdResolvesToLog()

	/**
	 * has() reports whether a binding is registered.
	 *
	 * @return void
	 */
	public function testHasReportsRegisteredIds(): void {
		$registry = new VerzuimloketProviderRegistry([new LogVerzuimloketProvider()]);

		$this->assertTrue($registry->has('log'));
		$this->assertFalse($registry->has('edukoppeling'));

	}//end testHasReportsRegisteredIds()

	/**
	 * An unknown provider id fails naming itself and the ids that do exist.
	 *
	 * @return void
	 */
	public function testUnknownProviderIdFailsNamingItselfAndKnownIds(): void {
		$registry = new VerzuimloketProviderRegistry([new LogVerzuimloketProvider()]);

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('No Verzuimloket provider is registered under "typo-edukoppeling"');

		$registry->get('typo-edukoppeling');

	}//end testUnknownProviderIdFailsNamingItselfAndKnownIds()

	/**
	 * ids() lists every registered provider id.
	 *
	 * @return void
	 */
	public function testIdsListsEveryRegisteredProvider(): void {
		$registry = new VerzuimloketProviderRegistry([new LogVerzuimloketProvider()]);
		$this->assertSame(['log'], $registry->ids());

	}//end testIdsListsEveryRegisteredProvider()
}//end class

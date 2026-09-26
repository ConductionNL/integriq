<?php

/**
 * Unit tests for RodProviderRegistry.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Service\Rod
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 *
 * @spec openspec/changes/integriq-adapter-rod/tasks.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Service\Rod;

use OCA\Integriq\Service\Rod\LogRodProvider;
use OCA\Integriq\Service\Rod\RodProviderRegistry;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Tests for the ROD provider registry.
 *
 * @spec openspec/changes/integriq-adapter-rod/specs/rod-adapter/spec.md#scenario-a-future-alternative-duo-compatible-transport-is-a-drop-in-binding
 */
class RodProviderRegistryTest extends TestCase {

	/**
	 * An empty provider id resolves to the `log` binding.
	 *
	 * @return void
	 */
	public function testEmptyProviderIdResolvesToLog(): void {
		$logProvider = new LogRodProvider();
		$registry = new RodProviderRegistry([$logProvider]);

		$this->assertSame($logProvider, $registry->get(''));

	}//end testEmptyProviderIdResolvesToLog()

	/**
	 * has() reports whether a binding is registered.
	 *
	 * @return void
	 */
	public function testHasReportsRegisteredIds(): void {
		$registry = new RodProviderRegistry([new LogRodProvider()]);

		$this->assertTrue($registry->has('log'));
		$this->assertFalse($registry->has('edukoppeling'));

	}//end testHasReportsRegisteredIds()

	/**
	 * An unknown provider id fails naming itself and the ids that do exist —
	 * never silently falls back to `log`.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/integriq-adapter-rod/specs/rod-adapter/spec.md#scenario-a-future-alternative-duo-compatible-transport-is-a-drop-in-binding
	 */
	public function testUnknownProviderIdFailsNamingItselfAndKnownIds(): void {
		$registry = new RodProviderRegistry([new LogRodProvider()]);

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('No ROD provider is registered under "typo-edukoppeling"');

		$registry->get('typo-edukoppeling');

	}//end testUnknownProviderIdFailsNamingItselfAndKnownIds()

	/**
	 * ids() lists every registered provider id.
	 *
	 * @return void
	 */
	public function testIdsListsEveryRegisteredProvider(): void {
		$registry = new RodProviderRegistry([new LogRodProvider()]);
		$this->assertSame(['log'], $registry->ids());

	}//end testIdsListsEveryRegisteredProvider()
}//end class

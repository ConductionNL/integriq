<?php

/**
 * Unit tests for UwlrEduVProviderRegistry.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Service\UwlrEduV
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 *
 * @spec openspec/changes/integriq-adapter-uwlr-eduv/tasks.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Service\UwlrEduV;

use OCA\Integriq\Service\UwlrEduV\LogUwlrEduVProvider;
use OCA\Integriq\Service\UwlrEduV\UwlrEduVProviderRegistry;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Tests for the UWLR/Edu-V provider registry.
 *
 * @spec openspec/changes/integriq-adapter-uwlr-eduv/specs/uwlr-eduv-adapter/spec.md#requirement-req-001-shared-provider-abstraction-with-log-and-uwlr-eduv-bindings
 */
class UwlrEduVProviderRegistryTest extends TestCase {

	/**
	 * An empty provider id resolves to the `log` binding.
	 *
	 * @return void
	 */
	public function testEmptyProviderIdResolvesToLog(): void {
		$logProvider = new LogUwlrEduVProvider();
		$registry = new UwlrEduVProviderRegistry([$logProvider]);

		$this->assertSame($logProvider, $registry->get(''));

	}//end testEmptyProviderIdResolvesToLog()

	/**
	 * has() reports whether a binding is registered.
	 *
	 * @return void
	 */
	public function testHasReportsRegisteredIds(): void {
		$registry = new UwlrEduVProviderRegistry([new LogUwlrEduVProvider()]);

		$this->assertTrue($registry->has('log'));
		$this->assertFalse($registry->has('uwlr-eduv'));

	}//end testHasReportsRegisteredIds()

	/**
	 * An unknown provider id fails naming itself and the ids that do exist.
	 *
	 * @return void
	 */
	public function testUnknownProviderIdFailsNamingItselfAndKnownIds(): void {
		$registry = new UwlrEduVProviderRegistry([new LogUwlrEduVProvider()]);

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('No UWLR/Edu-V provider is registered under "typo-uwlr-eduv"');

		$registry->get('typo-uwlr-eduv');

	}//end testUnknownProviderIdFailsNamingItselfAndKnownIds()

	/**
	 * ids() lists every registered provider id.
	 *
	 * @return void
	 */
	public function testIdsListsEveryRegisteredProvider(): void {
		$registry = new UwlrEduVProviderRegistry([new LogUwlrEduVProvider()]);
		$this->assertSame(['log'], $registry->ids());

	}//end testIdsListsEveryRegisteredProvider()
}//end class

<?php

/**
 * Unit tests for IdpAdapterRegistry: which adapter answers for a provider.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Auth\Idp
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 *
 * @spec openspec/specs/digid-eherkenning-auth-adapter/spec.md#requirement-dormant-seam-adapters-ship-config-flag-gated-and-inert
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Auth\Idp;

use OCA\Integriq\Auth\Idp\GovernmentIdpAdapterInterface;
use OCA\Integriq\Auth\Idp\IdpAdapterRegistry;
use OCA\Integriq\Auth\Idp\LogGovernmentIdpAdapter;
use OCA\Integriq\Exception\IdpAssertionException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * The bound adapter answers for its own provider, the dormant adapter for the
 * other known ones, and an unknown provider starts nothing.
 *
 * @covers \OCA\Integriq\Auth\Idp\IdpAdapterRegistry
 * @uses   \OCA\Integriq\Auth\Idp\LogGovernmentIdpAdapter
 */
class IdpAdapterRegistryTest extends TestCase {

	/**
	 * A registry whose bound adapter serves $bound.
	 *
	 * @param string $bound The bound adapter's provider id.
	 *
	 * @return array{0: IdpAdapterRegistry, 1: GovernmentIdpAdapterInterface} The registry and its bound adapter.
	 */
	private function registry(string $bound): array {
		$adapter = $this->createMock(originalClassName: GovernmentIdpAdapterInterface::class);
		$adapter->method('getProviderId')->willReturn($bound);

		return [new IdpAdapterRegistry(boundAdapter: $adapter, logger: $this->createMock(originalClassName: LoggerInterface::class)), $adapter];
	}//end registry()

	/**
	 * The bound adapter is returned for its own provider.
	 *
	 * @return void
	 */
	public function testTheBoundAdapterAnswersForItsProvider(): void {
		[$registry, $adapter] = $this->registry(bound: 'digid');

		$this->assertSame(expected: $adapter, actual: $registry->forProvider(provider: 'digid'));
	}//end testTheBoundAdapterAnswersForItsProvider()

	/**
	 * The other known providers.
	 *
	 * @return array<string, array{0: string}>
	 */
	public static function otherProviders(): array {
		return [
			'eherkenning' => ['eherkenning'],
			'eidas'       => ['eidas'],
		];
	}//end otherProviders()

	/**
	 * A known provider the bound adapter does not serve gets the dormant
	 * adapter for that provider, which is not configured.
	 *
	 * @param string $provider The provider.
	 *
	 * @return void
	 */
	#[DataProvider('otherProviders')]
	public function testAnotherKnownProviderGetsTheDormantAdapter(string $provider): void {
		[$registry] = $this->registry(bound: 'digid');

		$adapter = $registry->forProvider(provider: $provider);

		$this->assertInstanceOf(expected: LogGovernmentIdpAdapter::class, actual: $adapter);
		$this->assertSame(expected: $provider, actual: $adapter->getProviderId());
		$this->assertFalse(condition: $adapter->isConfigured());
	}//end testAnotherKnownProviderGetsTheDormantAdapter()

	/**
	 * An unknown provider is refused, even when the bound adapter claims it.
	 *
	 * @return void
	 */
	public function testAnUnknownProviderIsRefusedEvenIfTheBoundAdapterClaimsIt(): void {
		[$registry] = $this->registry(bound: 'itsme');

		$this->expectException(exception: IdpAssertionException::class);
		$this->expectExceptionMessage(message: 'Unknown identity provider, so no login starts.');
		$registry->forProvider(provider: 'itsme');
	}//end testAnUnknownProviderIsRefusedEvenIfTheBoundAdapterClaimsIt()

	/**
	 * Provider ids match exactly: a different case is unknown.
	 *
	 * @return void
	 */
	public function testProviderIdsMatchExactly(): void {
		[$registry] = $this->registry(bound: 'digid');

		$this->expectException(exception: IdpAssertionException::class);
		$registry->forProvider(provider: 'DigiD');
	}//end testProviderIdsMatchExactly()
}//end class

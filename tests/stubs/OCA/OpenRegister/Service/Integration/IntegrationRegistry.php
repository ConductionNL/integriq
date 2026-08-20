<?php

/**
 * Stub for OCA\OpenRegister\Service\Integration\IntegrationRegistry.
 *
 * Only declares the methods CatalogRegistryService actually calls
 * (list()). Concrete behaviour (addProvider validation, page widgets) is
 * intentionally omitted — this stub exists purely so PHPUnit's mock
 * builder / real-instance tests can construct/mock the class without a
 * live OpenRegister install.
 *
 * @category Test
 * @package  OCA\OpenConnector\Tests\Stubs
 * @license  EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Integration;

/**
 * Minimal stub for OCA\OpenRegister\Service\Integration\IntegrationRegistry.
 */
class IntegrationRegistry {
	/**
	 * @var array<int, IntegrationProvider>
	 */
	private array $providers = [];

	/**
	 * Replace the entire provider set (test seam, mirrors the real class).
	 *
	 * @param array<int, IntegrationProvider> $providers Provider instances.
	 *
	 * @return void
	 */
	public function withProviders(array $providers): void {
		$this->providers = $providers;
	}

	/**
	 * List every registered provider.
	 *
	 * @return array<int, IntegrationProvider>
	 */
	public function list(): array {
		return $this->providers;
	}
}

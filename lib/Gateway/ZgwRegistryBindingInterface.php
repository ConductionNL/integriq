<?php

/**
 * Contract of the ZgwRegistryBinding.
 *
 * @category Contract
 * @package  OCA\Integriq\Gateway
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

namespace OCA\Integriq\Gateway;

/**
 * Public operations of {@see ZgwRegistryBinding} that other components call.
 */
interface ZgwRegistryBindingInterface {
	/**
	 * The configured binding.
	 *
	 * @return array<string,mixed> The binding, defaulting to OpenRegister.
	 *
	 * @spec exclude declaration only, the behaviour and its spec live on ZgwRegistryBinding::current()
	 */
	public function current(): array;

	/**
	 * Whether the binding has passed its reachability test.
	 *
	 * @return bool True when it is marked usable.
	 *
	 * @spec exclude declaration only, the behaviour and its spec live on ZgwRegistryBinding::isUsable()
	 */
	public function isUsable(): bool;

	/**
	 * Test a binding's reachability, and mark it usable only when it answers.
	 *
	 * @param array<string,mixed>|null $binding The binding to test, or null for the configured one.
	 *
	 * @return array{ok:bool,message:string,binding:array<string,mixed>} The verdict.
	 *
	 * @spec exclude declaration only, the behaviour and its spec live on ZgwRegistryBinding::test()
	 */
	public function test(?array $binding = null): array;
}

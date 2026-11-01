<?php

/**
 * Contract of the DigitalPostProviderRegistry.
 *
 * @category Contract
 * @package  OCA\Integriq\Service\DigitalPost
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

namespace OCA\Integriq\Service\DigitalPost;

use RuntimeException;

/**
 * Public operations of {@see DigitalPostProviderRegistry} that other components call.
 */
interface DigitalPostProviderRegistryInterface {
	/**
	 * Whether a binding answers to this provider id.
	 *
	 * @param string $providerId Provider id.
	 *
	 * @return bool True when one is registered.
	 *
	 * @spec openspec/changes/berichtenbox-digital-post-adapter/specs/digital-post-adapter/spec.md
	 */
	public function has(string $providerId): bool;

	/**
	 * The binding for a provider id.
	 *
	 * @param string $providerId Provider id.
	 *
	 * @return DigitalPostProviderInterface The binding.
	 *
	 * @throws RuntimeException When nothing answers to the id.
	 *
	 * @spec openspec/changes/berichtenbox-digital-post-adapter/specs/digital-post-adapter/spec.md
	 */
	public function get(string $providerId): DigitalPostProviderInterface;

	/**
	 * Every registered provider id.
	 *
	 * @return array<int,string> Provider ids.
	 *
	 * @spec openspec/changes/berichtenbox-digital-post-adapter/specs/digital-post-adapter/spec.md
	 */
	public function ids(): array;

	/**
	 * Every binding with the configuration it needs.
	 *
	 * @return array<int,array<string,mixed>> The bindings.
	 *
	 * @spec openspec/changes/berichtenbox-digital-post-adapter/specs/digital-post-adapter/spec.md
	 */
	public function describeAll(): array;
}

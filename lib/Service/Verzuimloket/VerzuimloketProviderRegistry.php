<?php

/**
 * The Verzuimloket bindings this instance ships.
 *
 * @category Registry
 * @package  OCA\Integriq\Service\Verzuimloket
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://www.integriq.nl
 */

declare(strict_types=1);

namespace OCA\Integriq\Service\Verzuimloket;

use OCA\Integriq\Service\Helper\ProviderIndex;
use OCA\Integriq\Service\Helper\ProviderIds;
use RuntimeException;

/**
 * Resolved by `providerId` from the Verzuimloket source's
 * `configuration.provider`. A provider id nothing answers to fails naming
 * itself and the ids that do exist (mirrors RodProviderRegistry).
 *
 * @spec openspec/specs/verzuimloket-adapter/spec.md#requirement-req-001-verzuimloket-provider-abstraction-with-log-and-edukoppeling-bindings
 */
class VerzuimloketProviderRegistry {
	/**
	 * Bindings keyed by provider id.
	 *
	 * @var ProviderIndex<VerzuimloketProviderInterface>
	 */
	private readonly ProviderIndex $index;

	/**
	 * Constructor.
	 *
	 * @param iterable<VerzuimloketProviderInterface> $providers The bindings.
	 */
	public function __construct(iterable $providers = []) {
		$this->index = new ProviderIndex(kind: 'Verzuimloket', providers: $providers);
	}//end __construct()

	/**
	 * Whether a binding answers to this provider id.
	 *
	 * @param string $providerId Provider id.
	 *
	 * @return bool True when one is registered.
	 *
	 * @spec openspec/specs/verzuimloket-adapter/spec.md#requirement-req-001-verzuimloket-provider-abstraction-with-log-and-edukoppeling-bindings
	 */
	public function has(string $providerId): bool {
		return $this->index->has(providerId: $providerId);
	}//end has()

	/**
	 * The binding for a provider id, defaulting to `log` when none is given.
	 *
	 * @param string $providerId Provider id (empty string resolves to `log`).
	 *
	 * @return VerzuimloketProviderInterface The binding.
	 *
	 * @throws RuntimeException When nothing answers to a non-empty, unrecognised id.
	 *
	 * @spec openspec/specs/verzuimloket-adapter/spec.md#requirement-req-001-verzuimloket-provider-abstraction-with-log-and-edukoppeling-bindings
	 */
	public function get(string $providerId): VerzuimloketProviderInterface {
		return $this->index->get(providerId: ProviderIds::orLog(providerId: $providerId));
	}//end get()

	/**
	 * Every registered provider id.
	 *
	 * @return array<int,string> Provider ids.
	 *
	 * @spec openspec/specs/verzuimloket-adapter/spec.md#requirement-req-001-verzuimloket-provider-abstraction-with-log-and-edukoppeling-bindings
	 */
	public function ids(): array {
		return $this->index->ids();
	}//end ids()
}//end class

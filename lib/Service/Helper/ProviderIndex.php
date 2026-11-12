<?php

/**
 * Integriq provider index.
 *
 * The lookup every adapter's provider registry shares: the bindings keyed by
 * provider id, the first binding winning a duplicate id, and the refusal
 * that names the asked id and the registered ones when nothing answers.
 *
 * @category Service
 * @package  OCA\Integriq\Service\Helper
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.Integriq.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Integriq\Service\Helper;

use OCA\Integriq\Service\DigitalPost\DigitalPostProviderInterface;
use OCA\Integriq\Service\Oso\OsoProviderInterface;
use OCA\Integriq\Service\Rod\RodProviderInterface;
use OCA\Integriq\Service\UwlrEduV\UwlrEduVProviderInterface;
use OCA\Integriq\Service\Verzuimloket\VerzuimloketProviderInterface;
use RuntimeException;

/**
 * Provider bindings keyed by provider id, for one adapter's registry.
 *
 * @template T of DigitalPostProviderInterface|OsoProviderInterface|RodProviderInterface|UwlrEduVProviderInterface|VerzuimloketProviderInterface
 *
 * @spec openspec/specs/rod-adapter/spec.md#requirement-req-001-rod-provider-abstraction-with-log-and-edukoppeling-bindings
 * @spec openspec/changes/berichtenbox-digital-post-adapter/specs/digital-post-adapter/spec.md#requirement-one-provider-seam-with-log-berichtenbox-and-postex-bindings-req-dpa-001
 */
final class ProviderIndex {
	/**
	 * Bindings keyed by provider id.
	 *
	 * @var array<string,T>
	 */
	private array $providers = [];

	/**
	 * Constructor.
	 *
	 * @param string      $kind      How a refusal names the provider kind, e.g. `ROD` or `digital post`.
	 * @param iterable<T> $providers The bindings; the first one wins a duplicate id.
	 */
	public function __construct(
		private readonly string $kind,
		iterable $providers,
	) {
		foreach ($providers as $provider) {
			if (isset($this->providers[$provider->getProviderId()]) === true) {
				continue;
			}

			$this->providers[$provider->getProviderId()] = $provider;
		}
	}//end __construct()

	/**
	 * Whether a binding answers to this provider id.
	 *
	 * @param string $providerId Provider id.
	 *
	 * @return bool True when one is registered.
	 *
	 * @spec openspec/specs/rod-adapter/spec.md#requirement-req-001-rod-provider-abstraction-with-log-and-edukoppeling-bindings
	 */
	public function has(string $providerId): bool {
		return isset($this->providers[$providerId]);
	}//end has()

	/**
	 * The binding for a provider id.
	 *
	 * @param string $providerId Provider id, as the registry resolved it.
	 *
	 * @return T The binding.
	 *
	 * @throws RuntimeException When no binding answers to the id, naming the registered ones.
	 *
	 * @spec openspec/specs/rod-adapter/spec.md#requirement-req-001-rod-provider-abstraction-with-log-and-edukoppeling-bindings
	 */
	public function get(string $providerId): object {
		if (isset($this->providers[$providerId]) === false) {
			throw new RuntimeException(
				sprintf(
					'No %s provider is registered under "%s". Registered providers: %s. Nothing was sent.',
					$this->kind,
					$providerId,
					ProviderIds::listed(ids: $this->ids())
				)
			);
		}

		return $this->providers[$providerId];
	}//end get()

	/**
	 * Registered provider ids.
	 *
	 * @return array<int,string> Ids.
	 *
	 * @spec openspec/specs/rod-adapter/spec.md#requirement-req-001-rod-provider-abstraction-with-log-and-edukoppeling-bindings
	 */
	public function ids(): array {
		return array_keys($this->providers);
	}//end ids()
}//end class

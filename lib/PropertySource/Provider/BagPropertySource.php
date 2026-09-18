<?php

/**
 * BAG addresses, bound over the PDOK Locatieserver connector.
 *
 * @category Provider
 * @package  OCA\Integriq\PropertySource\Provider
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

namespace OCA\Integriq\PropertySource\Provider;

use OCA\Integriq\PropertySource\Exception\SourceUnreachableException;
use OCA\Integriq\PropertySource\PropertySourceProviderInterface;
use OCA\Integriq\Adapters\Pdok\PdokGeocodingClient;
use Throwable;

/**
 * No new client: the PDOK connector already normalises the Locatieserver into
 * the canonical address shape, so this binding only adapts its calls.
 *
 * @spec openspec/changes/registry-backed-field-source/specs/registry-field-source/spec.md#requirement-bag-brp-and-kvk-bind-to-sources-that-already-exist-req-rfs-006
 */
class BagPropertySource implements PropertySourceProviderInterface {
	/**
	 * Provider id a schema property names.
	 */
	public const ID = 'bag';

	/**
	 * A BAG address is stable, so an hour is a defensible budget.
	 */
	public const STALENESS_BUDGET = 3600;

	/**
	 * Constructor.
	 *
	 * @param PdokGeocodingClient $geocoding The shipped PDOK Locatieserver client.
	 */
	public function __construct(private readonly PdokGeocodingClient $geocoding) {
	}//end __construct()

	/**
	 * The provider id.
	 *
	 * @return string Provider id.
	 */
	public function id(): string {
		return self::ID;
	}//end id()

	/**
	 * Address type-ahead while somebody is typing.
	 *
	 * @param string $query Partial address.
	 * @param array<string,mixed> $config Declared configuration; `rows` caps the result.
	 *
	 * @return array<int,array<string,mixed>> Suggestions with `identifier` and `label`.
	 */
	public function suggest(string $query, array $config = []): array {
		$rows = (int)($config['rows'] ?? 10);

		try {
			$hits = $this->geocoding->suggest($query, $rows);
		} catch (Throwable $e) {
			throw new SourceUnreachableException(self::ID, 'The PDOK Locatieserver did not answer a suggest: ' . $e->getMessage());
		}

		$out = [];
		foreach ($hits as $hit) {
			$identifier = (string)($hit['id'] ?? $hit['identifier'] ?? '');
			if ($identifier === '') {
				continue;
			}

			$out[] = [
				'identifier' => $identifier,
				'label' => (string)($hit['weergavenaam'] ?? $hit['label'] ?? $identifier),
			];
		}

		return $out;
	}//end suggest()

	/**
	 * One authoritative address read by its BAG object id.
	 *
	 * @param string $identifier PDOK Locatieserver id.
	 * @param array<string,mixed> $config Declared configuration.
	 *
	 * @return array<string,mixed> Canonical PostalAddress.
	 *
	 * @throws SourceUnreachableException When PDOK does not answer or knows no such id.
	 */
	public function resolve(string $identifier, array $config = []): array {
		try {
			$address = $this->geocoding->lookup($identifier);
		} catch (Throwable $e) {
			throw new SourceUnreachableException(self::ID, 'The PDOK Locatieserver did not answer a lookup: ' . $e->getMessage());
		}

		if (is_array($address) === false) {
			throw new SourceUnreachableException(self::ID, sprintf('The PDOK Locatieserver returned no address for "%s".', $identifier));
		}

		return $address;
	}//end resolve()

	/**
	 * What this binding keys on and how fresh it keeps a value.
	 *
	 * @return array{id:string,label:string,identifier:string,stalenessBudget:int,listShaped:bool}
	 */
	public function describe(): array {
		return [
			'id' => self::ID,
			'label' => 'BAG address',
			'identifier' => 'pdokId',
			'stalenessBudget' => self::STALENESS_BUDGET,
			'listShaped' => false,
		];
	}//end describe()
}//end class

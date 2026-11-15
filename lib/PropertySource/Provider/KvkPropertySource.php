<?php

/**
 * KvK organisation values, bound over the seeded kvk source.
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
use OCA\Integriq\PropertySource\RegistrySourceGateway;

/**
 * A binding over a source that already exists, not a new client.
 *
 * @spec openspec/specs/registry-field-source/spec.md#requirement-bag-brp-and-kvk-bind-to-sources-that-already-exist-req-rfs-006
 */
class KvkPropertySource implements PropertySourceProviderInterface {
	/**
	 * Provider id a schema property names.
	 */
	public const ID = 'kvk';

	/**
	 * Slug of the seeded source this binding reads through.
	 */
	public const SOURCE_SLUG = 'kvk';

	/**
	 * Staleness budget in seconds.
	 */
	public const STALENESS_BUDGET = 86400;

	/**
	 * Constructor.
	 *
	 * @param RegistrySourceGateway $gateway The one way to reach a source.
	 */
	public function __construct(private readonly RegistrySourceGateway $gateway) {
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
	 * Type-ahead over the registry: KvK Zoeken by name.
	 *
	 * @param string $query Partial query.
	 * @param array<string,mixed> $config Declared configuration; `source` overrides the slug.
	 *
	 * @return array<int,array<string,mixed>> Suggestions with `identifier` and `label`.
	 *
	 * @spec openspec/changes/registry-bindings-speak-the-registry-api/specs/registry-field-source/spec.md#requirement-a-registry-binding-asks-the-way-its-registry-answers
	 */
	public function suggest(string $query, array $config = []): array {
		$body = $this->gateway->read(
			self::ID,
			(string)($config['source'] ?? self::SOURCE_SLUG),
			'/zoeken',
			['naam' => $query]
		);

		$out = [];
		foreach ((array)($body['resultaten'] ?? []) as $hit) {
			if (is_array($hit) === false) {
				continue;
			}

			$identifier = (string)($hit['kvkNummer'] ?? '');
			if ($identifier === '') {
				continue;
			}

			$out[] = [
				'identifier' => $identifier,
				'label' => (string)($hit['naam'] ?? $identifier),
			];
		}

		return $out;
	}//end suggest()

	/**
	 * One authoritative read by the registry's own identifier.
	 *
	 * KvK Zoeken answers a list of registrations under the number (the head
	 * office, branches, the legal person). The record is the head office,
	 * which carries the address; without one, the first row of that number.
	 *
	 * @param string $identifier The kvkNummer.
	 * @param array<string,mixed> $config Declared configuration; `source` overrides the slug.
	 *
	 * @return array<string,mixed> The record as the registry holds it.
	 *
	 * @throws SourceUnreachableException When the registry knows no such identifier.
	 *
	 * @spec openspec/changes/registry-bindings-speak-the-registry-api/specs/registry-field-source/spec.md#requirement-a-registry-binding-asks-the-way-its-registry-answers
	 */
	public function resolve(string $identifier, array $config = []): array {
		$body = $this->gateway->read(
			self::ID,
			(string)($config['source'] ?? self::SOURCE_SLUG),
			'/zoeken',
			['kvkNummer' => $identifier]
		);

		$record = null;
		foreach ((array)($body['resultaten'] ?? []) as $row) {
			if (is_array($row) === false || (string)($row['kvkNummer'] ?? '') !== $identifier) {
				continue;
			}

			if (($row['type'] ?? '') === 'hoofdvestiging') {
				return $row;
			}

			$record ??= $row;
		}

		if ($record === null) {
			throw new SourceUnreachableException(
				providerId: self::ID,
				message: sprintf('The kvk registry returned no record for "%s".', $identifier)
			);
		}

		return $record;
	}//end resolve()

	/**
	 * What this binding keys on and how fresh it keeps a value.
	 *
	 * @return array{id:string,label:string,identifier:string,stalenessBudget:int,listShaped:bool}
	 */
	public function describe(): array {
		return [
			'id' => self::ID,
			'label' => 'KvK organisation',
			'identifier' => 'kvkNummer',
			'stalenessBudget' => self::STALENESS_BUDGET,
			'listShaped' => false,
		];
	}//end describe()
}//end class

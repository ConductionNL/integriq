<?php

/**
 * BRP person values, bound over the seeded brp-haalcentraal source.
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
class BrpPropertySource implements PropertySourceProviderInterface {
	/**
	 * Provider id a schema property names.
	 */
	public const ID = 'brp';

	/**
	 * Slug of the seeded source this binding reads through.
	 */
	public const SOURCE_SLUG = 'brp-haalcentraal';

	/**
	 * Staleness budget in seconds.
	 */
	public const STALENESS_BUDGET = 900;

	/**
	 * The fields asked for when a property declares none.
	 *
	 * @var array<int, string>
	 */
	public const DEFAULT_FIELDS = ['burgerservicenummer', 'naam', 'geboorte', 'verblijfplaats', 'geheimhoudingPersoonsgegevens'];

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
	 * Type-ahead over the registry.
	 *
	 * Haal Centraal BRP has no free search, by design. A whole
	 * burgerservicenummer suggests its one person; anything else suggests
	 * nothing and asks the registry nothing.
	 *
	 * @param string $query Partial query.
	 * @param array<string,mixed> $config Declared configuration; `source` overrides the slug.
	 *
	 * @return array<int,array<string,mixed>> Suggestions with `identifier` and `label`.
	 *
	 * @spec openspec/changes/registry-bindings-speak-the-registry-api/specs/registry-field-source/spec.md#requirement-a-registry-binding-asks-the-way-its-registry-answers
	 */
	public function suggest(string $query, array $config = []): array {
		$query = trim($query);
		if (preg_match('/^\d{9}$/', $query) !== 1) {
			return [];
		}

		try {
			$person = $this->resolve(identifier: $query, config: $config);
		} catch (SourceUnreachableException) {
			return [];
		}

		$name = '';
		if (is_array($person['naam'] ?? null) === true) {
			$name = (string)($person['naam']['volledigeNaam'] ?? $person['naam']['geslachtsnaam'] ?? '');
		}

		if ($name === '') {
			$name = $query;
		}

		return [['identifier' => $query, 'label' => $name]];
	}//end suggest()

	/**
	 * One authoritative read by the registry's own identifier.
	 *
	 * Asked the way Haal Centraal BRP Personen v2 takes it: a POST to
	 * `/personen` of type RaadpleegMetBurgerservicenummer, so the number
	 * travels in the body and never in the URL. The answer is an envelope
	 * with a `personen` list; the record is its first person.
	 *
	 * @param string $identifier The burgerservicenummer.
	 * @param array<string,mixed> $config Declared configuration; `source` overrides the slug, `fields`
	 *                                    the fields asked for (a municipality's authorisation decides
	 *                                    which it may ask).
	 *
	 * @return array<string,mixed> The person as the registry holds it.
	 *
	 * @throws SourceUnreachableException When the registry knows no such identifier.
	 *
	 * @spec openspec/changes/registry-bindings-speak-the-registry-api/specs/registry-field-source/spec.md#requirement-a-registry-binding-asks-the-way-its-registry-answers
	 */
	public function resolve(string $identifier, array $config = []): array {
		$fields = self::DEFAULT_FIELDS;
		if (is_array($config['fields'] ?? null) === true && $config['fields'] !== []) {
			$fields = array_values(array_map('strval', $config['fields']));
		}

		$body = $this->gateway->read(
			self::ID,
			(string)($config['source'] ?? self::SOURCE_SLUG),
			'/personen',
			[],
			[
				'type' => 'RaadpleegMetBurgerservicenummer',
				'burgerservicenummer' => [$identifier],
				'fields' => $fields,
			]
		);

		$person = null;
		foreach ((array)($body['personen'] ?? []) as $row) {
			if (is_array($row) === true) {
				$person = $row;
				break;
			}
		}

		if ($person === null) {
			throw new SourceUnreachableException(
				providerId: self::ID,
				message: 'The brp registry returned no record for the burgerservicenummer asked.'
			);
		}

		return $person;
	}//end resolve()

	/**
	 * What this binding keys on and how fresh it keeps a value.
	 *
	 * @return array{id:string,label:string,identifier:string,stalenessBudget:int,listShaped:bool}
	 */
	public function describe(): array {
		return [
			'id' => self::ID,
			'label' => 'BRP person',
			'identifier' => 'burgerservicenummer',
			'stalenessBudget' => self::STALENESS_BUDGET,
			'listShaped' => false,
		];
	}//end describe()
}//end class

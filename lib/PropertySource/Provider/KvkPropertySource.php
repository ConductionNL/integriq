<?php

/**
 * KvK organisation values, bound over the seeded kvk-api source.
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
 * @spec openspec/changes/registry-backed-field-source/specs/registry-field-source/spec.md#requirement-bag-brp-and-kvk-bind-to-sources-that-already-exist-req-rfs-006
 */
class KvkPropertySource implements PropertySourceProviderInterface {
	/**
	 * Provider id a schema property names.
	 */
	public const ID = 'kvk';

	/**
	 * Slug of the seeded source this binding reads through.
	 */
	public const SOURCE_SLUG = 'kvk-api';

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
	 * Type-ahead over the registry.
	 *
	 * @param string $query Partial query.
	 * @param array<string,mixed> $config Declared configuration; `source` overrides the slug.
	 *
	 * @return array<int,array<string,mixed>> Suggestions with `identifier` and `label`.
	 */
	public function suggest(string $query, array $config = []): array {
		$body = $this->gateway->read(
			self::ID,
			(string)($config['source'] ?? self::SOURCE_SLUG),
			'/kvk/suggest',
			['q' => $query]
		);

		$out = [];
		foreach (($body['results'] ?? $body['_embedded'] ?? []) as $hit) {
			if (is_array($hit) === false) {
				continue;
			}

			$identifier = (string)($hit['kvkNummer'] ?? $hit['identifier'] ?? '');
			if ($identifier === '') {
				continue;
			}

			$out[] = [
				'identifier' => $identifier,
				'label' => (string)($hit['label'] ?? $hit['naam'] ?? $identifier),
			];
		}

		return $out;
	}//end suggest()

	/**
	 * One authoritative read by the registry's own identifier.
	 *
	 * @param string $identifier The kvkNummer.
	 * @param array<string,mixed> $config Declared configuration; `source` overrides the slug.
	 *
	 * @return array<string,mixed> The record as the registry holds it.
	 *
	 * @throws SourceUnreachableException When the registry knows no such identifier.
	 */
	public function resolve(string $identifier, array $config = []): array {
		$body = $this->gateway->read(
			self::ID,
			(string)($config['source'] ?? self::SOURCE_SLUG),
			'/kvk/' . rawurlencode($identifier),
			[]
		);

		if (($body['kvkNummer'] ?? null) === null && ($body['identifier'] ?? null) === null) {
			throw new SourceUnreachableException(
				self::ID,
				sprintf('The kvk registry returned no record for "%s".', $identifier)
			);
		}

		return $body;
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

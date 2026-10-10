<?php

/**
 * KvK subscriptions through the mutatieservice.
 *
 * @category Provider
 * @package  OCA\Integriq\Service\Registry
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

namespace OCA\Integriq\Service\Registry;

/**
 * The KvK mutatieservice reports what changed about a registered company. The
 * shape is the same as the BRP binding's: subscribe, unsubscribe, poll.
 *
 * @spec openspec/specs/registry-subscription-connector/spec.md#requirement-a-subscription-provider-per-registry-req-rsc-001
 */
class KvkMutatieProvider extends AbstractSourceSubscriptionProvider implements MapsSourceFieldsInterface {
	/**
	 * Registry id this binding answers to.
	 */
	public const REGISTRY_ID = 'kvk';

	/**
	 * Slug of the seeded mutatieservice source.
	 */
	public const SOURCE_SLUG = 'kvk-mutatieservice';

	/**
	 * Source field to property, per target schema slug (decision 178).
	 *
	 * @var array<string,array<string,string>>
	 */
	public const FIELD_MAPS = [
		// Dossiq's company. The mutatieservice and Zoeken both name the trade
		// name and the address two ways; either lands on the one property.
		'kvkCompany' => [
			'handelsnaam' => 'tradeName',
			'naam' => 'tradeName',
			'rechtsvorm' => 'legalForm',
			'adres' => 'address',
			'bezoekadres' => 'address',
		],
	];

	/**
	 * The registry id.
	 *
	 * @return string Registry id.
	 *
	 * @spec openspec/specs/registry-subscription-connector/spec.md
	 */
	public function registryId(): string {
		return self::REGISTRY_ID;
	}//end registryId()

	/**
	 * The seeded source this binding works through.
	 *
	 * @return string Source slug.
	 *
	 * @spec openspec/specs/registry-subscription-connector/spec.md
	 */
	protected function sourceSlug(): string {
		return self::SOURCE_SLUG;
	}//end sourceSlug()

	/**
	 * Register one KvK number with the mutatieservice.
	 *
	 * @param string $identity The KvK number.
	 *
	 * @return SubscriptionResult Active, or failed with the source's error text.
	 *
	 * @spec openspec/specs/registry-subscription-connector/spec.md
	 */
	public function subscribe(string $identity): SubscriptionResult {
		$outcome = $this->callSource(
			endpoint: '/abonnementen',
			method: 'POST',
			config: ['body' => ['kvkNummer' => $identity]]
		);

		if ($outcome['error'] !== '') {
			return SubscriptionResult::failed($identity, $outcome['error']);
		}

		return SubscriptionResult::active($identity, (string)($outcome['body']['abonnementId'] ?? ''));
	}//end subscribe()

	/**
	 * End the subscription for one KvK number.
	 *
	 * @param string $identity The KvK number.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/registry-subscription-connector/spec.md
	 */
	public function unsubscribe(string $identity): void {
		$this->callSource(endpoint: '/abonnementen/' . rawurlencode($identity), method: 'DELETE');
	}//end unsubscribe()

	/**
	 * Ask the mutatieservice what changed.
	 *
	 * @param array<int,string> $identities KvK numbers with an active subscription.
	 *
	 * @return iterable<SubscriptionChange> The changes.
	 *
	 * @spec openspec/specs/registry-subscription-connector/spec.md
	 */
	public function pollChanges(array $identities = []): iterable {
		if ($identities === []) {
			return [];
		}

		$outcome = $this->callSource(
			endpoint: '/mutaties',
			method: 'GET',
			config: ['query' => ['kvkNummer' => implode(',', $identities)]]
		);

		if ($outcome['error'] !== '') {
			$this->logger->warning('registry-subscription.kvk.poll-failed', ['error' => $outcome['error']]);
			return [];
		}

		$changes = [];
		foreach (($outcome['body']['mutaties'] ?? $outcome['body']['results'] ?? []) as $row) {
			if (is_array($row) === false) {
				continue;
			}

			$kvk = (string)($row['kvkNummer'] ?? '');
			$properties = ($row['gewijzigd'] ?? []);
			if ($kvk === '' || is_array($properties) === false || $properties === []) {
				continue;
			}

			$changes[] = new SubscriptionChange($kvk, $properties, (string)($row['mutatieId'] ?? $row['eventReference'] ?? ''));
		}

		return $changes;
	}//end pollChanges()

	/**
	 * Which source field becomes which property of the target schema.
	 *
	 * @param string $targetSchema The target schema's slug.
	 *
	 * @return array<string,string>|null The map, or null when this binding has none for that schema.
	 *
	 * @spec openspec/changes/registry-update-maps-source-fields/specs/registry-subscription-connector/spec.md#requirement-a-change-is-posted-in-each-target-schemas-own-property-names-req-rsc-004
	 */
	public function fieldMapFor(string $targetSchema): ?array {
		return (self::FIELD_MAPS[$targetSchema] ?? null);
	}//end fieldMapFor()
}//end class

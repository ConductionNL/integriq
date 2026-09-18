<?php

/**
 * BRP subscriptions through Haal Centraal volgindicaties.
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
 * A volgindicatie asks the BRP to keep telling us about one person. A BRP
 * source without that contract refuses, and the refusal is reported in the
 * source's own words.
 *
 * @spec openspec/changes/registry-subscription-connector/specs/registry-subscription-connector/spec.md#requirement-a-subscription-provider-per-registry-req-rsc-001
 */
class BrpVolgindicatieProvider extends AbstractSourceSubscriptionProvider {
	/**
	 * Registry id this binding answers to.
	 */
	public const REGISTRY_ID = 'brp';

	/**
	 * Slug of the seeded Haal Centraal source.
	 */
	public const SOURCE_SLUG = 'brp-haalcentraal';

	/**
	 * The registry id.
	 *
	 * @return string Registry id.
	 */
	public function registryId(): string {
		return self::REGISTRY_ID;
	}//end registryId()

	/**
	 * The seeded source this binding works through.
	 *
	 * @return string Source slug.
	 */
	protected function sourceSlug(): string {
		return self::SOURCE_SLUG;
	}//end sourceSlug()

	/**
	 * Place a volgindicatie on one BSN.
	 *
	 * @param string $identity The BSN.
	 *
	 * @return SubscriptionResult Active, or failed with the source's error text.
	 */
	public function subscribe(string $identity): SubscriptionResult {
		$outcome = $this->callSource(
			'/ingeschrevenpersonen/' . rawurlencode($identity) . '/volgindicaties',
			'PUT'
		);

		if ($outcome['error'] !== '') {
			return SubscriptionResult::failed($identity, $outcome['error']);
		}

		return SubscriptionResult::active($identity, (string)($outcome['body']['volgindicatieId'] ?? ''));
	}//end subscribe()

	/**
	 * Remove the volgindicatie from one BSN.
	 *
	 * @param string $identity The BSN.
	 *
	 * @return void
	 */
	public function unsubscribe(string $identity): void {
		$this->callSource(
			'/ingeschrevenpersonen/' . rawurlencode($identity) . '/volgindicaties',
			'DELETE'
		);
	}//end unsubscribe()

	/**
	 * Ask the BRP what changed for the BSNs we follow.
	 *
	 * @param array<int,string> $identities BSNs with an active subscription.
	 *
	 * @return iterable<SubscriptionChange> The changes.
	 */
	public function pollChanges(array $identities = []): iterable {
		if ($identities === []) {
			return [];
		}

		$outcome = $this->callSource(
			'/ingeschrevenpersonen',
			'GET',
			['query' => ['volgindicatie' => 'true', 'burgerservicenummer' => implode(',', $identities)]]
		);

		if ($outcome['error'] !== '') {
			$this->logger->warning(
				'registry-subscription.brp.poll-failed',
				['error' => $outcome['error']]
			);
			return [];
		}

		$changes = [];
		foreach (($outcome['body']['_embedded']['ingeschrevenpersonen'] ?? $outcome['body']['results'] ?? []) as $row) {
			if (is_array($row) === false) {
				continue;
			}

			$bsn = (string)($row['burgerservicenummer'] ?? '');
			$properties = ($row['gewijzigd'] ?? []);
			if ($bsn === '' || is_array($properties) === false || $properties === []) {
				continue;
			}

			$changes[] = new SubscriptionChange($bsn, $properties, (string)($row['volgindicatieId'] ?? $row['eventReference'] ?? ''));
		}

		return $changes;
	}//end pollChanges()
}//end class

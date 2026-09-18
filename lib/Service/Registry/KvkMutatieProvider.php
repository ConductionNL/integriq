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
 * @spec openspec/changes/registry-subscription-connector/specs/registry-subscription-connector/spec.md#requirement-a-subscription-provider-per-registry-req-rsc-001
 */
class KvkMutatieProvider extends AbstractSourceSubscriptionProvider {
	/**
	 * Registry id this binding answers to.
	 */
	public const REGISTRY_ID = 'kvk';

	/**
	 * Slug of the seeded mutatieservice source.
	 */
	public const SOURCE_SLUG = 'kvk-mutatieservice';

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
	 * Register one KvK number with the mutatieservice.
	 *
	 * @param string $identity The KvK number.
	 *
	 * @return SubscriptionResult Active, or failed with the source's error text.
	 */
	public function subscribe(string $identity): SubscriptionResult {
		$outcome = $this->callSource(
			'/abonnementen',
			'POST',
			['body' => ['kvkNummer' => $identity]]
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
	 */
	public function unsubscribe(string $identity): void {
		$this->callSource('/abonnementen/' . rawurlencode($identity), 'DELETE');
	}//end unsubscribe()

	/**
	 * Ask the mutatieservice what changed.
	 *
	 * @param array<int,string> $identities KvK numbers with an active subscription.
	 *
	 * @return iterable<SubscriptionChange> The changes.
	 */
	public function pollChanges(array $identities = []): iterable {
		if ($identities === []) {
			return [];
		}

		$outcome = $this->callSource(
			'/mutaties',
			'GET',
			['query' => ['kvkNummer' => implode(',', $identities)]]
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
}//end class

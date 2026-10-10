<?php

/**
 * Which identities integriq currently follows, and nothing about them.
 *
 * @category Service
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

use OCP\IAppConfig;

/**
 * The poll job has to know whom to ask about. It holds identity values and
 * subscription references only: the person or the company stays in
 * OpenRegister, which is the whole point of superseding the store-and-copy
 * design.
 *
 * @spec openspec/specs/registry-subscription-connector/spec.md#requirement-a-polled-change-is-posted-to-openregister-not-stored-locally-req-rsc-003
 */
class SubscriptionRoster {
	/**
	 * App id for app-config reads and writes.
	 */
	public const APP_ID = 'integriq';

	/**
	 * App-config key prefix holding the roster per registry.
	 */
	public const KEY_PREFIX = 'registry_subscription.roster.';

	/**
	 * App-config key prefix holding, per registry, which target schemas follow
	 * each identity. A key of its own, so the roster above keeps its shape.
	 */
	public const TARGETS_PREFIX = 'registry_subscription.targets.';

	/**
	 * Constructor.
	 *
	 * @param IAppConfig $appConfig App configuration store.
	 */
	public function __construct(private readonly IAppConfig $appConfig) {
	}//end __construct()

	/**
	 * The identities currently followed for a registry.
	 *
	 * @param string $registryId Registry id.
	 *
	 * @return array<string,string> Identity value to subscription reference.
	 *
	 * @spec openspec/specs/registry-subscription-connector/spec.md
	 */
	public function identities(string $registryId): array {
		$raw = $this->appConfig->getValueString(self::APP_ID, (self::KEY_PREFIX . $registryId), '{}');
		$decoded = json_decode($raw, true);

		if (is_array($decoded) === true) {
			return $decoded;
		}

		return [];
	}//end identities()

	/**
	 * Record an identity as followed.
	 *
	 * @param string $registryId Registry id.
	 * @param string $identity The identity value.
	 * @param string $reference The registry's own subscription reference.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/registry-subscription-connector/spec.md
	 */
	public function add(string $registryId, string $identity, string $reference = ''): void {
		$roster = $this->identities(registryId: $registryId);
		$roster[$identity] = $reference;
		$this->store(registryId: $registryId, roster: $roster);
	}//end add()

	/**
	 * Stop following an identity.
	 *
	 * @param string $registryId Registry id.
	 * @param string $identity The identity value.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/registry-subscription-connector/spec.md
	 */
	public function remove(string $registryId, string $identity): void {
		$roster = $this->identities(registryId: $registryId);
		unset($roster[$identity]);
		$this->store(registryId: $registryId, roster: $roster);

		$targets = $this->allTargets(registryId: $registryId);
		unset($targets[$identity]);
		$this->storeJson(key: (self::TARGETS_PREFIX . $registryId), value: $targets);
	}//end remove()

	/**
	 * The target schema slugs that follow one identity.
	 *
	 * @param string $registryId Registry id.
	 * @param string $identity The identity value.
	 *
	 * @return array<int,string> Target schema slugs, in the order they were recorded.
	 *
	 * @spec openspec/changes/registry-update-maps-source-fields/specs/registry-subscription-connector/spec.md#requirement-a-change-is-posted-in-each-target-schemas-own-property-names-req-rsc-004
	 */
	public function targets(string $registryId, string $identity): array {
		$targets = ($this->allTargets(registryId: $registryId)[$identity] ?? []);
		if (is_array($targets) === false) {
			return [];
		}

		return array_values(array_map('strval', $targets));
	}//end targets()

	/**
	 * Record that a target schema follows an identity. Recording it twice
	 * keeps one entry.
	 *
	 * @param string $registryId Registry id.
	 * @param string $identity The identity value.
	 * @param string $targetSchema The target schema's slug.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/registry-update-maps-source-fields/specs/registry-subscription-connector/spec.md#requirement-a-change-is-posted-in-each-target-schemas-own-property-names-req-rsc-004
	 */
	public function addTarget(string $registryId, string $identity, string $targetSchema): void {
		$current = $this->targets(registryId: $registryId, identity: $identity);
		if ($targetSchema === '' || in_array($targetSchema, $current, true) === true) {
			return;
		}

		$targets = $this->allTargets(registryId: $registryId);
		$targets[$identity] = [...$current, $targetSchema];
		$this->storeJson(key: (self::TARGETS_PREFIX . $registryId), value: $targets);
	}//end addTarget()

	/**
	 * Every identity's targets for one registry.
	 *
	 * @param string $registryId Registry id.
	 *
	 * @return array<string,mixed> Identity value to target slugs.
	 */
	private function allTargets(string $registryId): array {
		$raw = $this->appConfig->getValueString(self::APP_ID, (self::TARGETS_PREFIX . $registryId), '{}');
		$decoded = json_decode($raw, true);

		if (is_array($decoded) === true) {
			return $decoded;
		}

		return [];
	}//end allTargets()

	/**
	 * Persist a JSON value under an app-config key.
	 *
	 * @param string $key The key.
	 * @param array<string,mixed> $value The value.
	 *
	 * @return void
	 */
	private function storeJson(string $key, array $value): void {
		$encoded = json_encode($value);
		if ($encoded === false) {
			$encoded = '{}';
		}

		$this->appConfig->setValueString(self::APP_ID, $key, $encoded);
	}//end storeJson()

	/**
	 * Persist a roster.
	 *
	 * @param string $registryId Registry id.
	 * @param array<string,string> $roster The roster.
	 *
	 * @return void
	 */
	private function store(string $registryId, array $roster): void {
		$encoded = json_encode($roster);
		if ($encoded === false) {
			$encoded = '{}';
		}

		$this->appConfig->setValueString(
			self::APP_ID,
			(self::KEY_PREFIX . $registryId),
			$encoded
		);
	}//end store()
}//end class

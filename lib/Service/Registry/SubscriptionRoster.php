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
 * @spec openspec/changes/registry-subscription-connector/specs/registry-subscription-connector/spec.md#requirement-a-polled-change-is-posted-to-openregister-not-stored-locally-req-rsc-003
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
	 */
	public function add(string $registryId, string $identity, string $reference = ''): void {
		$roster = $this->identities($registryId);
		$roster[$identity] = $reference;
		$this->store($registryId, $roster);
	}//end add()

	/**
	 * Stop following an identity.
	 *
	 * @param string $registryId Registry id.
	 * @param string $identity The identity value.
	 *
	 * @return void
	 */
	public function remove(string $registryId, string $identity): void {
		$roster = $this->identities($registryId);
		unset($roster[$identity]);
		$this->store($registryId, $roster);
	}//end remove()

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

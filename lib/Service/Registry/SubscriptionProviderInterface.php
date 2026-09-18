<?php

/**
 * The contract a registry subscription binding implements.
 *
 * @category Contract
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
 * Subscribe, unsubscribe, and ask what changed since last time.
 *
 * @spec openspec/changes/registry-subscription-connector/specs/registry-subscription-connector/spec.md#requirement-a-subscription-provider-per-registry-req-rsc-001
 */
interface SubscriptionProviderInterface {
	/**
	 * The registry id this binding answers to, for example `brp`.
	 *
	 * @return string Registry id.
	 */
	public function registryId(): string;

	/**
	 * Ask the registry to follow one identity.
	 *
	 * @param string $identity The identity value, for example a BSN.
	 *
	 * @return SubscriptionResult Active, or failed with the source's error text.
	 */
	public function subscribe(string $identity): SubscriptionResult;

	/**
	 * Ask the registry to stop following one identity.
	 *
	 * @param string $identity The identity value.
	 *
	 * @return void
	 */
	public function unsubscribe(string $identity): void;

	/**
	 * What changed since the last poll.
	 *
	 * @param array<int,string> $identities Identities with an active subscription.
	 *
	 * @return iterable<SubscriptionChange> The changes.
	 */
	public function pollChanges(array $identities = []): iterable;
}//end interface

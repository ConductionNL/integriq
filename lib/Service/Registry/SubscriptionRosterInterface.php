<?php

/**
 * Contract of the SubscriptionRoster.
 *
 * @category Contract
 * @package  OCA\Integriq\Service\Registry
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.Integriq.nl
 */

declare(strict_types=1);

namespace OCA\Integriq\Service\Registry;

/**
 * Public operations of {@see SubscriptionRoster} that other components call.
 */
interface SubscriptionRosterInterface {
	/**
	 * The identities currently followed for a registry.
	 *
	 * @param string $registryId Registry id.
	 *
	 * @return array<string,string> Identity value to subscription reference.
	 *
	 * @spec openspec/specs/registry-subscription-connector/spec.md
	 */
	public function identities(string $registryId): array;

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
	public function add(string $registryId, string $identity, string $reference = ''): void;

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
	public function remove(string $registryId, string $identity): void;

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
	public function targets(string $registryId, string $identity): array;

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
	public function addTarget(string $registryId, string $identity, string $targetSchema): void;
}

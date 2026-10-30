<?php

/**
 * Contract of the SubscriptionChange.
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
 * Public operations of {@see SubscriptionChange} that other components call.
 */
interface SubscriptionChangeInterface {
	/**
	 * The identity value that changed.
	 *
	 * @return string Identity value.
	 *
	 * @spec exclude declaration only, the behaviour and its spec live on SubscriptionChange::getIdentity()
	 */
	public function getIdentity(): string;

	/**
	 * The changed properties.
	 *
	 * @return array<string,mixed> Changed properties.
	 *
	 * @spec exclude declaration only, the behaviour and its spec live on SubscriptionChange::getProperties()
	 */
	public function getProperties(): array;

	/**
	 * The source's own event reference.
	 *
	 * @return string Event reference.
	 *
	 * @spec exclude declaration only, the behaviour and its spec live on SubscriptionChange::getEventReference()
	 */
	public function getEventReference(): string;

	/**
	 * Whether this change has anything in it worth posting.
	 *
	 * @return bool True when at least one property changed.
	 *
	 * @spec exclude declaration only, the behaviour and its spec live on SubscriptionChange::isEmpty()
	 */
	public function isEmpty(): bool;

	/**
	 * The change as OpenRegister's inbound update endpoint takes it.
	 *
	 * @return array<string,mixed> Serialisable change.
	 *
	 * @spec exclude declaration only, the behaviour and its spec live on SubscriptionChange::toArray()
	 */
	public function toArray(): array;

	/**
	 * The same change in a target schema's own property names. A field the map
	 * does not list is left out: the target schema does not own it, and
	 * OpenRegister would refuse the whole update for it.
	 *
	 * @param array<string,string> $map Source field to schema property.
	 *
	 * @return self The mapped change; empty when no field is kept.
	 *
	 * @spec openspec/changes/registry-update-maps-source-fields/specs/registry-subscription-connector/spec.md#requirement-a-change-is-posted-in-each-target-schemas-own-property-names-req-rsc-004
	 */
	public function mappedTo(array $map): self;
}

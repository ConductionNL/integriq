<?php

/**
 * One change a registry reported for a subscribed identity.
 *
 * @category ValueObject
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
 * The identity, what changed about it, and the source's own event reference.
 * Nothing more: the record itself stays in OpenRegister.
 *
 * @spec openspec/specs/registry-subscription-connector/spec.md#requirement-a-polled-change-is-posted-to-openregister-not-stored-locally-req-rsc-003
 */
final class SubscriptionChange {
	/**
	 * Constructor.
	 *
	 * @param string $identity The identity value that changed.
	 * @param array<string,mixed> $properties The changed properties, and only those.
	 * @param string $eventReference The source's own event reference.
	 */
	public function __construct(
		private readonly string $identity,
		private readonly array $properties,
		private readonly string $eventReference,
	) {
	}//end __construct()

	/**
	 * The identity value that changed.
	 *
	 * @return string Identity value.
	 */
	public function getIdentity(): string {
		return $this->identity;
	}//end getIdentity()

	/**
	 * The changed properties.
	 *
	 * @return array<string,mixed> Changed properties.
	 */
	public function getProperties(): array {
		return $this->properties;
	}//end getProperties()

	/**
	 * The source's own event reference.
	 *
	 * @return string Event reference.
	 */
	public function getEventReference(): string {
		return $this->eventReference;
	}//end getEventReference()

	/**
	 * Whether this change has anything in it worth posting.
	 *
	 * @return bool True when at least one property changed.
	 */
	public function isEmpty(): bool {
		return $this->properties === [];
	}//end isEmpty()

	/**
	 * The change as OpenRegister's inbound update endpoint takes it.
	 *
	 * @return array<string,mixed> Serialisable change.
	 */
	public function toArray(): array {
		return [
			'identity' => $this->identity,
			'properties' => $this->properties,
			'eventReference' => $this->eventReference,
		];
	}//end toArray()

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
	public function mappedTo(array $map): self {
		$mapped = [];
		foreach ($this->properties as $field => $value) {
			if (isset($map[$field]) === false) {
				continue;
			}

			$mapped[$map[$field]] = $value;
		}

		return new self(identity: $this->identity, properties: $mapped, eventReference: $this->eventReference);
	}//end mappedTo()
}//end class

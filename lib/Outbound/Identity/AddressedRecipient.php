<?php

/**
 * Integriq AddressedRecipient.
 *
 * One recipient of a batch decision together with its address normalised for
 * the batch's channel, or null when the address cannot be used there.
 *
 * @category Outbound
 * @package  OCA\Integriq\Outbound\Identity
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://www.integriq.nl
 *
 * @spec openspec/changes/opt-out-before-send/specs/outbound-opt-out-authority/spec.md#requirement-sibling-apps-ask-through-a-public-decision-event-req-ooa-002
 */

declare(strict_types=1);

namespace OCA\Integriq\Outbound\Identity;

/**
 * A recipient and its normalised address.
 *
 * @spec openspec/changes/opt-out-before-send/specs/outbound-opt-out-authority/spec.md#requirement-sibling-apps-ask-through-a-public-decision-event-req-ooa-002
 */
final class AddressedRecipient {
	/**
	 * Constructor.
	 *
	 * @param string|null         $key       The normalised address, or null when unusable.
	 * @param array<string,mixed> $recipient The recipient as given.
	 */
	public function __construct(
		public readonly ?string $key,
		public readonly array $recipient,
	) {
	}//end __construct()
}//end class

<?php

/**
 * Integriq SubscriptionRequest.
 *
 * One complete subscription request: the registry, the identity and the
 * optional target schema it names.
 *
 * @category Service
 * @package  OCA\Integriq\Service\Registry
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Integriq\Service\Registry;

/**
 * Value object for one subscription request (ADR-011).
 *
 * @spec openspec/specs/registry-subscription-connector/spec.md#requirement-a-subscription-request-is-turned-into-a-live-subscription-req-rsc-002
 */
final class SubscriptionRequest {

	/**
	 * Constructor.
	 *
	 * @param string $registryId The registry to subscribe at.
	 * @param string $identity   The identity to follow.
	 * @param string $schema     The target schema id or slug, empty when none is named.
	 */
	public function __construct(
		public readonly string $registryId,
		public readonly string $identity,
		public readonly string $schema,
	) {

	}//end __construct()
}//end class

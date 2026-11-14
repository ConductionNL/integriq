<?php

/**
 * Integriq JWKS key lookup.
 *
 * One request to resolve a `kid` against a registration's external
 * `jwks_uri`: which registration (type and uuid), where its keys are
 * published, and the `kid` the presented token names.
 *
 * @category Service
 * @package  OCA\Integriq\Service\Lti
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/lti-platform/spec.md#requirement-external-jwks-resolution-with-kid-lookup-per-registration-caching-and-rate-limited-refetch-req-lti-003
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Integriq\Service\Lti;

/**
 * Resolve one kid for one registration.
 *
 * @spec openspec/specs/lti-platform/spec.md#requirement-external-jwks-resolution-with-kid-lookup-per-registration-caching-and-rate-limited-refetch-req-lti-003
 */
final class JwksKeyLookup {

	/**
	 * Constructor.
	 *
	 * @param string $registrationType `lti_platform` or `lti_tool`.
	 * @param string $registrationUuid The registration's UUID.
	 * @param string $jwksUri          The external JWKS URI.
	 * @param string $kid              The `kid` from the presented token's header.
	 */
	public function __construct(
		public readonly string $registrationType,
		public readonly string $registrationUuid,
		public readonly string $jwksUri,
		public readonly string $kid,
	) {

	}//end __construct()
}//end class

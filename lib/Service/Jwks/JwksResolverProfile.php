<?php

/**
 * Integriq JwksResolverProfile.
 *
 * What a JwksResolver caller calls itself: its log prefix and the ad-hoc
 * source it fetches through.
 *
 * @category Service
 * @package  OCA\Integriq\Service\Jwks
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/access-oauth-and-token-validation/tasks.md#task-1
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Integriq\Service\Jwks;

/**
 * Keeps each caller's log lines and call identity its own, so the LTI path
 * still says LTI and the consumer path says consumer.
 *
 * @spec openspec/changes/access-oauth-and-token-validation/tasks.md#task-1
 */
final class JwksResolverProfile {

	/**
	 * Constructor.
	 *
	 * @param string $logLabel         Prefix of every log message, e.g. `LtiJwksResolverService`.
	 * @param string $sourceUuidPrefix Prefix of the ad-hoc source's uuid; the owner uuid is appended.
	 * @param string $sourceName       Name of the ad-hoc source.
	 *
	 * @spec openspec/changes/access-oauth-and-token-validation/tasks.md#task-1
	 */
	public function __construct(
		public readonly string $logLabel,
		public readonly string $sourceUuidPrefix,
		public readonly string $sourceName,
	) {

	}//end __construct()
}//end class

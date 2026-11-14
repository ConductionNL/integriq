<?php

/**
 * Integriq JwksLookup.
 *
 * One key lookup: whose key set, where it is published, and which key.
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
 * The key set is cached per owner (a registration or a consumer), never per
 * URL, so two owners sharing a `jwks_uri` cannot poison each other's cache.
 *
 * @spec openspec/changes/access-oauth-and-token-validation/tasks.md#task-1
 */
final class JwksLookup {

	/**
	 * Constructor.
	 *
	 * @param string $ownerType What kind of owner, part of the cache key (e.g. `platform`, `consumer`).
	 * @param string $ownerUuid The owner's uuid.
	 * @param string $jwksUri   Where the owner publishes its key set.
	 * @param string $kid       The key id the token names.
	 *
	 * @spec openspec/changes/access-oauth-and-token-validation/tasks.md#task-1
	 */
	public function __construct(
		public readonly string $ownerType,
		public readonly string $ownerUuid,
		public readonly string $jwksUri,
		public readonly string $kid,
	) {

	}//end __construct()
}//end class

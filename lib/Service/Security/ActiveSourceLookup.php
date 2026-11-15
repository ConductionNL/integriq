<?php

/**
 * Integriq Active Source Lookup.
 *
 * Finds an adapter's one active source the way every adapter service does:
 * `findAll()` locates the first enabled `source` of the adapter's type, and
 * {@see RawSourceResolver} re-reads that one uuid raw so its credentials are
 * intact. It answers null when nothing is configured, so each service keeps
 * throwing its own exception and message.
 *
 * It does not widen access: the query runs through the caller's own object
 * service with its default RBAC and multitenancy, exactly as before.
 *
 * @category Service
 * @package  OCA\Integriq\Service\Security
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.Integriq.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Integriq\Service\Security;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService as OrObjectService;

/**
 * The first enabled source of one adapter type, raw.
 *
 * @spec openspec/specs/rod-adapter/spec.md#requirement-req-004-push-endpoint-and-signed-retour-receiver
 */
final class ActiveSourceLookup {

	/**
	 * Constructor.
	 *
	 * @param OrObjectService   $objectService     The caller's object service.
	 * @param RawSourceResolver $rawSourceResolver Re-reads the located source raw.
	 */
	public function __construct(
		private readonly OrObjectService $objectService,
		private readonly RawSourceResolver $rawSourceResolver,
	) {
	}//end __construct()

	/**
	 * The first enabled source of a type, raw, or null when none is configured.
	 *
	 * @param string $sourceType The adapter's source type, e.g. `rod`.
	 *
	 * @return ObjectEntity|null The raw source, or null.
	 *
	 * @spec openspec/specs/rod-adapter/spec.md#requirement-req-004-push-endpoint-and-signed-retour-receiver
	 */
	public function first(string $sourceType): ?ObjectEntity {
		$matches = $this->objectService->findAll(
			config: [
				'filters' => [
					'register' => RawSourceResolver::REGISTER,
					'schema' => RawSourceResolver::SCHEMA,
					'type' => $sourceType,
					'isEnabled' => true,
				],
				'limit' => 1,
			]
		);
		$results = ($matches['results'] ?? $matches);

		if (empty($results) === true) {
			return null;
		}

		return $this->rawSourceResolver->resolveRaw(source: $results[0]);
	}//end first()
}//end class

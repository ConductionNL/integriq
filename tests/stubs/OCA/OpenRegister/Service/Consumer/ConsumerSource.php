<?php

// Copy of openregister development 75088d6237 lib/Service/Consumer/ConsumerSource.php (only @spec lines dropped), so integriq's tests run OpenRegister's real credential checks.

/**
 * Where AuthorizationService looks up the consumer behind a credential.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Consumer
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Consumer;

/**
 * A store of API consumers that AuthorizationService can authenticate against.
 *
 * OpenRegister's own consumers live in its `openregister_consumers` table
 * ({@see ConsumerMapperSource}, the default). An app that keeps its consumers
 * elsewhere (integriq: objects of its `consumer` schema) passes its own source
 * to the authorize call, so the checks stay in one place while the data stays
 * where the app keeps it.
 *
 */
interface ConsumerSource {

	/**
	 * The consumer whose name is a JWT's `iss` claim.
	 *
	 * @param string $issuer The issuer.
	 *
	 * @return ResolvedConsumer|null The consumer, or null when there is none.
	 *
	 */
	public function findByIssuer(string $issuer): ?ResolvedConsumer;

	/**
	 * The API-key consumer whose configured key equals the presented one.
	 *
	 * Implementations compare in constant time and never match an empty key.
	 *
	 * @param string $apiKey The presented key.
	 *
	 * @return ResolvedConsumer|null The consumer, or null when none matches.
	 *
	 */
	public function findByApiKey(string $apiKey): ?ResolvedConsumer;
}//end interface

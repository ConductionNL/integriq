<?php

// Copy of openregister lib/Service/Consumer/ConsumerIdentity.php (only @spec lines dropped; ResolvedConsumer takes an identity and an authorization), so integriq's tests run OpenRegister's real credential checks.

/**
 * Who a resolved consumer is, whatever store it came from.
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
 * Who a resolved consumer is: the store that resolved it, its identifier there and its name.
 *
 */
final class ConsumerIdentity {

	/**
	 * Constructor.
	 *
	 * @param string      $source Which store resolved it (`openregister`, or the app id).
	 * @param string|null $uuid   The consumer's identifier in that store.
	 * @param string      $name   The consumer's name (a JWT's `iss`).
	 *
	 */
	public function __construct(
		public readonly string $source,
		public readonly ?string $uuid,
		public readonly string $name,
	) {
	}//end __construct()
}//end class

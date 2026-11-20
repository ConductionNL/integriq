<?php

/**
 * Test stub for OCA\OpenRegister\Service\Credential\CredentialMint.
 *
 * Mirrors openregister main: the name, provider and owner of a credential to mint.
 *
 * @category Test
 * @package  OCA\OpenRegister\Service\Credential
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://www.Integriq.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Credential;

/**
 * Stub: what a mint creates.
 */
final class CredentialMint {

	/**
	 * Constructor.
	 *
	 * @param string               $name     The credential name.
	 * @param string               $provider The provider identifier.
	 * @param string               $owner    The owning user's UID.
	 * @param array<string, mixed> $metadata Non-secret connection properties.
	 */
	public function __construct(
		public readonly string $name,
		public readonly string $provider,
		public readonly string $owner,
		public readonly array $metadata=[],
	) {
	}//end __construct()
}//end class

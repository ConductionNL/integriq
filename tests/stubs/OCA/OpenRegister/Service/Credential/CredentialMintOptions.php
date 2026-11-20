<?php

/**
 * Test stub for OCA\OpenRegister\Service\Credential\CredentialMintOptions.
 *
 * Mirrors openregister main: the allowed apps, the secret, the scope and the
 * organisation of a mint. The secret is kept out of the public properties.
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
 * Stub: how a mint runs.
 */
final class CredentialMintOptions {

	/**
	 * The raw secret.
	 *
	 * @var string|null
	 */
	private ?string $secret;

	/**
	 * Constructor.
	 *
	 * @param array<int, string> $allowedApps  The app ids permitted to use the credential.
	 * @param string|null        $secret       The raw secret.
	 * @param string             $scope        The resolved scope.
	 * @param string|null        $organisation The owning organisation.
	 */
	public function __construct(
		public readonly array $allowedApps=[],
		#[\SensitiveParameter] ?string $secret=null,
		public readonly string $scope='personal',
		public readonly ?string $organisation=null,
	) {
		$this->secret = $secret;
	}//end __construct()

	/**
	 * The raw secret.
	 *
	 * @return string|null
	 */
	public function secret(): ?string {
		return $this->secret;
	}//end secret()
}//end class

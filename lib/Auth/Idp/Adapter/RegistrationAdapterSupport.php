<?php

/**
 * Integriq RegistrationAdapterSupport.
 *
 * What the adapters built from one `idpRegistration` share, whatever their
 * protocol: the provider and the readiness they answer with, the assurance
 * spellings at or above the floor that a login asks for, the credential read
 * through the secret seam for this request only, and a response body as text.
 * A class using it holds `$registration`, `$secrets` and `$trust`.
 *
 * @category Service
 * @package  OCA\Integriq\Auth\Idp\Adapter
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://www.integriq.nl
 *
 * @spec openspec/changes/portal-idp-broker-config/specs/idp-broker-configuration/spec.md#requirement-an-identity-provider-adapter-is-one-of-two-vendor-neutral-kinds-req-idpc-001
 */

declare(strict_types=1);

namespace OCA\Integriq\Auth\Idp\Adapter;

use OCA\Integriq\Exception\IdpAssertionException;
use OCA\Integriq\Exception\IdpSecretRefusedException;

/**
 * The shared half of a registration's adapter.
 *
 * @spec openspec/changes/portal-idp-broker-config/specs/idp-broker-configuration/spec.md#requirement-an-identity-provider-adapter-is-one-of-two-vendor-neutral-kinds-req-idpc-001
 */
trait RegistrationAdapterSupport {

	/**
	 * The provider id this adapter answers to.
	 *
	 * @return string The registration's provider.
	 *
	 * @spec openspec/changes/portal-idp-broker-config/specs/idp-broker-configuration/spec.md#requirement-an-identity-provider-adapter-is-one-of-two-vendor-neutral-kinds-req-idpc-001
	 */
	public function getProviderId(): string {
		return $this->registration->provider();

	}//end getProviderId()

	/**
	 * Whether this adapter can authenticate anybody: it is built only for a ready registration.
	 *
	 * @return boolean True.
	 *
	 * @spec openspec/changes/portal-idp-broker-config/specs/idp-broker-configuration/spec.md#requirement-an-identity-provider-adapter-is-one-of-two-vendor-neutral-kinds-req-idpc-001
	 */
	public function isConfigured(): bool {
		return true;

	}//end isConfigured()

	/**
	 * The spellings of the registration's assurance mapping at or above the floor, for `acr_values` (OIDC) or RequestedAuthnContext (SAML).
	 *
	 * @return list<string>
	 *
	 * @spec openspec/changes/portal-idp-broker-config/specs/idp-broker-configuration/spec.md#requirement-an-identity-provider-adapter-is-one-of-two-vendor-neutral-kinds-req-idpc-001
	 */
	private function floorSpellings(): array {
		$aliases = $this->registration->stringMap(key: 'assuranceMapping');
		$values = [];
		foreach (array_keys($aliases) as $spelling) {
			try {
				$trust = $this->trust->map(provider: $this->registration->provider(), level: $spelling, aliases: $aliases);
			} catch (IdpAssertionException $unmapped) {
				continue;
			}

			if ($this->trust->meetsFloor(trust: $trust) === true) {
				$values[] = $spelling;
			}
		}

		return $values;

	}//end floorSpellings()

	/**
	 * One of the registration's credentials, read for this request.
	 *
	 * @param string $role The credential role.
	 *
	 * @return string
	 *
	 * @throws IdpAssertionException When the seam refuses it.
	 *
	 * @spec openspec/changes/portal-idp-broker-config/specs/idp-broker-configuration/spec.md#requirement-an-identity-provider-adapter-is-one-of-two-vendor-neutral-kinds-req-idpc-001
	 */
	private function credential(string $role): string {
		try {
			return $this->secrets->read(
				registration: $this->registration->id,
				organisation: $this->registration->organisation(),
				role: $role,
				reference: $this->registration->credential(role: $role)
			);
		} catch (IdpSecretRefusedException $refused) {
			throw new IdpAssertionException(message: 'The '.$role.' credential of the registration could not be read.', previous: $refused);
		}

	}//end credential()

	/**
	 * A response body as a string.
	 *
	 * @param mixed $body The body: a string or a stream resource.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/portal-idp-broker-config/specs/idp-broker-configuration/spec.md#requirement-an-identity-provider-adapter-is-one-of-two-vendor-neutral-kinds-req-idpc-001
	 */
	private static function body(mixed $body): string {
		if (is_resource($body) === true) {
			return (string)stream_get_contents($body);
		}

		return (string)$body;

	}//end body()

}//end trait

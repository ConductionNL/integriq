<?php

/**
 * Integriq IdpRegistrationRule.
 *
 * The rules a registration's own fields must meet before it is saved, apart
 * from the uniqueness of organisation and provider, which needs the store.
 *
 * @category Auth
 * @package  OCA\Integriq\Auth\Idp
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
 * @spec openspec/changes/idp-broker-yivi-route/specs/idp-broker-yivi/spec.md#requirement-yivi-is-a-provider-on-the-broker-reached-as-an-oidc-registration-req-yivi-001
 */

declare(strict_types=1);

namespace OCA\Integriq\Auth\Idp;

/**
 * Checks one registration's data on its own.
 *
 * A provider that is only reached over OIDC (Yivi, through an OIDC bridge) may
 * not be saved with the SAML kind: no SAML adapter can serve it, so the
 * registration would look configured and never log anyone in.
 *
 * @spec openspec/changes/idp-broker-yivi-route/specs/idp-broker-yivi/spec.md#requirement-yivi-is-a-provider-on-the-broker-reached-as-an-oidc-registration-req-yivi-001
 */
final class IdpRegistrationRule {

	/**
	 * The Yivi provider: attributes disclosed from the resident's wallet, through an OIDC bridge.
	 *
	 * @var string
	 */
	public const PROVIDER_YIVI = 'yivi';

	/**
	 * The providers that are only reached as an OIDC Relying Party.
	 *
	 * @var list<string>
	 */
	public const OIDC_ONLY_PROVIDERS = [self::PROVIDER_YIVI];

	/**
	 * The refusal code for an OIDC-only provider saved with another kind.
	 *
	 * @var string
	 */
	public const CODE_NEEDS_OIDC = 'idp_registration_needs_oidc';

	/**
	 * Why the registration may not be saved, or null when it may.
	 *
	 * @param array<string, mixed> $data The registration's data.
	 *
	 * @return array{code: string, provider: string}|null The refusal.
	 *
	 * @spec openspec/changes/idp-broker-yivi-route/specs/idp-broker-yivi/spec.md#requirement-yivi-is-a-provider-on-the-broker-reached-as-an-oidc-registration-req-yivi-001
	 */
	public function refusal(array $data): ?array {
		$provider = trim((string)($data['provider'] ?? ''));
		$kind = trim((string)($data['kind'] ?? ''));
		if (in_array($provider, self::OIDC_ONLY_PROVIDERS, true) === true && $kind !== IdpRegistration::KIND_OIDC) {
			return ['code' => self::CODE_NEEDS_OIDC, 'provider' => $provider];
		}

		return null;

	}//end refusal()
}//end class

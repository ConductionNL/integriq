<?php

/**
 * Integriq IdpRegistration.
 *
 * One organisation's registration with one identity provider, read from its
 * idpRegistration object: what is required for its protocol, and which
 * credential roles it needs.
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
 * @spec openspec/changes/portal-idp-broker-config/specs/idp-broker-configuration/spec.md#requirement-each-organisation-has-its-own-registration-per-provider-req-idpc-002
 */

declare(strict_types=1);

namespace OCA\Integriq\Auth\Idp;

/**
 * One readiness status of a registration, with reasons that name properties
namespace OCA\Integriq\Auth\Idp;

/**
 * One idpRegistration object, typed (design D2). Holds credential ids only.
 *
 * @spec openspec/changes/portal-idp-broker-config/specs/idp-broker-configuration/spec.md#requirement-each-organisation-has-its-own-registration-per-provider-req-idpc-002
 */
final class IdpRegistration {

	/**
	 * The SAML Service Provider kind.
	 *
	 * @var string
	 */
	public const KIND_SAML = 'saml-sp';

	/**
	 * The OIDC Relying Party kind.
	 *
	 * @var string
	 */
	public const KIND_OIDC = 'oidc-rp';

	/**
	 * Constructor.
	 *
	 * @param string               $id           The object's uuid.
	 * @param array<string, mixed> $data         The object's data.
	 */
	public function __construct(
		public readonly string $id,
		public readonly array $data,
	) {

	}//end __construct()

	/**
	 * A string property, trimmed; '' when absent.
	 *
	 * @param string $key The property.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/portal-idp-broker-config/specs/idp-broker-configuration/spec.md#requirement-each-organisation-has-its-own-registration-per-provider-req-idpc-002
	 */
	public function text(string $key): string {
		$value = ($this->data[$key] ?? '');
		if (is_scalar($value) === false) {
			return '';
		}

		return trim((string)$value);

	}//end text()

	/**
	 * An object property as a string-to-string map; empty when absent or not an object.
	 *
	 * @param string $key The property.
	 *
	 * @return array<string, string>
	 *
	 * @spec openspec/changes/portal-idp-broker-config/specs/idp-broker-configuration/spec.md#requirement-pseudonymisation-mode-is-chosen-per-registration-req-idpc-005
	 */
	public function stringMap(string $key): array {
		$value = ($this->data[$key] ?? []);
		if (is_array($value) === false) {
			return [];
		}

		$map = [];
		foreach ($value as $from => $to) {
			if (is_scalar($to) === true) {
				$map[(string)$from] = (string)$to;
			}
		}

		return $map;

	}//end stringMap()

	/**
	 * Whether the identity provider delivers polymorphic pseudonyms rather than a BSN.
	 *
	 * Anything but `polymorphic` is the default, `hmac`.
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/portal-idp-broker-config/specs/idp-broker-configuration/spec.md#requirement-pseudonymisation-mode-is-chosen-per-registration-req-idpc-005
	 */
	public function isPolymorphic(): bool {
		return $this->text(key: 'pseudonymisation') === 'polymorphic';

	}//end isPolymorphic()

	/**
	 * The organisation the logins are for.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/portal-idp-broker-config/specs/idp-broker-configuration/spec.md#requirement-each-organisation-has-its-own-registration-per-provider-req-idpc-002
	 */
	public function organisation(): string {
		return $this->text(key: 'organisation');

	}//end organisation()

	/**
	 * The provider: digid, eherkenning or eidas.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/portal-idp-broker-config/specs/idp-broker-configuration/spec.md#requirement-each-organisation-has-its-own-registration-per-provider-req-idpc-002
	 */
	public function provider(): string {
		return $this->text(key: 'provider');

	}//end provider()

	/**
	 * The protocol kind: saml-sp or oidc-rp.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/portal-idp-broker-config/specs/idp-broker-configuration/spec.md#requirement-each-organisation-has-its-own-registration-per-provider-req-idpc-002
	 */
	public function kind(): string {
		return $this->text(key: 'kind');

	}//end kind()

	/**
	 * Whether an administrator switched logins on.
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/portal-idp-broker-config/specs/idp-broker-configuration/spec.md#requirement-each-organisation-has-its-own-registration-per-provider-req-idpc-002
	 */
	public function isEnabled(): bool {
		return ($this->data['enabled'] ?? false) === true;

	}//end isEnabled()

	/**
	 * The name the screen and the messages use: the label, else the id.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/portal-idp-broker-config/specs/idp-broker-configuration/spec.md#requirement-each-organisation-has-its-own-registration-per-provider-req-idpc-002
	 */
	public function name(): string {
		$label = $this->text(key: 'label');
		if ($label === '') {
			return $this->id;
		}

		return $label;

	}//end name()

	/**
	 * The credential id stored for a role, or ''.
	 *
	 * @param string $role The role.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/portal-idp-broker-config/specs/idp-broker-configuration/spec.md#requirement-each-organisation-has-its-own-registration-per-provider-req-idpc-002
	 */
	public function credential(string $role): string {
		$credentials = ($this->data['credentials'] ?? []);
		if (is_array($credentials) === false) {
			return '';
		}

		$value = ($credentials[$role] ?? '');
		if (is_string($value) === false) {
			return '';
		}

		return trim($value);

	}//end credential()

	/**
	 * The properties its kind needs before a login can start (design D2).
	 *
	 * @return list<string> Property names; for an unknown kind, `kind` itself.
	 *
	 * @spec openspec/changes/portal-idp-broker-config/specs/idp-broker-configuration/spec.md#requirement-readiness-is-one-answer-for-the-screen-and-the-runtime-req-idpc-007
	 */
	public function requiredProperties(): array {
		$base = ['organisation', 'provider'];

		return match ($this->kind()) {
			self::KIND_SAML => [...$base, 'idpMetadataUrl'],
			self::KIND_OIDC => [...$base, 'issuer', 'clientId', 'clientAuth'],
			default => [...$base, 'kind'],
		};

	}//end requiredProperties()

	/**
	 * The required properties that are empty; `kind` also when it is not a known kind.
	 *
	 * @return list<string>
	 *
	 * @spec openspec/changes/portal-idp-broker-config/specs/idp-broker-configuration/spec.md#requirement-readiness-is-one-answer-for-the-screen-and-the-runtime-req-idpc-007
	 */
	public function missingProperties(): array {
		$known = in_array($this->kind(), [self::KIND_SAML, self::KIND_OIDC], true);

		return array_values(
			array_filter(
				$this->requiredProperties(),
				fn (string $key): bool => $this->text(key: $key) === '' || ($key === 'kind' && $known === false)
			)
		);

	}//end missingProperties()

	/**
	 * The credential roles this registration needs (design D4).
	 *
	 * The decryption key is needed only when the identity provider encrypts,
	 * which is known from its metadata, so it is required once it is named.
	 *
	 * @return list<string>
	 *
	 * @spec openspec/changes/portal-idp-broker-config/specs/idp-broker-configuration/spec.md#requirement-identity-provider-secrets-are-read-from-keepiq-and-fail-closed-req-idpc-004
	 */
	public function requiredCredentialRoles(): array {
		$roles = [];
		if ($this->kind() === self::KIND_SAML) {
			$roles[] = 'signing';
			if ($this->credential(role: 'decryption') !== '') {
				$roles[] = 'decryption';
			}
		}

		if ($this->kind() === self::KIND_OIDC && $this->text(key: 'clientAuth') === 'client_secret_basic') {
			$roles[] = 'clientSecret';
		} else if ($this->kind() === self::KIND_OIDC) {
			$roles[] = 'signing';
		}

		$pseudonymRole = 'salt';
		if ($this->text(key: 'pseudonymisation') === 'polymorphic') {
			$pseudonymRole = 'pseudonymKeys';
		}

		$roles[] = $pseudonymRole;

		return $roles;

	}//end requiredCredentialRoles()
}//end class

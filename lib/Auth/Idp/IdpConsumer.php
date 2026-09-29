<?php

/**
 * Integriq IdpConsumer.
 *
 * One app that may start a government login at integriq and redeem the code
 * it gets back. It carries where the browser may be sent back to, and how the
 * app proves who it is at the exchange.
 *
 * Two shapes are read. The current one is an object with a secret held by
 * broker reference, a list of return addresses and an enabled flag. The older
 * one is a bare id-to-secret pair; it can still redeem, and because it names
 * no return address it can never start a login.
 *
 * @category Auth
 * @package  OCA\Integriq\Auth\Idp
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://www.integriq.nl
 *
 * @spec openspec/changes/identity-broker-browser-login/specs/digid-eherkenning-auth-adapter/spec.md#requirement-a-consuming-app-is-registered-with-its-return-addresses-req-idp-003
 */

declare(strict_types=1);

namespace OCA\Integriq\Auth\Idp;

/**
 * One registered consuming app.
 *
 * @spec openspec/changes/identity-broker-browser-login/specs/digid-eherkenning-auth-adapter/spec.md#requirement-a-consuming-app-is-registered-with-its-return-addresses-req-idp-003
 */
final class IdpConsumer {

	/**
	 * Constructor.
	 *
	 * @param string $id The consumer id, also the envelope audience.
	 * @param boolean $enabled Whether the consumer may start and redeem.
	 * @param array<int,string> $returnUrls The exact addresses the browser may be sent back to.
	 * @param string $secretRef The credential broker reference holding the exchange secret.
	 * @param string $secretOrganisation The organisation that owns that credential, for the sessionless read.
	 * @param string $legacySecret The inline secret of the older form, empty for the current form.
	 */
	public function __construct(
		private readonly string $id,
		private readonly bool $enabled,
		private readonly array $returnUrls = [],
		private readonly string $secretRef = '',
		private readonly string $secretOrganisation = '',
		private readonly string $legacySecret = '',
	) {

	}//end __construct()

	/**
	 * Read one entry of the `idp_broker_consumers` map.
	 *
	 * @param string $id The consumer id (the map key).
	 * @param mixed $entry The map value: a secret string (older form) or an object.
	 *
	 * @return self|null The consumer, or null when the entry is unreadable.
	 *
	 * @spec openspec/changes/identity-broker-browser-login/specs/digid-eherkenning-auth-adapter/spec.md#requirement-a-consuming-app-is-registered-with-its-return-addresses-req-idp-003
	 */
	public static function fromConfig(string $id, mixed $entry): ?self {
		if (is_string($entry) === true) {
			// The older form. It was enabled by being present, it can redeem
			// with its inline secret, and it has no return address.
			return new self(id: $id, enabled: $entry !== '', legacySecret: $entry);
		}

		if (is_array($entry) === false) {
			return null;
		}

		$returnUrls = [];
		foreach ((array)($entry['returnUrls'] ?? []) as $url) {
			if (is_string($url) === true && $url !== '') {
				$returnUrls[] = $url;
			}
		}

		return new self(
			id: $id,
			enabled: ($entry['enabled'] ?? false) === true,
			returnUrls: $returnUrls,
			secretRef: (string)($entry['secretRef'] ?? ''),
			secretOrganisation: (string)($entry['secretOrganisation'] ?? ''),
		);

	}//end fromConfig()

	/**
	 * The entry as it is stored in the current form.
	 *
	 * @return array{enabled: bool, returnUrls: array<int,string>, secretRef: string, secretOrganisation: string} The entry.
	 *
	 * @spec openspec/changes/identity-broker-browser-login/specs/digid-eherkenning-auth-adapter/spec.md#requirement-a-consuming-app-is-registered-with-its-return-addresses-req-idp-003
	 */
	public function toConfig(): array {
		return [
			'enabled' => $this->enabled,
			'returnUrls' => array_values($this->returnUrls),
			'secretRef' => $this->secretRef,
			'secretOrganisation' => $this->secretOrganisation,
		];

	}//end toConfig()

	/**
	 * The consumer id.
	 *
	 * @return string The id.
	 *
	 * @spec openspec/changes/identity-broker-browser-login/specs/digid-eherkenning-auth-adapter/spec.md#requirement-a-consuming-app-is-registered-with-its-return-addresses-req-idp-003
	 */
	public function getId(): string {
		return $this->id;

	}//end getId()

	/**
	 * Whether the consumer is switched on.
	 *
	 * @return boolean True when enabled.
	 *
	 * @spec openspec/changes/identity-broker-browser-login/specs/digid-eherkenning-auth-adapter/spec.md#requirement-a-consuming-app-is-registered-with-its-return-addresses-req-idp-003
	 */
	public function isEnabled(): bool {
		return $this->enabled;

	}//end isEnabled()

	/**
	 * The registered return addresses.
	 *
	 * @return array<int,string> The addresses.
	 *
	 * @spec openspec/changes/identity-broker-browser-login/specs/digid-eherkenning-auth-adapter/spec.md#requirement-a-consuming-app-is-registered-with-its-return-addresses-req-idp-003
	 */
	public function getReturnUrls(): array {
		return $this->returnUrls;

	}//end getReturnUrls()

	/**
	 * The credential broker reference of the exchange secret.
	 *
	 * @return string The reference, or an empty string.
	 *
	 * @spec openspec/changes/identity-broker-browser-login/specs/digid-eherkenning-auth-adapter/spec.md#requirement-a-consuming-app-is-registered-with-its-return-addresses-req-idp-003
	 */
	public function getSecretRef(): string {
		return $this->secretRef;

	}//end getSecretRef()

	/**
	 * The organisation that owns the referenced credential.
	 *
	 * @return string The organisation, or an empty string.
	 *
	 * @spec openspec/changes/identity-broker-browser-login/specs/digid-eherkenning-auth-adapter/spec.md#requirement-a-consuming-app-is-registered-with-its-return-addresses-req-idp-003
	 */
	public function getSecretOrganisation(): string {
		return $this->secretOrganisation;

	}//end getSecretOrganisation()

	/**
	 * The inline secret of the older form.
	 *
	 * @return string The secret, or an empty string for the current form.
	 *
	 * @spec openspec/changes/identity-broker-browser-login/specs/digid-eherkenning-auth-adapter/spec.md#requirement-a-consuming-app-is-registered-with-its-return-addresses-req-idp-003
	 */
	public function getLegacySecret(): string {
		return $this->legacySecret;

	}//end getLegacySecret()

	/**
	 * Whether this consumer is registered in the older id-to-secret form.
	 *
	 * @return boolean True for the older form.
	 *
	 * @spec openspec/changes/identity-broker-browser-login/specs/digid-eherkenning-auth-adapter/spec.md#requirement-a-consuming-app-is-registered-with-its-return-addresses-req-idp-003
	 */
	public function isLegacy(): bool {
		return $this->legacySecret !== '';

	}//end isLegacy()

	/**
	 * Whether this consumer may start a login that returns to this address.
	 *
	 * Exact comparison, on purpose. A prefix or host match is how an open
	 * redirect gets in: `https://portal.example.nl.evil.example` starts with
	 * the portal's host, and a path prefix admits `/callback/../anything`.
	 *
	 * @param string $returnUrl The address the start was asked to return to.
	 *
	 * @return boolean True only for an enabled consumer and a registered address.
	 *
	 * @spec openspec/changes/identity-broker-browser-login/specs/digid-eherkenning-auth-adapter/spec.md#requirement-a-login-starts-at-integriq-with-a-signed-single-use-state-req-idp-001
	 */
	public function mayReturnTo(string $returnUrl): bool {
		if ($this->enabled === false || $this->isLegacy() === true || $returnUrl === '') {
			return false;
		}

		return in_array($returnUrl, $this->returnUrls, true);

	}//end mayReturnTo()

	/**
	 * Whether an address may be registered as a return address at all.
	 *
	 * An absolute https address with a host, no user info and no fragment.
	 * Plain http is accepted only for localhost, so a development portal can
	 * be registered and a production one cannot be sent a code in the clear.
	 *
	 * @param string $url The address.
	 *
	 * @return boolean True when it may be registered.
	 *
	 * @spec openspec/changes/identity-broker-browser-login/specs/digid-eherkenning-auth-adapter/spec.md#requirement-a-consuming-app-is-registered-with-its-return-addresses-req-idp-003
	 */
	public static function isAcceptableReturnUrl(string $url): bool {
		$parts = parse_url($url);
		if (is_array($parts) === false || trim((string)($parts['host'] ?? '')) === '') {
			return false;
		}

		if (isset($parts['user']) === true || isset($parts['pass']) === true || isset($parts['fragment']) === true) {
			return false;
		}

		$scheme = strtolower((string)($parts['scheme'] ?? ''));
		if ($scheme === 'https') {
			return true;
		}

		return $scheme === 'http' && in_array(strtolower((string)$parts['host']), ['localhost', '127.0.0.1', '[::1]'], true);

	}//end isAcceptableReturnUrl()

}//end class

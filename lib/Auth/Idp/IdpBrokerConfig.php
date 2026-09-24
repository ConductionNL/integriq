<?php

/**
 * Integriq IdpBrokerConfig.
 *
 * Reads the broker's tenant-wide settings, which live under Beheer >
 * Authenticatie per ADR-017. One place reads them, so the flag, the key and
 * the consumer secrets cannot drift between the callback path and the
 * exchange path.
 *
 * Nothing here has a default that turns the broker on. The flag starts at
 * `0`, and a missing key is missing rather than empty-but-usable.
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
 * @spec openspec/specs/digid-eherkenning-auth-adapter/spec.md
 */

declare(strict_types=1);

namespace OCA\Integriq\Auth\Idp;

use OCP\IAppConfig;

/**
 * The broker's tenant-wide settings.
 *
 * @spec openspec/specs/digid-eherkenning-auth-adapter/spec.md#requirement-configuration-placement-follows-adr-017-rules-1-3-7
 */
class IdpBrokerConfig {

	/**
	 * The app id these settings live under.
	 *
	 * @var string
	 */
	public const APP_ID = 'integriq';

	/**
	 * The feature flag key.
	 *
	 * @var string
	 */
	public const KEY_ENABLED = 'idp_broker_enabled';

	/**
	 * The envelope signing key.
	 *
	 * @var string
	 */
	public const KEY_SIGNING = 'idp_broker_signing_key';

	/**
	 * The per-consumer exchange secrets, as a JSON map.
	 *
	 * @var string
	 */
	public const KEY_CONSUMERS = 'idp_broker_consumers';

	/**
	 * The per-organisation pseudonym salts, as a JSON map.
	 *
	 * @var string
	 */
	public const KEY_SALTS = 'idp_broker_salts';

	/**
	 * The per-provider assurance-level aliases, as a JSON map.
	 *
	 * @var string
	 */
	public const KEY_TRUST_ALIASES = 'idp_broker_trust_aliases';

	/**
	 * Constructor.
	 *
	 * @param IAppConfig $appConfig The Nextcloud app configuration.
	 */
	public function __construct(
		private readonly IAppConfig $appConfig,
	) {

	}//end __construct()

	/**
	 * Whether the broker is switched on.
	 *
	 * @return boolean True only when the flag is exactly "1".
	 *
	 * @spec openspec/specs/digid-eherkenning-auth-adapter/spec.md#requirement-dormant-seam-adapters-ship-config-flag-gated-and-inert
	 */
	public function isEnabled(): bool {
		return $this->appConfig->getValueString(self::APP_ID, self::KEY_ENABLED, '0') === '1';

	}//end isEnabled()

	/**
	 * The envelope signing key.
	 *
	 * @return string The key, or an empty string when none is configured.
	 *
	 * @spec openspec/specs/digid-eherkenning-auth-adapter/spec.md#requirement-one-time-signed-subject-envelope-handoff
	 */
	public function signingKey(): string {
		return $this->appConfig->getValueString(self::APP_ID, self::KEY_SIGNING, '');

	}//end signingKey()

	/**
	 * One consumer's exchange secret.
	 *
	 * @param string $consumer The consumer id.
	 *
	 * @return string The secret, or an empty string when the consumer is unknown.
	 *
	 * @spec openspec/specs/digid-eherkenning-auth-adapter/spec.md#requirement-one-time-signed-subject-envelope-handoff
	 */
	public function consumerSecret(string $consumer): string {
		return (string)($this->map(key: self::KEY_CONSUMERS)[$consumer] ?? '');

	}//end consumerSecret()

	/**
	 * One organisation's pseudonym salt.
	 *
	 * @param string $organisation The organisation id.
	 *
	 * @return string The salt, or an empty string when the organisation has none.
	 *
	 * @spec openspec/specs/digid-eherkenning-auth-adapter/spec.md#requirement-bsn-pseudonymisation-at-the-broker-edge
	 */
	public function organisationSalt(string $organisation): string {
		return (string)($this->map(key: self::KEY_SALTS)[strtolower(trim($organisation))] ?? '');

	}//end organisationSalt()

	/**
	 * One provider's assurance-level aliases.
	 *
	 * @param string $provider The provider id.
	 *
	 * @return array<string,string> The aliases.
	 *
	 * @spec openspec/specs/digid-eherkenning-auth-adapter/spec.md#requirement-trust-levels-map-to-the-eidas-aligned-vocabulary
	 */
	public function trustAliases(string $provider): array {
		$aliases = ($this->map(key: self::KEY_TRUST_ALIASES)[strtolower(trim($provider))] ?? []);
		if (is_array($aliases) === false) {
			return [];
		}

		$narrowed = [];
		foreach ($aliases as $raw => $canonical) {
			$narrowed[(string)$raw] = (string)$canonical;
		}

		return $narrowed;

	}//end trustAliases()

	/**
	 * One JSON-map setting.
	 *
	 * A setting that is not a JSON object reads as empty rather than as
	 * something else: a half-parsed credential map is how a consumer ends up
	 * authenticating against the wrong secret.
	 *
	 * @param string $key The setting key.
	 *
	 * @return array<string,mixed> The map.
	 */
	private function map(string $key): array {
		$decoded = json_decode($this->appConfig->getValueString(self::APP_ID, $key, '{}'), true);
		if (is_array($decoded) === false) {
			return [];
		}

		return $decoded;

	}//end map()

}//end class

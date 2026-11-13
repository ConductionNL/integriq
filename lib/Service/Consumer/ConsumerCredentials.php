<?php

/**
 * ConsumerCredentials: a consumer's list of credentials, with API keys kept as keyed hashes.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\Integriq\Service\Consumer
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.Integriq.nl
 */

declare(strict_types=1);

namespace OCA\Integriq\Service\Consumer;

use DateTimeImmutable;
use DateTimeInterface;
use OCA\Integriq\Kernel\AppIdentity;
use OCA\Integriq\Service\Helper\RandomUuid;
use OCP\IAppConfig;
use OCP\Security\ISecureRandom;

/**
 * The rules for a consumer's `credentials` list.
 *
 * Each entry has an id, a label, a type, a creation date, an optional expiry
 * and the last time it was used. An API key entry holds `keyHash`, an
 * HMAC-SHA256 of the key under an instance pepper, and `keyPrefix`, the first
 * six characters so a person can tell keys apart. The key itself is never
 * stored: it is returned once, by {@see self::generate()}.
 *
 * @spec openspec/changes/access-consumer-credentials/specs/consumer-management/spec.md#requirement-a-consumer-holds-several-credentials-and-no-plaintext-key-req-cred-001
 */
class ConsumerCredentials {

	/**
	 * The consumer property that holds the list.
	 */
	public const FIELD = 'credentials';

	/**
	 * Type of an API key credential.
	 */
	public const TYPE_API_KEY = 'apiKey';

	/**
	 * Type of a client certificate credential.
	 */
	public const TYPE_CLIENT_CERTIFICATE = 'clientCertificate';

	/**
	 * Characters of a key shown so a person can tell keys apart.
	 */
	public const PREFIX_LENGTH = 6;

	/**
	 * Length of a generated key, in characters of a 62-character alphabet (over 32 bytes of entropy).
	 */
	public const KEY_LENGTH = 48;

	/**
	 * `lastUsedAt` is written at most once per this many seconds per credential.
	 */
	public const LAST_USED_INTERVAL = 60;

	/**
	 * App config key of the instance pepper.
	 */
	private const PEPPER_KEY = 'consumer_credential_pepper';

	/**
	 * Length of a generated pepper.
	 */
	private const PEPPER_LENGTH = 64;

	/**
	 * The pepper, once read.
	 *
	 * @var string|null
	 */
	private ?string $pepper = null;

	/**
	 * Constructor.
	 *
	 * @param IAppConfig    $appConfig Holds the instance pepper, as a sensitive value.
	 * @param ISecureRandom $random    Generates keys and the pepper.
	 */
	public function __construct(
		private readonly IAppConfig $appConfig,
		private readonly ISecureRandom $random,
	) {
	}//end __construct()

	/**
	 * The keyed hash of an API key.
	 *
	 * @param string $key The key.
	 *
	 * @return string The hex HMAC-SHA256.
	 *
	 * @spec openspec/changes/access-consumer-credentials/specs/consumer-management/spec.md#requirement-a-consumer-holds-several-credentials-and-no-plaintext-key-req-cred-001
	 */
	public function hashKey(string $key): string {
		return hash_hmac('sha256', $key, $this->pepper());
	}//end hashKey()

	/**
	 * An API key credential for a key that already exists, such as a migrated plaintext key.
	 *
	 * @param string                 $key       The key.
	 * @param string                 $label     The label.
	 * @param string|null            $expiresAt ISO-8601 expiry, or null for none.
	 * @param DateTimeImmutable|null $now       The moment of creation.
	 *
	 * @return array<string, mixed> The credential entry, without the key.
	 *
	 * @spec openspec/changes/access-consumer-credentials/specs/consumer-management/spec.md#requirement-a-consumer-holds-several-credentials-and-no-plaintext-key-req-cred-001
	 */
	public function apiKeyEntry(string $key, string $label, ?string $expiresAt = null, ?DateTimeImmutable $now = null): array {
		return [
			'id'         => RandomUuid::version4(),
			'label'      => $label,
			'type'       => self::TYPE_API_KEY,
			'createdAt'  => ($now ?? new DateTimeImmutable())->format(DateTimeInterface::ATOM),
			'expiresAt'  => $expiresAt,
			'lastUsedAt' => null,
			'keyPrefix'  => self::prefixOf(key: $key),
			'keyHash'    => $this->hashKey(key: $key),
		];
	}//end apiKeyEntry()

	/**
	 * The characters of a key shown to tell keys apart: the first six, and
	 * never more than a quarter of the key, so a short migrated key is not
	 * stored whole.
	 *
	 * @param string $key The key.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/access-consumer-credentials/specs/consumer-management/spec.md#requirement-a-new-key-is-shown-exactly-once-req-cred-002
	 */
	public static function prefixOf(string $key): string {
		return substr($key, 0, min(self::PREFIX_LENGTH, intdiv(strlen($key), 4)));
	}//end prefixOf()

	/**
	 * Generate a new API key and its credential entry.
	 *
	 * @param string                 $label     The label.
	 * @param string|null            $expiresAt ISO-8601 expiry, or null for none.
	 * @param DateTimeImmutable|null $now       The moment of creation.
	 *
	 * @return array{key: string, credential: array<string, mixed>} The key, to be shown once, and the entry to store.
	 *
	 * @spec openspec/changes/access-consumer-credentials/specs/consumer-management/spec.md#requirement-a-new-key-is-shown-exactly-once-req-cred-002
	 */
	public function generate(string $label, ?string $expiresAt = null, ?DateTimeImmutable $now = null): array {
		$key = $this->random->generate(self::KEY_LENGTH, ISecureRandom::CHAR_ALPHANUMERIC);

		return ['key' => $key, 'credential' => $this->apiKeyEntry(key: $key, label: $label, expiresAt: $expiresAt, now: $now)];
	}//end generate()

	/**
	 * The index of the live API key credential that matches a key, or null.
	 *
	 * @param mixed             $credentials The consumer's `credentials` value.
	 * @param string            $key         The presented key.
	 * @param DateTimeImmutable $now         The moment of the call.
	 *
	 * @return int|null
	 *
	 * @spec openspec/changes/access-consumer-credentials/specs/consumer-management/spec.md#requirement-a-key-can-be-replaced-without-downtime-req-cred-003
	 */
	public function matchingApiKey(mixed $credentials, string $key, DateTimeImmutable $now): ?int {
		if ($key === '' || is_array($credentials) === false || array_is_list($credentials) === false) {
			return null;
		}

		$hash = $this->hashKey(key: $key);
		foreach ($credentials as $index => $credential) {
			if (is_array($credential) === false || ($credential['type'] ?? '') !== self::TYPE_API_KEY) {
				continue;
			}

			$stored = ($credential['keyHash'] ?? '');
			if (is_string($stored) === false || $stored === '' || hash_equals($stored, $hash) === false) {
				continue;
			}

			if ($this->isLive(credential: $credential, now: $now) === true) {
				return $index;
			}
		}

		return null;
	}//end matchingApiKey()

	/**
	 * Whether a credential is not expired at a moment.
	 *
	 * An expiry that cannot be read counts as expired: a date the
	 * administrator meant to stop a key on never keeps it alive.
	 *
	 * @param array<string, mixed> $credential The credential.
	 * @param DateTimeImmutable    $now        The moment.
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/access-consumer-credentials/specs/consumer-management/spec.md#requirement-a-key-can-be-replaced-without-downtime-req-cred-003
	 */
	public function isLive(array $credential, DateTimeImmutable $now): bool {
		$expiresAt = ($credential['expiresAt'] ?? null);
		if ($expiresAt === null || $expiresAt === '') {
			return true;
		}

		if (is_string($expiresAt) === false) {
			return false;
		}

		$expiry = date_create_immutable($expiresAt);
		if ($expiry === false) {
			return false;
		}

		return $expiry > $now;
	}//end isLive()

	/**
	 * The credential with `lastUsedAt` set to now, or null when it was written less than a minute ago.
	 *
	 * @param array<string, mixed> $credential The credential.
	 * @param DateTimeImmutable    $now        The moment of the call.
	 *
	 * @return array<string, mixed>|null
	 *
	 * @spec openspec/changes/access-consumer-credentials/specs/consumer-management/spec.md#requirement-a-key-can-be-replaced-without-downtime-req-cred-003
	 */
	public function touched(array $credential, DateTimeImmutable $now): ?array {
		$last = ($credential['lastUsedAt'] ?? null);
		if (is_string($last) === true && $last !== '') {
			$lastUsed = date_create_immutable($last);
			if ($lastUsed !== false && ($now->getTimestamp() - $lastUsed->getTimestamp()) < self::LAST_USED_INTERVAL) {
				return null;
			}
		}

		$credential['lastUsedAt'] = $now->format(DateTimeInterface::ATOM);

		return $credential;
	}//end touched()

	/**
	 * A credential as it may be shown: everything but the hash.
	 *
	 * @param array<string, mixed> $credential The stored credential.
	 *
	 * @return array<string, mixed>
	 *
	 * @spec openspec/changes/access-consumer-credentials/specs/consumer-management/spec.md#requirement-a-new-key-is-shown-exactly-once-req-cred-002
	 */
	public static function shown(array $credential): array {
		unset($credential['keyHash']);

		return $credential;
	}//end shown()

	/**
	 * The instance pepper, generated and stored as a sensitive app config value on first use.
	 *
	 * @return string
	 */
	private function pepper(): string {
		if ($this->pepper !== null) {
			return $this->pepper;
		}

		$pepper = $this->appConfig->getValueString(AppIdentity::APP_ID, self::PEPPER_KEY, '', lazy: true);
		if ($pepper === '') {
			$pepper = $this->random->generate(self::PEPPER_LENGTH, ISecureRandom::CHAR_ALPHANUMERIC);
			$this->appConfig->setValueString(AppIdentity::APP_ID, self::PEPPER_KEY, $pepper, lazy: true, sensitive: true);
		}

		$this->pepper = $pepper;

		return $pepper;
	}//end pepper()
}//end class

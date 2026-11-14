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
use OCA\Integriq\Service\Auth\ClientCertificate;
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
		$list = self::credentialList(credentials: $credentials);
		if ($key === '' || $list === null) {
			return null;
		}

		return $this->firstLiveApiKey(list: $list, hash: $this->hashKey(key: $key), now: $now);
	}//end matchingApiKey()

	/**
	 * The index of the first live API key credential in the list whose hash is the given one.
	 *
	 * @param list<mixed>       $list The credentials.
	 * @param string            $hash The presented key's hash.
	 * @param DateTimeImmutable $now  The moment of the call.
	 *
	 * @return int|null
	 */
	private function firstLiveApiKey(array $list, string $hash, DateTimeImmutable $now): ?int {
		foreach ($list as $index => $credential) {
			if ($this->isLiveApiKeyFor(credential: $credential, hash: $hash, now: $now) === true) {
				return $index;
			}
		}

		return null;
	}//end firstLiveApiKey()

	/**
	 * The credentials as a list, or null when the value is not one.
	 *
	 * @param mixed $credentials The consumer's `credentials` value.
	 *
	 * @return list<mixed>|null
	 */
	private static function credentialList(mixed $credentials): ?array {
		if (is_array($credentials) === false || array_is_list($credentials) === false) {
			return null;
		}

		return $credentials;
	}//end credentialList()

	/**
	 * Whether a value is a credential of the given type.
	 *
	 * @param mixed  $credential The value.
	 * @param string $type       The credential type.
	 *
	 * @return bool
	 */
	private static function isOfType(mixed $credential, string $type): bool {
		return is_array($credential) === true && ($credential['type'] ?? '') === $type;
	}//end isOfType()

	/**
	 * Whether a value is a non-empty string.
	 *
	 * @param mixed $value The value.
	 *
	 * @return bool
	 */
	private static function isFilled(mixed $value): bool {
		return is_string($value) === true && $value !== '';
	}//end isFilled()

	/**
	 * Whether a value is a live API key credential whose stored hash is the given one.
	 *
	 * @param mixed             $credential The value.
	 * @param string            $hash       The presented key's hash.
	 * @param DateTimeImmutable $now        The moment of the call.
	 *
	 * @return bool
	 */
	private function isLiveApiKeyFor(mixed $credential, string $hash, DateTimeImmutable $now): bool {
		if (self::isOfType(credential: $credential, type: self::TYPE_API_KEY) === false) {
			return false;
		}

		$stored = ($credential['keyHash'] ?? '');
		if (self::isFilled(value: $stored) === false || hash_equals((string) $stored, $hash) === false) {
			return false;
		}

		return $this->isLive(credential: $credential, now: $now);
	}//end isLiveApiKeyFor()

	/**
	 * A client certificate credential.
	 *
	 * It pins the certificate itself by its SHA-256 fingerprint, or, with an
	 * issuing CA and a subject pattern, every certificate that CA signed for a
	 * matching subject, so a renewed PKIoverheid certificate keeps working.
	 * The pinned material is public; nothing on the entry is a secret.
	 *
	 * @param string                 $label             The label.
	 * @param ClientCertificate|null $certificate       The certificate to pin by fingerprint, or null.
	 * @param ClientCertificate|null $issuer            The issuing CA, with a subject pattern, or null.
	 * @param string|null            $subjectPattern    Shell pattern on the one-line subject, e.g. `/C=NL/O=Gemeente/serialNumber=0000*`.
	 * @param string|null            $expiresAt         ISO-8601 expiry, or null for none.
	 * @param DateTimeImmutable|null $now               The moment of creation.
	 *
	 * @return array<string, mixed>|null The entry, or null when neither pin is complete.
	 *
	 * @spec openspec/changes/access-consumer-credentials/specs/authorization-jwt/spec.md#requirement-an-endpoint-can-require-a-client-certificate-req-cred-004
	 */
	public function certificateEntry(
		string $label,
		?ClientCertificate $certificate = null,
		?ClientCertificate $issuer = null,
		?string $subjectPattern = null,
		?string $expiresAt = null,
		?DateTimeImmutable $now = null,
	): ?array {
		$entry = [
			'id'         => RandomUuid::version4(),
			'label'      => $label,
			'type'       => self::TYPE_CLIENT_CERTIFICATE,
			'createdAt'  => ($now ?? new DateTimeImmutable())->format(DateTimeInterface::ATOM),
			'expiresAt'  => $expiresAt,
			'lastUsedAt' => null,
		];

		if ($certificate !== null) {
			return $entry + [
				'fingerprint' => $certificate->fingerprint,
				'subject'     => $certificate->subject,
				'oin'         => $certificate->oin,
			];
		}

		$issuerPin = self::issuerPin(issuer: $issuer, subjectPattern: (string) $subjectPattern);
		if ($issuerPin === null) {
			return null;
		}

		return $entry + $issuerPin;
	}//end certificateEntry()

	/**
	 * The issuer pin fields of a certificate entry, or null when the pin is incomplete.
	 *
	 * @param ClientCertificate|null $issuer         The issuing CA.
	 * @param string                 $subjectPattern The subject pattern; empty when none was given.
	 *
	 * @return array<string, string>|null
	 */
	private static function issuerPin(?ClientCertificate $issuer, string $subjectPattern): ?array {
		if ($issuer === null || trim($subjectPattern) === '') {
			return null;
		}

		return [
			'issuerCertificate' => $issuer->pem,
			'issuerFingerprint' => $issuer->fingerprint,
			'issuer'            => $issuer->subject,
			'subjectPattern'    => trim($subjectPattern),
		];
	}//end issuerPin()

	/**
	 * The index of the live client certificate credential a certificate matches, or null.
	 *
	 * A certificate outside its validity never matches. A fingerprint pin
	 * matches that certificate only; an issuer pin matches a certificate whose
	 * signature the pinned CA verifies and whose subject fits the pattern.
	 *
	 * @param mixed             $credentials The consumer's `credentials` value.
	 * @param ClientCertificate $certificate The presented, web-server-verified certificate.
	 * @param DateTimeImmutable $now         The moment of the call.
	 *
	 * @return int|null
	 *
	 * @spec openspec/changes/access-consumer-credentials/specs/authorization-jwt/spec.md#requirement-an-endpoint-can-require-a-client-certificate-req-cred-004
	 */
	public function matchingCertificate(mixed $credentials, ClientCertificate $certificate, DateTimeImmutable $now): ?int {
		$list = self::credentialList(credentials: $credentials);
		if ($list === null || $certificate->isValidAt(now: $now->getTimestamp()) === false) {
			return null;
		}

		return $this->firstLivePin(list: $list, certificate: $certificate, now: $now);
	}//end matchingCertificate()

	/**
	 * The index of the first live client certificate credential in the list that pins the certificate.
	 *
	 * @param list<mixed>       $list        The credentials.
	 * @param ClientCertificate $certificate The certificate.
	 * @param DateTimeImmutable $now         The moment of the call.
	 *
	 * @return int|null
	 */
	private function firstLivePin(array $list, ClientCertificate $certificate, DateTimeImmutable $now): ?int {
		foreach ($list as $index => $credential) {
			if ($this->isLivePinFor(credential: $credential, certificate: $certificate, now: $now) === true) {
				return $index;
			}
		}

		return null;
	}//end firstLivePin()

	/**
	 * Whether a value is a live client certificate credential that pins the certificate.
	 *
	 * @param mixed             $credential  The value.
	 * @param ClientCertificate $certificate The certificate.
	 * @param DateTimeImmutable $now         The moment of the call.
	 *
	 * @return bool
	 */
	private function isLivePinFor(mixed $credential, ClientCertificate $certificate, DateTimeImmutable $now): bool {
		if (self::isOfType(credential: $credential, type: self::TYPE_CLIENT_CERTIFICATE) === false) {
			return false;
		}

		return self::pins(credential: $credential, certificate: $certificate) === true
			&& $this->isLive(credential: $credential, now: $now) === true;
	}//end isLivePinFor()

	/**
	 * Whether one client certificate credential pins a certificate.
	 *
	 * @param array<string, mixed> $credential  The credential.
	 * @param ClientCertificate    $certificate The certificate.
	 *
	 * @return bool
	 */
	private static function pins(array $credential, ClientCertificate $certificate): bool {
		$fingerprint = ($credential['fingerprint'] ?? null);
		if (self::isFilled(value: $fingerprint) === true) {
			return hash_equals(strtolower((string) $fingerprint), $certificate->fingerprint);
		}

		return self::issuerPins(credential: $credential, certificate: $certificate);
	}//end pins()

	/**
	 * Whether an issuer pin covers a certificate: the subject fits and the CA verifies it.
	 *
	 * @param array<string, mixed> $credential  The credential.
	 * @param ClientCertificate    $certificate The certificate.
	 *
	 * @return bool
	 */
	private static function issuerPins(array $credential, ClientCertificate $certificate): bool {
		$issuer  = ($credential['issuerCertificate'] ?? null);
		$pattern = ($credential['subjectPattern'] ?? null);
		if (self::isFilled(value: $issuer) === false || self::isFilled(value: $pattern) === false) {
			return false;
		}

		if (fnmatch((string) $pattern, $certificate->subject, FNM_NOESCAPE) === false) {
			return false;
		}

		return @openssl_x509_verify($certificate->pem, (string) $issuer) === 1;
	}//end issuerPins()

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
		if (self::usedWithinInterval(last: ($credential['lastUsedAt'] ?? null), now: $now) === true) {
			return null;
		}

		$credential['lastUsedAt'] = $now->format(DateTimeInterface::ATOM);

		return $credential;
	}//end touched()

	/**
	 * Whether a stored `lastUsedAt` is less than the write interval before now.
	 *
	 * @param mixed             $last The stored value.
	 * @param DateTimeImmutable $now  The moment of the call.
	 *
	 * @return bool
	 */
	private static function usedWithinInterval(mixed $last, DateTimeImmutable $now): bool {
		if (self::isFilled(value: $last) === false) {
			return false;
		}

		$lastUsed = date_create_immutable((string) $last);

		return $lastUsed !== false && ($now->getTimestamp() - $lastUsed->getTimestamp()) < self::LAST_USED_INTERVAL;
	}//end usedWithinInterval()

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

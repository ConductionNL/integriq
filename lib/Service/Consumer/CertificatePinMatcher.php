<?php

/**
 * Integriq CertificatePinMatcher: finds the client certificate credential that pins a certificate.
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
use OCA\Integriq\Service\Auth\ClientCertificate;
use OCA\Integriq\Service\Helper\QuietCall;

/**
 * Matches a certificate against a consumer's client certificate credentials.
 *
 * @spec openspec/changes/access-consumer-credentials/specs/authorization-jwt/spec.md#requirement-an-endpoint-can-require-a-client-certificate-req-cred-004
 */
class CertificatePinMatcher {

	/**
	 * Constructor.
	 *
	 * @param ConsumerCredentials $credentials Says whether a credential is live.
	 */
	public function __construct(
		private readonly ConsumerCredentials $credentials,
	) {
	}//end __construct()

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
	public function matching(mixed $credentials, ClientCertificate $certificate, DateTimeImmutable $now): ?int {
		$list = self::credentialList(credentials: $credentials);
		if ($list === null || $certificate->isValidAt(now: $now->getTimestamp()) === false) {
			return null;
		}

		return $this->firstLivePin(list: $list, certificate: $certificate, now: $now);
	}//end matching()

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
		if (is_array($credential) === false || ($credential['type'] ?? '') !== ConsumerCredentials::TYPE_CLIENT_CERTIFICATE) {
			return false;
		}

		return self::pins(credential: $credential, certificate: $certificate) === true
			&& $this->credentials->isLive(credential: $credential, now: $now) === true;
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

		return (new QuietCall())->run(static fn (): int|false => openssl_x509_verify($certificate->pem, (string) $issuer)) === 1;
	}//end issuerPins()

	/**
	 * The credentials list, or null when the value is not a list.
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
	 * Whether a value is a non-empty string.
	 *
	 * @param mixed $value The value.
	 *
	 * @return bool
	 */
	private static function isFilled(mixed $value): bool {
		return is_string($value) === true && $value !== '';
	}//end isFilled()
}//end class

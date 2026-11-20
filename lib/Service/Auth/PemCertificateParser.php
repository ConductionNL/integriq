<?php

/**
 * Integriq PemCertificateParser.
 *
 * Reads one PEM-encoded X.509 certificate into the fields a credential pins:
 * its SHA-256 fingerprint, subject and issuer on one line, validity and the
 * OIN of a PKIoverheid subject.
 *
 * @category Service
 * @package  OCA\Integriq\Service\Auth
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/access-consumer-credentials/specs/authorization-jwt/spec.md#requirement-an-endpoint-can-require-a-client-certificate-req-cred-004
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Integriq\Service\Auth;

use OCA\Integriq\Service\Helper\QuietCall;

/**
 * Parses PEM certificates.
 *
 * @spec openspec/changes/access-consumer-credentials/specs/authorization-jwt/spec.md#requirement-an-endpoint-can-require-a-client-certificate-req-cred-004
 */
class PemCertificateParser {

	/**
	 * An OIN is twenty digits.
	 */
	private const OIN_PATTERN = '/^\d{20}$/';

	/**
	 * Parse one PEM certificate; null when it is not one.
	 *
	 * @param string $pem The certificate, PEM encoded.
	 *
	 * @return ClientCertificate|null
	 *
	 * @spec openspec/changes/access-consumer-credentials/specs/authorization-jwt/spec.md#requirement-an-endpoint-can-require-a-client-certificate-req-cred-004
	 */
	public function parse(string $pem): ?ClientCertificate {
		$pem = self::normalisePem(pem: $pem);
		if ($pem === null) {
			return null;
		}

		$quiet       = new QuietCall();
		$parsed      = $quiet->run(static fn (): array|false => openssl_x509_parse($pem));
		$fingerprint = $quiet->run(static fn (): string|false => openssl_x509_fingerprint($pem, 'sha256'));
		if (is_array($parsed) === false || is_string($fingerprint) === false) {
			return null;
		}

		$subject = (array)($parsed['subject'] ?? []);

		return new ClientCertificate(
			pem: $pem,
			fingerprint: strtolower($fingerprint),
			subject: self::oneLine(name: $subject),
			issuer: self::oneLine(name: (array)($parsed['issuer'] ?? [])),
			validFrom: (int)($parsed['validFrom_time_t'] ?? 0),
			validTo: (int)($parsed['validTo_time_t'] ?? 0),
			oin: self::oinOf(subject: $subject)
		);
	}//end parse()

	/**
	 * The OIN in a subject's serial number, or null when it holds none.
	 *
	 * @param array<mixed> $subject The parsed subject.
	 *
	 * @return string|null
	 */
	private static function oinOf(array $subject): ?string {
		$serial = ($subject['serialNumber'] ?? null);
		if (is_string($serial) === true && preg_match(self::OIN_PATTERN, $serial) === 1) {
			return $serial;
		}

		return null;
	}//end oinOf()

	/**
	 * Rebuild a PEM from its base64 body, whatever whitespace it arrived with.
	 *
	 * @param string $pem A PEM, possibly with its newlines replaced.
	 *
	 * @return string|null
	 */
	private static function normalisePem(string $pem): ?string {
		if (preg_match('/-----BEGIN CERTIFICATE-----(.+?)-----END CERTIFICATE-----/s', $pem, $match) !== 1) {
			return null;
		}

		$body = preg_replace('/\s+/', '', $match[1]);
		if ($body === null || $body === '' || base64_decode($body, true) === false) {
			return null;
		}

		return "-----BEGIN CERTIFICATE-----\n" . chunk_split($body, 64, "\n") . "-----END CERTIFICATE-----\n";
	}//end normalisePem()

	/**
	 * A distinguished name as one line, `/C=NL/O=Gemeente/serialNumber=...`.
	 *
	 * @param array<string,string|array<int,string>> $name The parsed name.
	 *
	 * @return string
	 */
	private static function oneLine(array $name): string {
		$line = '';
		foreach ($name as $key => $value) {
			foreach ((array)$value as $part) {
				$line .= '/' . $key . '=' . (string)$part;
			}
		}

		return $line;
	}//end oneLine()
}//end class

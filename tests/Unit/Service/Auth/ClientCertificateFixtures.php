<?php

/**
 * Client certificates for the mTLS tests, made with OpenSSL at test time so
 * nothing expires in the repository: two issuing CAs and leaf certificates
 * signed by the first, one with a PKIoverheid-style subject carrying an OIN.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Service\Auth
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec exclude test support, not product behaviour
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Service\Auth;

/**
 * Builds and caches the fixture certificates for one test run.
 */
final class ClientCertificateFixtures {

	/**
	 * The OIN in the PKIoverheid-style subject.
	 */
	public const OIN = '00000001234567890000';

	/**
	 * @var array<string,string>|null
	 */
	private static ?array $pems = null;

	/**
	 * The fixture certificates, PEM encoded: `ca`, `otherCa`, `pkio` (signed by
	 * `ca`, subject with the OIN), `plain` (signed by `ca`), `rogue` (signed by
	 * `otherCa`, same subject as `pkio`).
	 *
	 * @return array<string,string>
	 */
	public static function pems(): array {
		if (self::$pems !== null) {
			return self::$pems;
		}

		$config = ['digest_alg' => 'sha256', 'private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA];
		[$ca, $caKey]           = self::selfSigned(cn: 'Fixture Staat der Nederlanden CA', config: $config);
		[$otherCa, $otherCaKey] = self::selfSigned(cn: 'Fixture Other CA', config: $config);
		$pkioSubject = ['countryName' => 'NL', 'organizationName' => 'Gemeente Voorbeeld', 'commonName' => 'zaaksysteem.voorbeeld.nl', 'serialNumber' => self::OIN];

		self::$pems = [
			'ca' => $ca,
			'otherCa' => $otherCa,
			'pkio' => self::signed(subject: $pkioSubject, ca: $ca, caKey: $caKey, config: $config, serial: 2),
			'plain' => self::signed(subject: ['countryName' => 'NL', 'commonName' => 'client.example.nl'], ca: $ca, caKey: $caKey, config: $config, serial: 3),
			'rogue' => self::signed(subject: $pkioSubject, ca: $otherCa, caKey: $otherCaKey, config: $config, serial: 4),
		];

		return self::$pems;
	}//end pems()

	/**
	 * A self-signed CA certificate and its key.
	 *
	 * @param string              $cn     The common name.
	 * @param array<string,mixed> $config OpenSSL options.
	 *
	 * @return array{0: string, 1: \OpenSSLAsymmetricKey}
	 */
	private static function selfSigned(string $cn, array $config): array {
		$key = openssl_pkey_new($config);
		$csr = openssl_csr_new(['countryName' => 'NL', 'commonName' => $cn], $key, $config);
		$certificate = openssl_csr_sign($csr, null, $key, 30, $config, 1);
		openssl_x509_export($certificate, $pem);

		return [$pem, $key];
	}//end selfSigned()

	/**
	 * A certificate for a subject, signed by a CA.
	 *
	 * @param array<string,string>  $subject The subject.
	 * @param string                $ca      The CA certificate, PEM.
	 * @param \OpenSSLAsymmetricKey $caKey   The CA key.
	 * @param array<string,mixed>   $config  OpenSSL options.
	 * @param int                   $serial  The serial number.
	 *
	 * @return string
	 */
	private static function signed(array $subject, string $ca, \OpenSSLAsymmetricKey $caKey, array $config, int $serial): string {
		$key = openssl_pkey_new($config);
		$csr = openssl_csr_new($subject, $key, $config);
		$certificate = openssl_csr_sign($csr, $ca, $caKey, 30, $config, $serial);
		openssl_x509_export($certificate, $pem);

		return $pem;
	}//end signed()
}//end class

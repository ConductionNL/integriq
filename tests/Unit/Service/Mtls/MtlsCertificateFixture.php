<?php

/**
 * OpenConnector mTLS test certificate fixtures.
 *
 * Generates small, throwaway self-signed certificates/keys at test-runtime
 * via PHP's `openssl_*` functions — never committed binary certs, per the
 * change's design.md "Testing strategy".
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Service\Mtls
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 *
 * @spec openspec/specs/mtls-client-certificate-transport/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Service\Mtls;

/**
 * Trait providing self-signed certificate/key fixtures for mTLS tests.
 */
trait MtlsCertificateFixture {
	/**
	 * Generate a valid, unprotected self-signed certificate + private key pair.
	 *
	 * @param integer $validDays Days the certificate remains valid for (negative = already expired).
	 *
	 * @return array{certificatePem: string, privateKeyPem: string}
	 */
	private function generateCertificateAndKey(int $validDays = 365): array {
		$privateKey = openssl_pkey_new(
			[
				'private_key_bits' => 2048,
				'private_key_type' => OPENSSL_KEYTYPE_RSA,
			]
		);

		$csr = openssl_csr_new(['commonName' => 'mtls-test.example.nl'], $privateKey);
		$cert = openssl_csr_sign($csr, null, $privateKey, $validDays, [], random_int(1000, 999999));

		openssl_x509_export($cert, $certificatePem);
		openssl_pkey_export($privateKey, $privateKeyPem);

		return [
			'certificatePem' => $certificatePem,
			'privateKeyPem' => $privateKeyPem,
		];

	}//end generateCertificateAndKey()

	/**
	 * Generate a valid self-signed certificate + a passphrase-protected private key.
	 *
	 * @param string $passphrase The passphrase to encrypt the private key with.
	 *
	 * @return array{certificatePem: string, privateKeyPem: string}
	 */
	private function generateCertificateAndEncryptedKey(string $passphrase): array {
		$privateKey = openssl_pkey_new(
			[
				'private_key_bits' => 2048,
				'private_key_type' => OPENSSL_KEYTYPE_RSA,
			]
		);

		$csr = openssl_csr_new(['commonName' => 'mtls-test.example.nl'], $privateKey);
		$cert = openssl_csr_sign($csr, null, $privateKey, 365, [], random_int(1000, 999999));

		openssl_x509_export($cert, $certificatePem);
		openssl_pkey_export($privateKey, $privateKeyPem, $passphrase);

		return [
			'certificatePem' => $certificatePem,
			'privateKeyPem' => $privateKeyPem,
		];

	}//end generateCertificateAndEncryptedKey()

	/**
	 * A deterministic, already-expired (validity 2020-01-01 → 2020-01-02)
	 * self-signed certificate + unprotected key. `openssl_csr_sign()` does
	 * not accept a negative `$days` to synthesize an expired cert at
	 * runtime, so this pair is pre-generated once (throwaway test-only key,
	 * never used for anything but this fixture) and hardcoded here.
	 *
	 * @return array{certificatePem: string, privateKeyPem: string}
	 */
	private function expiredCertificateAndKey(): array {
		$certificatePem = <<<PEM
        -----BEGIN CERTIFICATE-----
        MIICxjCCAa6gAwIBAgIUHqdfZKgTuWScVBlGlRQC3yrZtDMwDQYJKoZIhvcNAQEL
        BQAwHTEbMBkGA1UEAwwSZXhwaXJlZC5leGFtcGxlLm5sMB4XDTIwMDEwMTAwMDAw
        MFoXDTIwMDEwMjAwMDAwMFowHTEbMBkGA1UEAwwSZXhwaXJlZC5leGFtcGxlLm5s
        MIIBIjANBgkqhkiG9w0BAQEFAAOCAQ8AMIIBCgKCAQEAlK+B2YG+xOu3RQtCpUtI
        cRj1NNlRtZJx/P9Wmkp1vfAhCY6UBWa6z7076KU1rVSZBtQIV3MEP56pfwgjvANl
        4YLvLmtscC8cTtt8+KACQuY3AtsdxUl7pY1dSDvgnJkfzi7F5TcTm0sumv/ZOruN
        +9tkuIFtr+kHvRtEsLbo3ScCqUQjLTKa4Z6mKfEP/ueBUreRcSaUlLSib2/Cvmyr
        FDOrDnztejfRbn5NrwTwdXQUdB9Gkd45ZoSuUGq3AVkYeMOjmYMFyRB/6fkVHpTu
        iho/1xYeKi2Zg8HPS71gTz3GxKo/L6+MhRluv8lT3ohlMq9AnAWKQlwpQmhRUEmM
        UwIDAQABMA0GCSqGSIb3DQEBCwUAA4IBAQAAB7gcwXGXbHhoBBIMlaKj7FN9wkMm
        hAtDHnWQRShuba/f11Baq6V5zYs81UXivZsxf7vQIbBUqpIe/9/z6eRCE1PF2x/X
        o32Nf+e6+EUq2kf9705NcBlHAl+3ozpBHAZiFqC6XNjzM9c4UmXGTGWLBpxXSv7r
        0oSF6yYRhca1IpWQJVCSM8HPVG7JG8UAKUQ3VxD2Qg+Q1J8PV7pz4lVehOk/WlBL
        FJXYqluJqbv1HMh6NoC4lPbH9mmkG6wegyVMze630yeVB71XrHyV2lTmVijd+pPq
        qqaNKEIomH752JIuY/yqT9UU/gsJ9FLMzmJ4W+Sx0dVCAhLZOuowHZLJ
        -----END CERTIFICATE-----
        PEM;

		$privateKeyPem = <<<PEM
        -----BEGIN RSA PRIVATE KEY-----
        MIIEogIBAAKCAQEAlK+B2YG+xOu3RQtCpUtIcRj1NNlRtZJx/P9Wmkp1vfAhCY6U
        BWa6z7076KU1rVSZBtQIV3MEP56pfwgjvANl4YLvLmtscC8cTtt8+KACQuY3Atsd
        xUl7pY1dSDvgnJkfzi7F5TcTm0sumv/ZOruN+9tkuIFtr+kHvRtEsLbo3ScCqUQj
        LTKa4Z6mKfEP/ueBUreRcSaUlLSib2/CvmyrFDOrDnztejfRbn5NrwTwdXQUdB9G
        kd45ZoSuUGq3AVkYeMOjmYMFyRB/6fkVHpTuiho/1xYeKi2Zg8HPS71gTz3GxKo/
        L6+MhRluv8lT3ohlMq9AnAWKQlwpQmhRUEmMUwIDAQABAoIBAA+6pVzyKdFBMmke
        cNV1qls0jbQijU9NB7wA8xNtbxlBzuzo5WXQ4VBar3zEBXKpsWqUgbFmnHtyeHHU
        1ZrtLRj3NgBOIvGXOkJpW8Ydfz++hPFLZtHZHTh9RPIAS5mlZUT1k7/U3MEf6fVB
        vaRF9RZAtl4Cay0h1My/lruvDeFfNtRCm/sV4/11ckGN31ohi3tIe3t+kUFFWt+9
        L1chaqlQ0LNLOJ0QUWpsA63EfK73j17YORzzkfj28kEnLt9MlAdpi18cAPhsOl/A
        KELfCaTwXhwFR8yod+whqvQGHTFvAaw5S5urTzSMiSAuYkD1RnzPhYWdQGENnM0M
        E/BHG6kCgYEAxHca7/+oyGiuzz52kTsxKOiFUx2tIDLna1C6yC0IhN/7kiL5y/TC
        l17tdMGLptbX1QOUK8xsqOdO18xK0a43zFAciaxf5LVYe7DSaTCgnn6HVHO4cK2p
        obW3Pa+yd2W5CCdPvXzjc0E9LNzCBFft4gSuYgx/GAUUXBnOaD3kex0CgYEAwb3e
        TrK7JqXuoayXOeZHGueFQloLk1S+n+jB77y//TC7CENJFqenIam/hERaEcW937co
        oKeCXjjx/7h3xqjHcny1nufOYjvP1yz7sM1zDoEkZtBHo+Ng1L2y3n+bpbBQh2dL
        9uYzH6iG76DzgLvnTLZd1Xjcl5iYj7psA2xOGi8CgYA/jxDF5/3gqA01us18+ptS
        0rafRxCFRDKeA0YKEJea9SNcCbiqhQqXEfzcMulNFmBI55l9+eqFUh+trMffwe8H
        SDuTRpLXWNvBWFkZ8sNjwydg6PcYbPZd/H3FlRu1iNEtlBo2ATtMRCTYaKrT4OZy
        onUP/172lF4b1bVy/+L0+QKBgGtJfYYPK9xnHxKgxM3nW5DmjMEFpEteLoHXHy5n
        U9od1jTpLdxB0yetHMVeJJKa8l6kyvbMTEFpP3ng6VM1u90Gen0Y2Q1FGf+KhYaO
        /xwVH9dfl4yGKeUld5wHBmZmkPGqnkKHj+AEL1UbyDyN0bSFeMjyORYqBrHxBkeO
        /mE5AoGAGQ8nTwtJsqNgkQC00l2gy9QQ31TCFxrhrScHTnNOef8Tut6u6680KgBX
        GkYKtOA9eWo8Xegs2puv9bwlIYxO9v3+xSEs0eNGM3G0fUd+LBOKPiqjpNdKLCD5
        2CC/3Dfgx3kSFgVhVcfGAfOLd2dXTc8Vgw9BL0nVtsIH2OMrltk=
        -----END RSA PRIVATE KEY-----
        PEM;

		return [
			'certificatePem' => $certificatePem,
			'privateKeyPem' => $privateKeyPem,
		];

	}//end expiredCertificateAndKey()
}//end trait

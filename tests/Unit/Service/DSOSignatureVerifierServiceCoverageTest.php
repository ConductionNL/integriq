<?php

/**
 * Additional unit tests for DSOSignatureVerifierService (fail-closed paths).
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 *
 * @spec openspec/changes/dso-stam-pkioverheid-signature-verification/tasks.md#task-1
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Service;

use OCA\Integriq\Service\DSOSignatureVerifierService;
use OCA\Integriq\Service\WebhookSignatureService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Fail-closed behaviour of the DSO STAM signature verifier: exceptions during
 * verification, unparseable or expired signing certificates, and every
 * admin save-time chain validation error message.
 *
 * Certificates are generated locally with PHP's openssl_* functions so the
 * assertions exercise real X.509 parsing and chain-of-trust validation.
 *
 * @covers \OCA\Integriq\Service\DSOSignatureVerifierService
 * @uses   \OCA\Integriq\Service\WebhookSignatureService
 *
 * @spec openspec/changes/dso-stam-pkioverheid-signature-verification/tasks.md#task-1
 */
class DSOSignatureVerifierServiceCoverageTest extends TestCase {

	/**
	 * PEM-encoded self-signed root CA certificate.
	 *
	 * @var string
	 */
	private static string $rootCaPem;

	/**
	 * PEM-encoded leaf certificate signed by the root CA, currently valid.
	 *
	 * @var string
	 */
	private static string $signingCertPem;

	/**
	 * PEM-encoded private key of both leaf certificates.
	 *
	 * @var string
	 */
	private static string $signingKeyPem;

	/**
	 * PEM-encoded leaf certificate signed by the root CA, already expired.
	 *
	 * @var string
	 */
	private static string $expiredCertPem;

	/**
	 * @var LoggerInterface&MockObject
	 */
	private LoggerInterface $logger;

	/**
	 * @var DSOSignatureVerifierService
	 */
	private DSOSignatureVerifierService $service;

	/**
	 * Build a local root CA with one valid and one expired leaf certificate.
	 *
	 * @return void
	 */
	public static function setUpBeforeClass(): void {
		parent::setUpBeforeClass();

		$rootKey = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
		$rootCert = openssl_csr_sign(openssl_csr_new(['commonName' => 'Coverage Test Root CA'], $rootKey), null, $rootKey, 3650);
		$rootCaPem = '';
		openssl_x509_export($rootCert, $rootCaPem);
		self::$rootCaPem = $rootCaPem;

		$leafKey = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
		$leafKeyPem = '';
		openssl_pkey_export($leafKey, $leafKeyPem);
		self::$signingKeyPem = $leafKeyPem;

		$validCert = openssl_csr_sign(openssl_csr_new(['commonName' => 'valid.test'], $leafKey), $rootCert, $rootKey, 365);
		$validPem = '';
		openssl_x509_export($validCert, $validPem);
		self::$signingCertPem = $validPem;

		// A negative validity period yields a certificate whose notAfter lies one day in the past.
		$expiredCert = openssl_csr_sign(openssl_csr_new(['commonName' => 'expired.test'], $leafKey), $rootCert, $rootKey, -1);
		$expiredPem = '';
		openssl_x509_export($expiredCert, $expiredPem);
		self::$expiredCertPem = $expiredPem;

	}//end setUpBeforeClass()

	/**
	 * Set up test fixtures.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->logger = $this->createMock(LoggerInterface::class);
		$this->service = new DSOSignatureVerifierService(
			webhookSignatureService: new WebhookSignatureService($this->createMock(LoggerInterface::class)),
			logger: $this->logger,
		);

	}//end setUp()

	/**
	 * Sign a body with the leaf key and base64-encode the signature.
	 *
	 * @param string $body The body to sign.
	 *
	 * @return string
	 */
	private function sign(string $body): string {
		openssl_sign($body, $signature, openssl_pkey_get_private(self::$signingKeyPem), OPENSSL_ALGO_SHA256);
		return base64_encode($signature);
	}//end sign()

	/**
	 * An error raised during verification is logged and fails closed.
	 *
	 * @return void
	 */
	public function testExceptionDuringVerificationFailsClosedAndLogs(): void {
		$this->logger->expects($this->once())
			->method('warning')
			->with(
				'DSO STAM: signature verification raised an exception, failing closed',
				$this->callback(
					static fn (array $context): bool => str_contains($context['exception'], 'stdClass could not be converted to string')
				)
			);

		// A non-stringable secret makes the HMAC path throw an \Error.
		$trust = ['mode' => 'hmac', 'hmacSecret' => new \stdClass()];

		$this->assertFalse($this->service->verify('sha256=deadbeef', '{}', $trust));

	}//end testExceptionDuringVerificationFailsClosedAndLogs()

	/**
	 * An unparseable signing certificate fails closed without logging an exception.
	 *
	 * @return void
	 */
	public function testUnparseableSigningCertificateRejected(): void {
		$this->logger->expects($this->never())->method('warning');

		$body = '{"verzoekId":"garbage-cert"}';
		$trust = [
			'mode' => 'pkioverheid',
			'signingCertificate' => 'not a certificate',
			'rootCa' => self::$rootCaPem,
		];

		$this->assertFalse($this->service->verify($this->sign($body), $body, $trust));

	}//end testUnparseableSigningCertificateRejected()

	/**
	 * An expired signing certificate is rejected even though it chains to the
	 * trusted root and the signature over the body is mathematically valid.
	 *
	 * @return void
	 */
	public function testExpiredSigningCertificateRejected(): void {
		$body = '{"verzoekId":"expired-cert"}';
		$signature = $this->sign($body);

		$expiredTrust = ['mode' => 'pkioverheid', 'signingCertificate' => self::$expiredCertPem, 'rootCa' => self::$rootCaPem];
		$validTrust = ['mode' => 'pkioverheid', 'signingCertificate' => self::$signingCertPem, 'rootCa' => self::$rootCaPem];

		$this->assertFalse($this->service->isCertificateCurrentlyValid(self::$expiredCertPem));
		$this->assertFalse($this->service->verify($signature, $body, $expiredTrust));
		// Same key, same signature: only the validity window differs.
		$this->assertTrue($this->service->verify($signature, $body, $validTrust));

	}//end testExpiredSigningCertificateRejected()

	/**
	 * chainIsTrusted rejects an unparseable leaf and removes its temp files.
	 *
	 * @return void
	 */
	public function testChainIsTrustedRejectsUnparseableCertificate(): void {
		$before = glob(sys_get_temp_dir() . '/dso_pki_*');

		$this->assertFalse($this->service->chainIsTrusted('not a certificate', self::$rootCaPem));

		$this->assertSame($before, glob(sys_get_temp_dir() . '/dso_pki_*'), 'temp PEM files must be removed');

	}//end testChainIsTrustedRejectsUnparseableCertificate()

	/**
	 * An unparseable signing certificate is reported by validateChainConfig.
	 *
	 * @return void
	 */
	public function testValidateChainConfigReportsInvalidSigningCertificate(): void {
		$this->assertSame(
			['Signing certificate is not a valid X.509 PEM certificate.'],
			$this->service->validateChainConfig('not a certificate', self::$rootCaPem)
		);

	}//end testValidateChainConfigReportsInvalidSigningCertificate()

	/**
	 * An expired certificate and a missing root are both reported.
	 *
	 * @return void
	 */
	public function testValidateChainConfigReportsExpiredCertificateAndMissingRoot(): void {
		$this->assertSame(
			[
				'Signing certificate is expired or not yet valid.',
				'A trusted root CA certificate is required.',
			],
			$this->service->validateChainConfig(self::$expiredCertPem, '')
		);

	}//end testValidateChainConfigReportsExpiredCertificateAndMissingRoot()

	/**
	 * An unparseable root CA is reported.
	 *
	 * @return void
	 */
	public function testValidateChainConfigReportsInvalidRootCa(): void {
		$this->assertSame(
			['Root CA is not a valid X.509 PEM certificate.'],
			$this->service->validateChainConfig(self::$signingCertPem, 'not a certificate')
		);

	}//end testValidateChainConfigReportsInvalidRootCa()
}//end class

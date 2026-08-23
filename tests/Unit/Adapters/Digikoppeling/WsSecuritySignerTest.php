<?php

/**
 * Integriq — WS-Security signer/verifier tests.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Adapters\Digikoppeling
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @version GIT: <git-id>
 *
 * @link https://www.Integriq.nl
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Adapters\Digikoppeling;

use OCA\Integriq\Adapters\Digikoppeling\WsSecuritySigner;
use OCA\Integriq\Exception\DigikoppelingException;
use PHPUnit\Framework\TestCase;

/**
 * Real-crypto tests for WS-Security X.509 signing over a SOAP Body (REQ-DK-002).
 *
 * @spec openspec/specs/digikoppeling-adapter/spec.md
 */
class WsSecuritySignerTest extends TestCase {

	private const ENVELOPE = '<?xml version="1.0"?><soap:Envelope xmlns:soap="http://schemas.xmlsoap.org/soap/envelope/"><soap:Header/><soap:Body><vraag xmlns="http://stuf">payload</vraag></soap:Body></soap:Envelope>';

	private string $certPem = '';
	private string $keyPem = '';

	/**
	 * Generate a self-signed RSA keypair for the signing tests.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
		if ($key === false) {
			$this->markTestSkipped('openssl keypair generation unavailable in this environment');
		}

		$csr = openssl_csr_new(['commonName' => 'test-pkio'], $key);
		$x509 = openssl_csr_sign($csr, null, $key, 1);
		openssl_x509_export($x509, $this->certPem);
		openssl_pkey_export($key, $this->keyPem);
	}//end setUp()

	/**
	 * A signed envelope carries a WS-Security X.509 signature over the Body and
	 * verifies as valid.
	 *
	 * @return void
	 */
	public function testSignedEnvelopeVerifies(): void {
		$signer = new WsSecuritySigner();
		$signed = $signer->sign(self::ENVELOPE, $this->certPem, $this->keyPem);

		$this->assertStringContainsString('Signature', $signed);
		$this->assertStringContainsString('X509Certificate', $signed);
		$this->assertTrue($signer->verify($signed));
	}//end testSignedEnvelopeVerifies()

	/**
	 * A tampered Body invalidates the signature (rejected as a transport error).
	 *
	 * @return void
	 */
	public function testTamperedBodyIsRejected(): void {
		$signer = new WsSecuritySigner();
		$signed = $signer->sign(self::ENVELOPE, $this->certPem, $this->keyPem);

		$tampered = str_replace('payload', 'tampered', $signed);

		$this->assertFalse($signer->verify($tampered));
	}//end testTamperedBodyIsRejected()

	/**
	 * An unsigned envelope has no signature to verify.
	 *
	 * @return void
	 */
	public function testUnsignedEnvelopeIsRejected(): void {
		$signer = new WsSecuritySigner();

		$this->assertFalse($signer->verify(self::ENVELOPE));
	}//end testUnsignedEnvelopeIsRejected()

	/**
	 * Verifying against a different (wrong) certificate fails.
	 *
	 * @return void
	 */
	public function testWrongCertificateIsRejected(): void {
		$signer = new WsSecuritySigner();
		$signed = $signer->sign(self::ENVELOPE, $this->certPem, $this->keyPem);

		$otherKey = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
		$otherCsr = openssl_csr_new(['commonName' => 'other'], $otherKey);
		$otherX509 = openssl_csr_sign($otherCsr, null, $otherKey, 1);
		openssl_x509_export($otherX509, $otherCert);

		$this->assertFalse($signer->verify($signed, $otherCert));
	}//end testWrongCertificateIsRejected()

	/**
	 * Signing with invalid key material fails closed with a clear error.
	 *
	 * @return void
	 */
	public function testInvalidKeyMaterialThrows(): void {
		$this->expectException(DigikoppelingException::class);

		(new WsSecuritySigner())->sign(self::ENVELOPE, $this->certPem, 'not-a-key');
	}//end testInvalidKeyMaterialThrows()
}//end class

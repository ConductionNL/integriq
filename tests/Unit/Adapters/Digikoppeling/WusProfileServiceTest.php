<?php

/**
 * OpenConnector — WUS profile service tests.
 *
 * @category Test
 * @package  OCA\OpenConnector\Tests\Unit\Adapters\Digikoppeling
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
 * @link https://www.OpenConnector.nl
 */

declare(strict_types=1);

namespace OCA\OpenConnector\Tests\Unit\Adapters\Digikoppeling;

use OCA\OpenConnector\Adapters\Digikoppeling\PkiOverheidCredentialResolver;
use OCA\OpenConnector\Adapters\Digikoppeling\WsSecuritySigner;
use OCA\OpenConnector\Adapters\Digikoppeling\WusProfileService;
use OCA\OpenConnector\Exception\DigikoppelingException;
use PHPUnit\Framework\TestCase;

/**
 * Tests the WUS profile composition: sign a StUF body, verify the response,
 * fail closed when the broker cannot supply signing material (REQ-DK-002/005).
 *
 * @spec openspec/specs/digikoppeling-adapter/spec.md
 */
class WusProfileServiceTest extends TestCase {

	private string $certPem = '';
	private string $keyPem = '';

	/**
	 * Generate a keypair for the signing composition tests.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
		if ($key === false) {
			$this->markTestSkipped('openssl keypair generation unavailable');
		}

		$csr = openssl_csr_new(['commonName' => 'wus-test'], $key);
		$x509 = openssl_csr_sign($csr, null, $key, 1);
		openssl_x509_export($x509, $this->certPem);
		openssl_pkey_export($key, $this->keyPem);
	}//end setUp()

	/**
	 * Build a resolver that returns the test keypair as signing material.
	 *
	 * @return PkiOverheidCredentialResolver
	 */
	private function materialResolver(): PkiOverheidCredentialResolver {
		$container = $this->createMock(\Psr\Container\ContainerInterface::class);
		$cert = $this->certPem;
		$key = $this->keyPem;

		return new class($container, $cert, $key) extends PkiOverheidCredentialResolver {
			private string $c;
			private string $k;

			public function __construct($container, string $c, string $k) {
				parent::__construct($container);
				$this->c = $c;
				$this->k = $k;
			}

			public function resolveSigningMaterial(string $certificateRef): array {
				return ['certificatePem' => $this->c, 'privateKeyPem' => $this->k];
			}
		};
	}//end materialResolver()

	/**
	 * A StUF body is wrapped, signed, and its response verified end-to-end.
	 *
	 * @return void
	 */
	public function testBuildSignedRequestAndVerifyResponse(): void {
		$signer = new WsSecuritySigner();
		$service = new WusProfileService($signer, $this->materialResolver());

		$signed = $service->buildSignedRequest('pkio-ref', '<vraag xmlns="http://stuf">bsn</vraag>');
		$this->assertStringContainsString('Signature', $signed);
		$this->assertStringContainsString('vraag', $signed);

		// A correctly signed response (round-tripped) verifies.
		$this->assertSame($signed, $service->verifyResponse($signed));
	}//end testBuildSignedRequestAndVerifyResponse()

	/**
	 * A tampered / unsigned response is rejected as a transport error.
	 *
	 * @return void
	 */
	public function testUnsignedResponseRejected(): void {
		$service = new WusProfileService(new WsSecuritySigner(), $this->materialResolver());

		$this->expectException(DigikoppelingException::class);
		$service->verifyResponse('<soap:Envelope xmlns:soap="http://schemas.xmlsoap.org/soap/envelope/"><soap:Body/></soap:Envelope>');
	}//end testUnsignedResponseRejected()

	/**
	 * When the broker cannot supply signing material, request building fails
	 * closed (no plaintext fallback).
	 *
	 * @return void
	 */
	public function testFailsClosedWithoutMaterial(): void {
		$container = $this->createMock(\Psr\Container\ContainerInterface::class);
		$resolver = new class($container) extends PkiOverheidCredentialResolver {
			protected function resolveBroker(): ?object {
				return new class {
					/**
					 * Constrained proxy — no signing-material capability.
					 *
					 * @return array
					 */
					public function request(): array {
						return [];
					}
				};
			}
		};

		$service = new WusProfileService(new WsSecuritySigner(), $resolver);

		$this->expectException(DigikoppelingException::class);
		$service->buildSignedRequest('pkio-ref', '<vraag/>');
	}//end testFailsClosedWithoutMaterial()
}//end class

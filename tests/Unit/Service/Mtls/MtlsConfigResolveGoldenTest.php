<?php

/**
 * Golden tests for MtlsConfigResolver::resolve.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Service\Mtls
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Service\Mtls;

use OCA\Integriq\Exception\MtlsConfigurationException;
use OCA\Integriq\Exception\MtlsTransportException;
use OCA\Integriq\Service\Mtls\MtlsConfigResolver;
use OCP\Security\ICrypto;
use PHPUnit\Framework\TestCase;

/**
 * Pins what resolve decrypts and in which order, the optional passphrase and
 * CA bundle, and the missing-material refusal for every shape of the section.
 */
class MtlsConfigResolveGoldenTest extends TestCase {
	use MtlsCertificateFixture;

	/**
	 * Values decrypted, in order.
	 *
	 * @var array<int, string>
	 */
	private array $decrypted = [];

	/**
	 * A resolver whose crypto records what it decrypts and returns it unchanged.
	 *
	 * @return MtlsConfigResolver The resolver.
	 */
	private function resolver(): MtlsConfigResolver {
		$this->decrypted = [];
		$crypto          = $this->createMock(ICrypto::class);
		$crypto->method('decrypt')->willReturnCallback(
			function (string $value): string {
				$this->decrypted[] = $value;
				return $value;
			}
		);
		return new MtlsConfigResolver($crypto);

	}//end resolver()

	/**
	 * The decrypt order and the optional fields.
	 *
	 * @return void
	 */
	public function testDecryptOrderAndOptionalFields(): void {
		$key = $this->generateCertificateAndEncryptedKey(passphrase: 'pw');
		$ca  = $this->generateCertificateAndKey();

		$bundle = $this->resolver()->resolve(
			['mtls' => ['encryptedCaBundle' => $ca['certificatePem'], 'encryptedPassphrase' => 'pw', 'encryptedPrivateKey' => $key['privateKeyPem'], 'encryptedCertificate' => $key['certificatePem']]]
		);
		$this->assertSame([$key['certificatePem'], $key['privateKeyPem'], 'pw', $ca['certificatePem']], $this->decrypted);
		$this->assertSame([$key['certificatePem'], $key['privateKeyPem'], 'pw', $ca['certificatePem']], [$bundle->certificatePem, $bundle->privateKeyPem, $bundle->passphrase, $bundle->caBundlePem]);

		$plain  = $this->generateCertificateAndKey();
		$bundle = $this->resolver()->resolve(
			['mtls' => ['encryptedCertificate' => $plain['certificatePem'], 'encryptedPrivateKey' => $plain['privateKeyPem'], 'encryptedPassphrase' => '', 'encryptedCaBundle' => '']]
		);
		$this->assertSame([$plain['certificatePem'], $plain['privateKeyPem']], $this->decrypted);
		$this->assertNull($bundle->passphrase);
		$this->assertNull($bundle->caBundlePem);

	}//end testDecryptOrderAndOptionalFields()

	/**
	 * Every shape without both encrypted materials is refused before anything is decrypted.
	 *
	 * @return void
	 */
	public function testMissingMaterial(): void {
		foreach ([[], ['mtls' => 'x'], ['mtls' => []], ['mtls' => ['encryptedCertificate' => 'c']], ['mtls' => ['encryptedPrivateKey' => 'k']]] as $config) {
			$resolver = $this->resolver();
			try {
				$resolver->resolve($config);
				$this->fail('Not refused.');
			} catch (MtlsConfigurationException $exception) {
				$this->assertSame(
					'mTLS is configured (`authentication.mode=mtls`) but `authentication.mtls.encryptedCertificate`/`encryptedPrivateKey` are missing. No plaintext-certificate fallback is permitted.',
					$exception->getMessage()
				);
				$this->assertSame(MtlsTransportException::ERROR_MATERIAL_MISSING, $exception->getErrorCode());
			}

			$this->assertSame([], $this->decrypted);
		}

	}//end testMissingMaterial()
}//end class

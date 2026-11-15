<?php

/**
 * Unit tests for keepiq references in MtlsConfigResolver.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Service\Mtls
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 *
 * @spec openspec/changes/brp-connection-monitor-from-pipelinq/specs/mtls-client-certificate-transport/spec.md#requirement-mtls-material-may-be-a-keepiq-reference-req-005
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Service\Mtls;

use OCA\Integriq\Exception\MtlsConfigurationException;
use OCA\Integriq\Exception\MtlsTransportException;
use OCA\Integriq\Service\BrokeredCallConfigurationException;
use OCA\Integriq\Service\BrokeredCallServiceInterface;
use OCA\Integriq\Service\Mtls\MtlsCertificateBundle;
use OCA\Integriq\Service\Mtls\MtlsConfigResolver;
use OCP\Security\ICrypto;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * mTLS parts given as `{credentialRef}` resolve through the broker and fail closed.
 */
#[CoversClass(MtlsConfigResolver::class)]
#[UsesClass(MtlsCertificateBundle::class)]
#[UsesClass(MtlsConfigurationException::class)]
#[UsesClass(MtlsTransportException::class)]
class MtlsConfigResolverReferenceTest extends TestCase {
	use MtlsCertificateFixture;

	/**
	 * Crypto mock that echoes its input, so inline material is its own plaintext.
	 *
	 * @var ICrypto&MockObject
	 */
	private $crypto;

	/**
	 * Broker mock.
	 *
	 * @var BrokeredCallServiceInterface&MockObject
	 */
	private $broker;

	/**
	 * Build the mocks.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->crypto = $this->createMock(ICrypto::class);
		$this->crypto->method('decrypt')->willReturnArgument(0);
		$this->broker = $this->createMock(BrokeredCallServiceInterface::class);

	}//end setUp()

	/**
	 * A referenced certificate and key resolve through the broker into a validated bundle.
	 *
	 * @return void
	 */
	public function testReferencedCertificateAndKeyResolveAtCallTime(): void {
		$material = $this->generateCertificateAndKey();
		$secrets = ['cert-1' => $material['certificatePem'], 'key-1' => $material['privateKeyPem']];
		$this->broker->expects($this->exactly(2))
			->method('resolveCredentialRef')
			->willReturnCallback(static fn (array $ref): string => $secrets[$ref['credentialId']]);
		$this->crypto->expects($this->never())->method('decrypt');

		$bundle = (new MtlsConfigResolver($this->crypto, $this->broker))->resolve(
			[
				'mtls' => [
					'certificate' => ['credentialRef' => ['credentialId' => 'cert-1']],
					'privateKey' => ['credentialRef' => ['credentialId' => 'key-1']],
				],
			]
		);

		$this->assertSame($material['certificatePem'], $bundle->certificatePem);
		$this->assertSame($material['privateKeyPem'], $bundle->privateKeyPem);
		$this->assertNull($bundle->passphrase);
		$this->assertNull($bundle->caBundlePem);

	}//end testReferencedCertificateAndKeyResolveAtCallTime()

	/**
	 * A referenced passphrase and CA bundle sit beside inline certificate and key.
	 *
	 * @return void
	 */
	public function testReferencedPassphraseAndCaBundleMixWithInlineMaterial(): void {
		$material = $this->generateCertificateAndEncryptedKey(passphrase: 'correct-horse');
		$ca = $this->generateCertificateAndKey();
		$secrets = ['pass-1' => 'correct-horse', 'ca-1' => $ca['certificatePem']];
		$this->broker->method('resolveCredentialRef')
			->willReturnCallback(static fn (array $ref): string => $secrets[$ref['credentialId']]);

		$bundle = (new MtlsConfigResolver($this->crypto, $this->broker))->resolve(
			[
				'mtls' => [
					'encryptedCertificate' => $material['certificatePem'],
					'encryptedPrivateKey' => $material['privateKeyPem'],
					'passphrase' => ['credentialRef' => ['credentialId' => 'pass-1']],
					'caBundle' => ['credentialRef' => ['credentialId' => 'ca-1']],
				],
			]
		);

		$this->assertSame('correct-horse', $bundle->passphrase);
		$this->assertSame($ca['certificatePem'], $bundle->caBundlePem);

	}//end testReferencedPassphraseAndCaBundleMixWithInlineMaterial()

	/**
	 * A reference to a deleted secret fails closed with a stable code.
	 *
	 * @return void
	 */
	public function testReferenceThatDoesNotResolveFailsClosed(): void {
		$material = $this->generateCertificateAndKey();
		$this->broker->method('resolveCredentialRef')
			->willThrowException(new BrokeredCallConfigurationException('The referenced credential does not exist.'));

		try {
			(new MtlsConfigResolver($this->crypto, $this->broker))->resolve(
				[
					'mtls' => [
						'certificate' => ['credentialRef' => ['credentialId' => 'deleted']],
						'encryptedPrivateKey' => $material['privateKeyPem'],
					],
				]
			);
			$this->fail('Expected MtlsConfigurationException was not thrown.');
		} catch (MtlsConfigurationException $exception) {
			$this->assertSame(MtlsTransportException::ERROR_REFERENCE_UNRESOLVED, $exception->getErrorCode());
			$this->assertInstanceOf(BrokeredCallConfigurationException::class, $exception->getPrevious());
			$this->assertStringContainsString('certificate', $exception->getMessage());
		}

	}//end testReferenceThatDoesNotResolveFailsClosed()

	/**
	 * Without a broker a reference is refused, never skipped.
	 *
	 * @return void
	 */
	public function testReferenceWithoutBrokerFailsClosed(): void {
		$material = $this->generateCertificateAndKey();

		$this->expectException(MtlsConfigurationException::class);
		$this->expectExceptionMessage('no credential broker is available');

		(new MtlsConfigResolver($this->crypto))->resolve(
			[
				'mtls' => [
					'encryptedCertificate' => $material['certificatePem'],
					'privateKey' => ['credentialRef' => ['credentialId' => 'key-1']],
				],
			]
		);

	}//end testReferenceWithoutBrokerFailsClosed()

	/**
	 * A reference that resolves to nothing is refused.
	 *
	 * @return void
	 */
	public function testEmptyResolvedSecretFailsClosed(): void {
		$material = $this->generateCertificateAndKey();
		$this->broker->method('resolveCredentialRef')->willReturn('');

		try {
			(new MtlsConfigResolver($this->crypto, $this->broker))->resolve(
				[
					'mtls' => [
						'encryptedCertificate' => $material['certificatePem'],
						'privateKey' => ['credentialRef' => ['credentialId' => 'key-1']],
					],
				]
			);
			$this->fail('Expected MtlsConfigurationException was not thrown.');
		} catch (MtlsConfigurationException $exception) {
			$this->assertSame(MtlsTransportException::ERROR_REFERENCE_UNRESOLVED, $exception->getErrorCode());
		}

	}//end testEmptyResolvedSecretFailsClosed()

	/**
	 * A referenced certificate gets the same validation as inline material.
	 *
	 * @return void
	 */
	public function testReferencedExpiredCertificateIsRefused(): void {
		$material = $this->generateCertificateAndKey(validDays: -1);
		$this->broker->method('resolveCredentialRef')->willReturn($material['certificatePem']);

		try {
			(new MtlsConfigResolver($this->crypto, $this->broker))->resolve(
				[
					'mtls' => [
						'certificate' => ['credentialRef' => ['credentialId' => 'cert-1']],
						'encryptedPrivateKey' => $material['privateKeyPem'],
					],
				]
			);
			$this->fail('Expected MtlsConfigurationException was not thrown.');
		} catch (MtlsConfigurationException $exception) {
			$this->assertSame(MtlsTransportException::ERROR_CERTIFICATE_EXPIRED, $exception->getErrorCode());
		}

	}//end testReferencedExpiredCertificateIsRefused()

	/**
	 * An empty reference is not material: the section counts as missing.
	 *
	 * @return void
	 */
	public function testEmptyReferenceCountsAsMissingMaterial(): void {
		$this->broker->expects($this->never())->method('resolveCredentialRef');

		try {
			(new MtlsConfigResolver($this->crypto, $this->broker))->resolve(
				['mtls' => ['certificate' => ['credentialRef' => []], 'privateKey' => ['credentialRef' => ['credentialId' => 'k']]]]
			);
			$this->fail('Expected MtlsConfigurationException was not thrown.');
		} catch (MtlsConfigurationException $exception) {
			$this->assertSame(MtlsTransportException::ERROR_MATERIAL_MISSING, $exception->getErrorCode());
		}

	}//end testEmptyReferenceCountsAsMissingMaterial()
}//end class

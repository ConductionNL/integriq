<?php

/**
 * Unit tests for MtlsConfigResolver.
 *
 * @category Test
 * @package  OCA\OpenConnector\Tests\Unit\Service\Mtls
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

namespace OCA\OpenConnector\Tests\Unit\Service\Mtls;

use OCA\OpenConnector\Exception\MtlsConfigurationException;
use OCA\OpenConnector\Exception\MtlsTransportException;
use OCA\OpenConnector\Service\Mtls\MtlsCertificateBundle;
use OCA\OpenConnector\Service\Mtls\MtlsConfigResolver;
use OCP\Security\ICrypto;
use PHPUnit\Framework\TestCase;

/**
 * Tests for mTLS configuration decryption, PEM/expiry/passphrase validation.
 *
 * @spec openspec/specs/mtls-client-certificate-transport/spec.md#requirement-shared-mtls-transport-builds-guzzle-tls-options-from-encrypted-at-rest-certificate-material-req-001
 */
class MtlsConfigResolverTest extends TestCase
{
    use MtlsCertificateFixture;

    /**
     * @var ICrypto|\PHPUnit\Framework\MockObject\MockObject
     */
    private $crypto;

    /**
     * Set up: `ICrypto::decrypt()` is an identity pass-through in these
     * tests (the "encrypted" value IS the plaintext) unless a test
     * overrides it to simulate a decrypt failure.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->crypto = $this->createMock(ICrypto::class);
        $this->crypto->method('decrypt')->willReturnArgument(0);

    }//end setUp()

    /**
     * A valid configuration resolves to a usable bundle.
     *
     * @return void
     *
     * @spec openspec/specs/mtls-client-certificate-transport/spec.md#scenario-valid-mtls-configuration-resolves-to-a-usable-certificate-bundle
     */
    public function testResolveReturnsBundleForValidConfig(): void
    {
        $material = $this->generateCertificateAndKey();
        $resolver = new MtlsConfigResolver($this->crypto);

        $bundle = $resolver->resolve(
            [
                'mtls' => [
                    'encryptedCertificate' => $material['certificatePem'],
                    'encryptedPrivateKey'  => $material['privateKeyPem'],
                ],
            ]
        );

        $this->assertInstanceOf(MtlsCertificateBundle::class, $bundle);
        $this->assertSame($material['certificatePem'], $bundle->certificatePem);
        $this->assertSame($material['privateKeyPem'], $bundle->privateKeyPem);
        $this->assertNull($bundle->passphrase);
        $this->assertNull($bundle->caBundlePem);

    }//end testResolveReturnsBundleForValidConfig()

    /**
     * A valid CA bundle and passphrase-protected key both resolve correctly.
     *
     * @return void
     */
    public function testResolveReturnsBundleWithPassphraseAndCaBundle(): void
    {
        $material = $this->generateCertificateAndEncryptedKey(passphrase: 'correct-horse');
        $ca       = $this->generateCertificateAndKey();
        $resolver = new MtlsConfigResolver($this->crypto);

        $bundle = $resolver->resolve(
            [
                'mtls' => [
                    'encryptedCertificate' => $material['certificatePem'],
                    'encryptedPrivateKey'  => $material['privateKeyPem'],
                    'encryptedPassphrase'  => 'correct-horse',
                    'encryptedCaBundle'    => $ca['certificatePem'],
                ],
            ]
        );

        $this->assertSame('correct-horse', $bundle->passphrase);
        $this->assertSame($ca['certificatePem'], $bundle->caBundlePem);

    }//end testResolveReturnsBundleWithPassphraseAndCaBundle()

    /**
     * Missing certificate/key material fails closed before any network call.
     *
     * @return void
     *
     * @spec openspec/specs/mtls-client-certificate-transport/spec.md#scenario-missing-certificate-or-key-material-fails-closed-before-any-request
     */
    public function testResolveThrowsWhenMaterialMissing(): void
    {
        $resolver = new MtlsConfigResolver($this->crypto);

        try {
            $resolver->resolve(['mtls' => []]);
            $this->fail('Expected MtlsConfigurationException was not thrown.');
        } catch (MtlsConfigurationException $exception) {
            $this->assertSame(MtlsTransportException::ERROR_MATERIAL_MISSING, $exception->getErrorCode());
        }

    }//end testResolveThrowsWhenMaterialMissing()

    /**
     * A decrypt failure is wrapped with a stable errorCode.
     *
     * @return void
     */
    public function testResolveThrowsOnDecryptionFailure(): void
    {
        $crypto = $this->createMock(ICrypto::class);
        $crypto->method('decrypt')->willThrowException(new \RuntimeException('bad ciphertext'));

        $resolver = new MtlsConfigResolver($crypto);

        try {
            $resolver->resolve(
                [
                    'mtls' => [
                        'encryptedCertificate' => 'anything',
                        'encryptedPrivateKey'  => 'anything',
                    ],
                ]
            );
            $this->fail('Expected MtlsConfigurationException was not thrown.');
        } catch (MtlsConfigurationException $exception) {
            $this->assertSame(MtlsTransportException::ERROR_DECRYPTION_FAILED, $exception->getErrorCode());
            $this->assertStringNotContainsString('bad ciphertext', $exception->getMessage());
        }

    }//end testResolveThrowsOnDecryptionFailure()

    /**
     * A malformed certificate PEM is rejected.
     *
     * @return void
     */
    public function testResolveThrowsOnInvalidCertificateShape(): void
    {
        $material = $this->generateCertificateAndKey();
        $resolver = new MtlsConfigResolver($this->crypto);

        try {
            $resolver->resolve(
                [
                    'mtls' => [
                        'encryptedCertificate' => 'not a certificate',
                        'encryptedPrivateKey'  => $material['privateKeyPem'],
                    ],
                ]
            );
            $this->fail('Expected MtlsConfigurationException was not thrown.');
        } catch (MtlsConfigurationException $exception) {
            $this->assertSame(MtlsTransportException::ERROR_INVALID_CERTIFICATE, $exception->getErrorCode());
        }

    }//end testResolveThrowsOnInvalidCertificateShape()

    /**
     * A malformed private key PEM is rejected.
     *
     * @return void
     */
    public function testResolveThrowsOnInvalidPrivateKeyShape(): void
    {
        $material = $this->generateCertificateAndKey();
        $resolver = new MtlsConfigResolver($this->crypto);

        try {
            $resolver->resolve(
                [
                    'mtls' => [
                        'encryptedCertificate' => $material['certificatePem'],
                        'encryptedPrivateKey'  => 'not a private key',
                    ],
                ]
            );
            $this->fail('Expected MtlsConfigurationException was not thrown.');
        } catch (MtlsConfigurationException $exception) {
            $this->assertSame(MtlsTransportException::ERROR_INVALID_PRIVATE_KEY, $exception->getErrorCode());
        }

    }//end testResolveThrowsOnInvalidPrivateKeyShape()

    /**
     * An expired certificate is rejected pre-flight (no network call needed to detect it).
     *
     * @return void
     *
     * @spec openspec/specs/mtls-client-certificate-transport/spec.md#scenario-an-expired-certificate-is-rejected-pre-flight
     */
    public function testResolveThrowsOnExpiredCertificate(): void
    {
        $material = $this->expiredCertificateAndKey();
        $resolver = new MtlsConfigResolver($this->crypto);

        try {
            $resolver->resolve(
                [
                    'mtls' => [
                        'encryptedCertificate' => $material['certificatePem'],
                        'encryptedPrivateKey'  => $material['privateKeyPem'],
                    ],
                ]
            );
            $this->fail('Expected MtlsConfigurationException was not thrown.');
        } catch (MtlsConfigurationException $exception) {
            $this->assertSame(MtlsTransportException::ERROR_CERTIFICATE_EXPIRED, $exception->getErrorCode());
        }

    }//end testResolveThrowsOnExpiredCertificate()

    /**
     * A wrong passphrase is rejected pre-flight (no network call needed to detect it).
     *
     * @return void
     *
     * @spec openspec/specs/mtls-client-certificate-transport/spec.md#scenario-an-incorrect-passphrase-is-rejected-pre-flight
     */
    public function testResolveThrowsOnWrongPassphrase(): void
    {
        $material = $this->generateCertificateAndEncryptedKey(passphrase: 'correct-horse');
        $resolver = new MtlsConfigResolver($this->crypto);

        try {
            $resolver->resolve(
                [
                    'mtls' => [
                        'encryptedCertificate' => $material['certificatePem'],
                        'encryptedPrivateKey'  => $material['privateKeyPem'],
                        'encryptedPassphrase'  => 'wrong-passphrase',
                    ],
                ]
            );
            $this->fail('Expected MtlsConfigurationException was not thrown.');
        } catch (MtlsConfigurationException $exception) {
            $this->assertSame(MtlsTransportException::ERROR_PASSPHRASE_INVALID, $exception->getErrorCode());
            $this->assertStringNotContainsString('correct-horse', $exception->getMessage());
            $this->assertStringNotContainsString('wrong-passphrase', $exception->getMessage());
        }

    }//end testResolveThrowsOnWrongPassphrase()

    /**
     * A missing passphrase for a protected key is rejected pre-flight.
     *
     * @return void
     */
    public function testResolveThrowsWhenPassphraseRequiredButAbsent(): void
    {
        $material = $this->generateCertificateAndEncryptedKey(passphrase: 'correct-horse');
        $resolver = new MtlsConfigResolver($this->crypto);

        $this->expectException(MtlsConfigurationException::class);
        $resolver->resolve(
            [
                'mtls' => [
                    'encryptedCertificate' => $material['certificatePem'],
                    'encryptedPrivateKey'  => $material['privateKeyPem'],
                ],
            ]
        );

    }//end testResolveThrowsWhenPassphraseRequiredButAbsent()

    /**
     * `isMtlsConfigured()` reads `authentication.mode`.
     *
     * @return void
     */
    public function testIsMtlsConfiguredReadsMode(): void
    {
        $resolver = new MtlsConfigResolver($this->crypto);

        $this->assertFalse($resolver->isMtlsConfigured([]));
        $this->assertFalse($resolver->isMtlsConfigured(['mode' => 'token']));
        $this->assertTrue($resolver->isMtlsConfigured(['mode' => 'mtls']));

    }//end testIsMtlsConfiguredReadsMode()
}//end class

<?php

/**
 * Unit tests for DSOSignatureVerifierService.
 *
 * @category Test
 * @package  OCA\OpenConnector\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 *
 * @spec openspec/changes/dso-stam-pkioverheid-signature-verification/tasks.md#task-1
 */

declare(strict_types=1);

namespace OCA\OpenConnector\Tests\Unit\Service;

use OCA\OpenConnector\Service\DSOSignatureVerifierService;
use OCA\OpenConnector\Service\WebhookSignatureService;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Tests for the DSO STAM PKIoverheid / HMAC signature verifier.
 *
 * The RSA-mode tests build a real, locally-generated 2-level certificate
 * chain (self-signed root CA -> leaf signing cert) with PHP's own openssl_*
 * functions, so the assertions exercise real X.509 parsing, chain-of-trust
 * validation, and RSA-SHA256 signature verification — not mocked crypto.
 *
 * @spec openspec/changes/dso-stam-pkioverheid-signature-verification/tasks.md#task-1
 */
class DSOSignatureVerifierServiceTest extends TestCase
{

    /**
     * @var array<string, string>
     */
    private array $config = [];

    /**
     * @var IAppConfig|\PHPUnit\Framework\MockObject\MockObject
     */
    private $appConfig;

    /**
     * @var DSOSignatureVerifierService
     */
    private DSOSignatureVerifierService $service;

    /**
     * PEM-encoded self-signed root CA certificate (test fixture).
     *
     * @var string
     */
    private static string $rootCaPem;

    /**
     * PEM-encoded leaf signing certificate, signed by the test root CA.
     *
     * @var string
     */
    private static string $signingCertPem;

    /**
     * PEM-encoded private key for the leaf signing certificate.
     *
     * @var string
     */
    private static string $signingKeyPem;

    /**
     * PEM-encoded self-signed certificate NOT chained to the test root CA
     * (used to prove untrusted chains are rejected).
     *
     * @var string
     */
    private static string $untrustedCertPem;

    /**
     * @var string
     */
    private static string $untrustedKeyPem;


    /**
     * Build a local test certificate chain once for the whole test class.
     *
     * @return void
     */
    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();

        // Root CA key + self-signed certificate.
        $rootKey  = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        $rootCsr  = openssl_csr_new(['commonName' => 'Test PKIoverheid Private Root CA'], $rootKey);
        $rootCert = openssl_csr_sign($rootCsr, null, $rootKey, 3650);
        $rootCaPem = '';
        openssl_x509_export($rootCert, $rootCaPem);
        self::$rootCaPem = $rootCaPem;

        // Leaf signing certificate, signed by the root CA.
        $leafKey  = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        $leafCsr  = openssl_csr_new(['commonName' => 'dso-stam.test.conduction.nl'], $leafKey);
        $leafCert = openssl_csr_sign($leafCsr, $rootCert, $rootKey, 365);
        $signingCertPem = '';
        $signingKeyPem  = '';
        openssl_x509_export($leafCert, $signingCertPem);
        openssl_pkey_export($leafKey, $signingKeyPem);
        self::$signingCertPem = $signingCertPem;
        self::$signingKeyPem  = $signingKeyPem;

        // An unrelated self-signed certificate that does NOT chain to the root CA.
        $untrustedKey  = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        $untrustedCsr  = openssl_csr_new(['commonName' => 'untrusted.test'], $untrustedKey);
        $untrustedCert = openssl_csr_sign($untrustedCsr, null, $untrustedKey, 365);
        $untrustedCertPem = '';
        $untrustedKeyPem  = '';
        openssl_x509_export($untrustedCert, $untrustedCertPem);
        openssl_pkey_export($untrustedKey, $untrustedKeyPem);
        self::$untrustedCertPem = $untrustedCertPem;
        self::$untrustedKeyPem  = $untrustedKeyPem;

    }//end setUpBeforeClass()

    /**
     * Set up test fixtures.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->config    = [];
        $this->appConfig = $this->createMock(IAppConfig::class);
        $this->appConfig->method('getValueString')
            ->willReturnCallback(function (string $app, string $key, string $default = '') {
                return ($this->config[$key] ?? $default);
            });

        $logger        = $this->createMock(LoggerInterface::class);
        $webhookSigner = new WebhookSignatureService($logger);

        $this->service = new DSOSignatureVerifierService(
            appConfig: $this->appConfig,
            webhookSignatureService: $webhookSigner,
            logger: $logger,
        );

    }//end setUp()

    /**
     * Missing header always fails closed, regardless of mode.
     *
     * @return void
     */
    public function testMissingSignatureHeaderRejected(): void
    {
        $this->config[DSOSignatureVerifierService::CONFIG_MODE] = DSOSignatureVerifierService::MODE_HMAC;
        $this->assertFalse($this->service->verify(null, '{"foo":"bar"}'));
        $this->assertFalse($this->service->verify('', '{"foo":"bar"}'));

    }//end testMissingSignatureHeaderRejected()

    /**
     * A correctly computed HMAC signature (pre-production mode) verifies.
     *
     * @return void
     */
    public function testValidHmacSignatureAccepted(): void
    {
        $secret = 'test-shared-secret';
        $body   = '{"verzoekId":"abc-123"}';

        $this->config[DSOSignatureVerifierService::CONFIG_MODE]        = DSOSignatureVerifierService::MODE_HMAC;
        $this->config[DSOSignatureVerifierService::CONFIG_HMAC_SECRET] = $secret;

        $header = 'sha256='.hash_hmac('sha256', $body, $secret);

        $this->assertTrue($this->service->verify($header, $body));

    }//end testValidHmacSignatureAccepted()

    /**
     * A forged HMAC signature is rejected.
     *
     * @return void
     */
    public function testForgedHmacSignatureRejected(): void
    {
        $this->config[DSOSignatureVerifierService::CONFIG_MODE]        = DSOSignatureVerifierService::MODE_HMAC;
        $this->config[DSOSignatureVerifierService::CONFIG_HMAC_SECRET] = 'test-shared-secret';

        $header = 'sha256='.hash_hmac('sha256', 'tampered', 'wrong-secret');

        $this->assertFalse($this->service->verify($header, '{"verzoekId":"abc-123"}'));

    }//end testForgedHmacSignatureRejected()

    /**
     * HMAC mode with no secret configured fails closed.
     *
     * @return void
     */
    public function testHmacModeWithoutConfiguredSecretRejected(): void
    {
        $this->config[DSOSignatureVerifierService::CONFIG_MODE] = DSOSignatureVerifierService::MODE_HMAC;

        $this->assertFalse($this->service->verify('sha256=deadbeef', '{}'));

    }//end testHmacModeWithoutConfiguredSecretRejected()

    /**
     * A valid RSA signature from a certificate chained to the trusted root
     * CA verifies successfully.
     *
     * @return void
     */
    public function testValidRsaChainSignatureAccepted(): void
    {
        $body = '{"verzoekId":"real-signature"}';

        $privateKey = openssl_pkey_get_private(self::$signingKeyPem);
        openssl_sign($body, $signature, $privateKey, OPENSSL_ALGO_SHA256);

        $this->config[DSOSignatureVerifierService::CONFIG_MODE]                 = DSOSignatureVerifierService::MODE_RSA;
        $this->config[DSOSignatureVerifierService::CONFIG_SIGNING_CERTIFICATE]  = self::$signingCertPem;
        $this->config[DSOSignatureVerifierService::CONFIG_ROOT_CA]              = self::$rootCaPem;

        $this->assertTrue($this->service->verify(base64_encode($signature), $body));

    }//end testValidRsaChainSignatureAccepted()

    /**
     * A body-hash mismatch (tampered payload) is rejected even though the
     * certificate chain itself is trusted.
     *
     * @return void
     */
    public function testTamperedBodyRsaSignatureRejected(): void
    {
        $privateKey = openssl_pkey_get_private(self::$signingKeyPem);
        openssl_sign('{"verzoekId":"original"}', $signature, $privateKey, OPENSSL_ALGO_SHA256);

        $this->config[DSOSignatureVerifierService::CONFIG_MODE]                = DSOSignatureVerifierService::MODE_RSA;
        $this->config[DSOSignatureVerifierService::CONFIG_SIGNING_CERTIFICATE] = self::$signingCertPem;
        $this->config[DSOSignatureVerifierService::CONFIG_ROOT_CA]             = self::$rootCaPem;

        $this->assertFalse($this->service->verify(base64_encode($signature), '{"verzoekId":"tampered"}'));

    }//end testTamperedBodyRsaSignatureRejected()

    /**
     * A certificate that does not chain to the configured trusted root is
     * rejected even with a mathematically valid RSA signature.
     *
     * @return void
     */
    public function testUntrustedCertificateChainRejected(): void
    {
        $body = '{"verzoekId":"untrusted"}';

        $privateKey = openssl_pkey_get_private(self::$untrustedKeyPem);
        openssl_sign($body, $signature, $privateKey, OPENSSL_ALGO_SHA256);

        $this->config[DSOSignatureVerifierService::CONFIG_MODE]                = DSOSignatureVerifierService::MODE_RSA;
        $this->config[DSOSignatureVerifierService::CONFIG_SIGNING_CERTIFICATE] = self::$untrustedCertPem;
        $this->config[DSOSignatureVerifierService::CONFIG_ROOT_CA]             = self::$rootCaPem;

        $this->assertFalse($this->service->verify(base64_encode($signature), $body));

    }//end testUntrustedCertificateChainRejected()

    /**
     * RSA mode with no certificate configured fails closed.
     *
     * @return void
     */
    public function testRsaModeWithoutConfiguredCertificateRejected(): void
    {
        $this->config[DSOSignatureVerifierService::CONFIG_MODE] = DSOSignatureVerifierService::MODE_RSA;

        $this->assertFalse($this->service->verify(base64_encode('anything'), '{}'));

    }//end testRsaModeWithoutConfiguredCertificateRejected()

    /**
     * A malformed (non-base64) signature header in RSA mode is rejected.
     *
     * @return void
     */
    public function testMalformedRsaSignatureRejected(): void
    {
        $this->config[DSOSignatureVerifierService::CONFIG_MODE]                = DSOSignatureVerifierService::MODE_RSA;
        $this->config[DSOSignatureVerifierService::CONFIG_SIGNING_CERTIFICATE] = self::$signingCertPem;
        $this->config[DSOSignatureVerifierService::CONFIG_ROOT_CA]             = self::$rootCaPem;

        $this->assertFalse($this->service->verify('%%%not-base64%%%', '{}'));

    }//end testMalformedRsaSignatureRejected()

    /**
     * `isCertificateCurrentlyValid` accepts a freshly-issued certificate.
     *
     * @return void
     */
    public function testIsCertificateCurrentlyValidAcceptsFreshCertificate(): void
    {
        $this->assertTrue($this->service->isCertificateCurrentlyValid(self::$signingCertPem));

    }//end testIsCertificateCurrentlyValidAcceptsFreshCertificate()

    /**
     * `isCertificateCurrentlyValid` rejects unparseable input.
     *
     * @return void
     */
    public function testIsCertificateCurrentlyValidRejectsGarbage(): void
    {
        $this->assertFalse($this->service->isCertificateCurrentlyValid('not a certificate'));

    }//end testIsCertificateCurrentlyValidRejectsGarbage()

    /**
     * `validateChainConfig` reports errors for a certificate that does not
     * chain to the configured root, so the admin settings save-path can
     * surface a descriptive error instead of silently accepting a broken
     * configuration.
     *
     * @return void
     */
    public function testValidateChainConfigReportsUntrustedChain(): void
    {
        $errors = $this->service->validateChainConfig(self::$untrustedCertPem, self::$rootCaPem);

        $this->assertNotEmpty($errors);

    }//end testValidateChainConfigReportsUntrustedChain()

    /**
     * `validateChainConfig` reports no errors for a valid, trusted chain.
     *
     * @return void
     */
    public function testValidateChainConfigAcceptsValidChain(): void
    {
        $errors = $this->service->validateChainConfig(self::$signingCertPem, self::$rootCaPem);

        $this->assertEmpty($errors);

    }//end testValidateChainConfigAcceptsValidChain()
}//end class

<?php
/**
 * Unit tests for CallService.
 *
 * @category Test
 * @package  OCA\OpenConnector\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenConnector\Tests\Unit\Service;

use OCA\OpenConnector\Service\AuthenticationService;
use OCA\OpenConnector\Service\CallService;
use OCA\OpenConnector\Tests\Helpers\ObjectServiceMockBuilder;
use OCA\OpenRegister\Service\ObjectService;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Twig\Loader\ArrayLoader;

/**
 * Tests for the outbound call service (OR cutover — no deleted Db types).
 */
class CallServiceTest extends TestCase
{

    /**
     * @var CallService
     */
    private CallService $service;

    /**
     * @var ObjectService|\PHPUnit\Framework\MockObject\MockObject
     */
    private $objectService;


    /**
     * Set up test fixtures.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->objectService = ObjectServiceMockBuilder::make($this);

        $authService = $this->createMock(AuthenticationService::class);
        $appConfig   = $this->createMock(IAppConfig::class);
        $logger      = $this->createMock(LoggerInterface::class);

        // IAppConfig::hasKey defaults to false so no retention config is applied.
        $appConfig->method('hasKey')->willReturn(false);

        // CallService constructor signature (5 args): ORObjectService,
        // ArrayLoader, AuthenticationService, IAppConfig, LoggerInterface.
        // The previous version passed only 4 — the LoggerInterface arg was
        // added by #1011 (security-policy warnings) but the test wasn't
        // updated, causing 5 ArgumentCountErrors that only surfaced once
        // #1023 unblocked setUp() and the post-#1024 Service-suite peel
        // (#1026) brought the remaining 3 cited #1025 suites to green.
        $this->service = new CallService(
            $this->objectService,
            new ArrayLoader([]),
            $authService,
            $appConfig,
            $logger,
        );
    }//end setUp()


    /**
     * Test that the constructor instantiates CallService without errors.
     *
     * @return void
     */
    public function testConstructorWiresDependencies(): void
    {
        $this->assertInstanceOf(CallService::class, $this->service);
    }//end testConstructorWiresDependencies()


    /**
     * Test that applyConfigDot expands dot-notation keys into nested arrays.
     *
     * @return void
     */
    public function testApplyConfigDotExpandsDotKeys(): void
    {
        // Arrange
        $config = [
            'foo.bar' => 'baz',
            'plain'   => 'value',
        ];

        // Act
        $result = $this->service->applyConfigDot($config);

        // Assert
        $this->assertArrayHasKey('foo', $result);
        $this->assertSame('baz', $result['foo']['bar']);
        $this->assertSame('value', $result['plain']);
        $this->assertArrayNotHasKey('foo.bar', $result);
    }//end testApplyConfigDotExpandsDotKeys()


    /**
     * Test that applyConfigDot returns flat config unchanged when no dot keys present.
     *
     * @return void
     */
    public function testApplyConfigDotLeavesNonDotKeysUntouched(): void
    {
        // Arrange
        $config = ['key' => 'value', 'another' => 123];

        // Act
        $result = $this->service->applyConfigDot($config);

        // Assert
        $this->assertSame($config, $result);
    }//end testApplyConfigDotLeavesNonDotKeysUntouched()


    /**
     * Test that removeFiles does not throw when config has no certificate keys.
     *
     * @return void
     */
    public function testRemoveFilesHandlesEmptyConfig(): void
    {
        // Arrange / Act / Assert — should not throw
        $this->service->removeFiles([]);
        $this->assertTrue(true);
    }//end testRemoveFilesHandlesEmptyConfig()


    /**
     * Test that getCertificate passes through config with no cert keys unchanged.
     *
     * @return void
     */
    public function testGetCertificatePassesThroughWhenNoCertKey(): void
    {
        // Arrange
        $config = ['url' => 'https://example.com'];

        // Act
        $this->service->getCertificate($config);

        // Assert
        $this->assertSame(['url' => 'https://example.com'], $config);
    }//end testGetCertificatePassesThroughWhenNoCertKey()


    /**
     * REQ-STUF-011 scenario "Client certificate used for mTLS request": a
     * string PEM certificate configured on a StUF (or any mTLS) Source is
     * written to a temp file by `getCertificate()`, the config is rewritten
     * to point at that file (so Guzzle's `cert` option receives a path, not
     * PEM content), and the file is real / readable on disk.
     *
     * @return void
     *
     * @spec openspec/changes/connector-adapter-e2e-traceability/tasks.md#task-4
     */
    public function testGetCertificateWritesCertToTempFile(): void
    {
        // Arrange
        $pem    = "-----BEGIN CERTIFICATE-----\nMIIBAjCB...fixture...\n-----END CERTIFICATE-----";
        $config = ['cert' => $pem];

        // Act
        $this->service->getCertificate($config);

        // Assert: config now holds a filesystem path, not the raw PEM.
        $this->assertIsString($config['cert']);
        $this->assertNotSame($pem, $config['cert']);
        $this->assertFileExists($config['cert']);
        $this->assertSame($pem, file_get_contents($config['cert']));

        // Cleanup via the service's own removal path (also exercises it).
        $this->service->removeFiles($config);
        $this->assertFileDoesNotExist($config['cert']);
    }//end testGetCertificateWritesCertToTempFile()


    /**
     * REQ-STUF-011 scenario "Client certificate used for mTLS request",
     * SSL-key variant: `ssl_key` is written to disk exactly like `cert`.
     *
     * @return void
     *
     * @spec openspec/changes/connector-adapter-e2e-traceability/tasks.md#task-4
     */
    public function testGetCertificateWritesSslKeyToTempFile(): void
    {
        // Arrange
        $key    = "-----BEGIN PRIVATE KEY-----\nMIIEvQ...fixture...\n-----END PRIVATE KEY-----";
        $config = ['ssl_key' => $key];

        // Act
        $this->service->getCertificate($config);

        // Assert
        $this->assertFileExists($config['ssl_key']);
        $this->assertSame($key, file_get_contents($config['ssl_key']));

        $this->service->removeFiles($config);
        $this->assertFileDoesNotExist($config['ssl_key']);
    }//end testGetCertificateWritesSslKeyToTempFile()


    /**
     * REQ-STUF-011 scenario "Escaped newlines in PEM converted correctly":
     * a PEM stored with literal `\n` escape sequences (as it would arrive
     * from a JSON-encoded Source configuration) is converted to real
     * newline bytes on write, asserted byte-for-byte.
     *
     * @return void
     *
     * @spec openspec/changes/connector-adapter-e2e-traceability/tasks.md#task-4
     */
    public function testGetCertificateConvertsEscapedNewlines(): void
    {
        // Arrange: literal backslash-n sequences, as stored in a JSON field.
        $escaped  = '-----BEGIN CERTIFICATE-----\nMIIBAjCB...fixture...\n-----END CERTIFICATE-----';
        $expected = "-----BEGIN CERTIFICATE-----\nMIIBAjCB...fixture...\n-----END CERTIFICATE-----";
        $config   = ['cert' => $escaped];

        // Act
        $this->service->getCertificate($config);

        // Assert byte-for-byte: no stray literal backslash-n left in the file.
        $written = file_get_contents($config['cert']);
        $this->assertSame($expected, $written);
        $this->assertStringNotContainsString('\\n', $written);

        $this->service->removeFiles($config);
    }//end testGetCertificateConvertsEscapedNewlines()


    /**
     * REQ-STUF-011: `cert` supplied as a `[pem, password]` array (Guzzle's
     * client-cert-with-passphrase shape) writes only the PEM element
     * (index 0) to disk and preserves the password element untouched.
     *
     * @return void
     *
     * @spec openspec/changes/connector-adapter-e2e-traceability/tasks.md#task-4
     */
    public function testGetCertificateWritesArrayFormCertPreservingPassword(): void
    {
        // Arrange
        $pem    = "-----BEGIN CERTIFICATE-----\nfixture\n-----END CERTIFICATE-----";
        $config = ['cert' => [$pem, 'super-secret-passphrase']];

        // Act
        $this->service->getCertificate($config);

        // Assert
        $this->assertFileExists($config['cert'][0]);
        $this->assertSame($pem, file_get_contents($config['cert'][0]));
        $this->assertSame('super-secret-passphrase', $config['cert'][1]);

        $this->service->removeFiles($config);
        $this->assertFileDoesNotExist($config['cert'][0]);
    }//end testGetCertificateWritesArrayFormCertPreservingPassword()


    /**
     * REQ-STUF-011: `removeFiles()` cleans up cert + ssl_key + verify
     * together in a single call, mirroring the real teardown path in
     * `CallService::call()` after both the success and exception branches.
     *
     * @return void
     *
     * @spec openspec/changes/connector-adapter-e2e-traceability/tasks.md#task-4
     */
    public function testRemoveFilesCleansUpCertSslKeyAndVerifyTogether(): void
    {
        // Arrange
        $config = [
            'cert'    => "-----BEGIN CERTIFICATE-----\nfixture\n-----END CERTIFICATE-----",
            'ssl_key' => "-----BEGIN PRIVATE KEY-----\nfixture\n-----END PRIVATE KEY-----",
            'verify'  => "-----BEGIN CERTIFICATE-----\nca-fixture\n-----END CERTIFICATE-----",
        ];
        $this->service->getCertificate($config);
        $certPath   = $config['cert'];
        $keyPath    = $config['ssl_key'];
        $verifyPath = $config['verify'];

        $this->assertFileExists($certPath);
        $this->assertFileExists($keyPath);
        $this->assertFileExists($verifyPath);

        // Act
        $this->service->removeFiles($config);

        // Assert: every temp file is gone (exception-path cleanup is
        // identical — `removeFiles()` is called the same way from both the
        // success and `finally`/catch branches in `call()`).
        $this->assertFileDoesNotExist($certPath);
        $this->assertFileDoesNotExist($keyPath);
        $this->assertFileDoesNotExist($verifyPath);
    }//end testRemoveFilesCleansUpCertSslKeyAndVerifyTogether()


}//end class

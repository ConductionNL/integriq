<?php

/**
 * Unit tests for MtlsTransportService.
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

use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Promise\FulfilledPromise;
use GuzzleHttp\Promise\RejectedPromise;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use OCA\OpenConnector\Exception\MtlsHandshakeException;
use OCA\OpenConnector\Exception\MtlsTransportException;
use OCA\OpenConnector\Service\Mtls\MtlsCertificateBundle;
use OCA\OpenConnector\Service\Mtls\MtlsTransportOptionsBuilder;
use OCA\OpenConnector\Service\Mtls\MtlsTransportService;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Tests for the materialize → dispatch → cleanup-in-finally orchestration.
 *
 * @spec openspec/specs/mtls-client-certificate-transport/spec.md#requirement-mtls-never-fails-open-to-plaintext-or-token-auth-req-003
 */
class MtlsTransportServiceTest extends TestCase
{

    /**
     * @var LoggerInterface|\PHPUnit\Framework\MockObject\MockObject
     */
    private $logger;

    /**
     * @var MtlsTransportOptionsBuilder
     */
    private MtlsTransportOptionsBuilder $optionsBuilder;

    /**
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();
        $this->logger         = $this->createMock(LoggerInterface::class);
        $this->optionsBuilder = new MtlsTransportOptionsBuilder();

    }//end setUp()

    /**
     * request() merges the TLS options into the dispatched request and
     * cleans up the materialized temp files afterwards.
     *
     * @return void
     *
     * @spec openspec/specs/mtls-client-certificate-transport/spec.md#scenario-temp-files-are-removed-after-a-successful-dispatch
     */
    public function testRequestMergesTlsOptionsAndCleansUpOnSuccess(): void
    {
        $capturedOptions   = null;
        $capturedFileExist = null;
        $capturedHeader    = null;
        $handler           = function (Request $request, array $options) use (&$capturedOptions, &$capturedFileExist, &$capturedHeader) {
            $capturedOptions   = $options;
            $capturedFileExist = file_exists($options['cert']);
            $capturedHeader    = $request->getHeaderLine('X-Foo');
            return new FulfilledPromise(new Response(200));
        };

        $client  = new Client(['handler' => $handler]);
        $service = new MtlsTransportService($this->optionsBuilder, $this->logger);
        $bundle  = new MtlsCertificateBundle(certificatePem: 'CERT-CONTENTS', privateKeyPem: 'KEY-CONTENTS');

        $response = $service->request($client, 'POST', 'https://example.nl/berichten', ['headers' => ['X-Foo' => 'bar']], $bundle);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertTrue($capturedFileExist, 'the cert file must exist DURING dispatch');
        $this->assertArrayHasKey('cert', $capturedOptions);
        $this->assertArrayHasKey('ssl_key', $capturedOptions);
        $this->assertSame('bar', $capturedHeader, 'caller-supplied headers must survive the TLS-options merge');

        // Cleanup must have run — the file no longer exists after request() returns.
        $this->assertFileDoesNotExist($capturedOptions['cert']);
        $this->assertFileDoesNotExist($capturedOptions['ssl_key']);

    }//end testRequestMergesTlsOptionsAndCleansUpOnSuccess()

    /**
     * request() wraps a GuzzleException as MtlsHandshakeException AND still
     * cleans up the materialized temp files (finally block proof).
     *
     * @return void
     *
     * @spec openspec/specs/mtls-client-certificate-transport/spec.md#scenario-temp-files-are-removed-even-when-the-dispatch-throws
     * @spec openspec/specs/mtls-client-certificate-transport/spec.md#scenario-a-handshake-failure-raises-a-typed-exception-not-a-silent-fallback
     */
    public function testRequestWrapsGuzzleExceptionAndCleansUpOnFailure(): void
    {
        $capturedPaths = null;
        $handler       = function (Request $request, array $options) use (&$capturedPaths) {
            $capturedPaths = ['cert' => $options['cert'], 'ssl_key' => $options['ssl_key']];
            return new RejectedPromise(
                new ConnectException('SSL certificate problem: unable to get local issuer certificate', $request)
            );
        };

        $client  = new Client(['handler' => $handler]);
        $service = new MtlsTransportService($this->optionsBuilder, $this->logger);
        $bundle  = new MtlsCertificateBundle(certificatePem: 'CERT-CONTENTS', privateKeyPem: 'KEY-CONTENTS');

        try {
            $service->request($client, 'POST', 'https://example.nl/berichten', [], $bundle);
            $this->fail('Expected MtlsHandshakeException was not thrown.');
        } catch (MtlsHandshakeException $exception) {
            $this->assertSame(MtlsTransportException::ERROR_HANDSHAKE_FAILED, $exception->getErrorCode());
        }

        $this->assertNotNull($capturedPaths);
        $this->assertFileDoesNotExist($capturedPaths['cert']);
        $this->assertFileDoesNotExist($capturedPaths['ssl_key']);

    }//end testRequestWrapsGuzzleExceptionAndCleansUpOnFailure()

    /**
     * Neither the certificate PEM, private key PEM, nor a configured
     * passphrase ever appear in the thrown exception's message.
     *
     * @return void
     */
    public function testFailureMessagesCarryNoSecretMaterial(): void
    {
        $certificatePem = "-----BEGIN CERTIFICATE-----\nSUPER-SECRET-CERT-BYTES\n-----END CERTIFICATE-----";
        $privateKeyPem  = "-----BEGIN PRIVATE KEY-----\nSUPER-SECRET-KEY-BYTES\n-----END PRIVATE KEY-----";
        $passphrase     = 'th3-p4ssphr4se';

        $handler = function (Request $request, array $options) {
            return new RejectedPromise(new ConnectException('handshake failed', $request));
        };

        $client  = new Client(['handler' => $handler]);
        $service = new MtlsTransportService($this->optionsBuilder, $this->logger);
        $bundle  = new MtlsCertificateBundle(
            certificatePem: $certificatePem,
            privateKeyPem: $privateKeyPem,
            passphrase: $passphrase
        );

        try {
            $service->request($client, 'POST', 'https://example.nl/berichten', [], $bundle);
            $this->fail('Expected MtlsHandshakeException was not thrown.');
        } catch (MtlsHandshakeException $exception) {
            $message = $exception->getMessage();
            $this->assertStringNotContainsString('SUPER-SECRET-CERT-BYTES', $message);
            $this->assertStringNotContainsString('SUPER-SECRET-KEY-BYTES', $message);
            $this->assertStringNotContainsString($passphrase, $message);
        }

    }//end testFailureMessagesCarryNoSecretMaterial()
}//end class

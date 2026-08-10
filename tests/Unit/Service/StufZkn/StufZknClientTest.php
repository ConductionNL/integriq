<?php

/**
 * Unit tests for StufZknClient.
 *
 * @category Test
 * @package  OCA\OpenConnector\Tests\Unit\Service\StufZkn
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 *
 * @spec openspec/changes/stuf-zkn-bridge/tasks.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenConnector\Tests\Unit\Service\StufZkn;

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use OCA\OpenConnector\Exception\StufZknProviderException;
use OCA\OpenConnector\Service\Mtls\MtlsCertificateBundle;
use OCA\OpenConnector\Service\Mtls\MtlsConfigResolver;
use OCA\OpenConnector\Service\Mtls\MtlsTransportOptionsBuilder;
use OCA\OpenConnector\Service\Mtls\MtlsTransportService;
use OCA\OpenConnector\Service\StufZkn\StufZknClient;
use OCP\IL10N;
use OCP\Security\ICrypto;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Tests for the generic REST/mTLS StUF-ZKN outbound client (SOAP-shaped headers, mTLS
 * routing proof, ref extraction, transport failure handling).
 *
 * @spec openspec/changes/stuf-zkn-bridge/specs/stuf-zkn-bridge/spec.md#requirement-stufzkn-outbound-provider-abstraction-with-log-and-rest-bindings-req-001
 */
class StufZknClientTest extends TestCase
{

    /**
     * @var array<int,array{request:\GuzzleHttp\Psr7\Request}>
     */
    private array $history = [];

    /**
     * @var ICrypto|\PHPUnit\Framework\MockObject\MockObject
     */
    private $crypto;

    /**
     * @var IL10N|\PHPUnit\Framework\MockObject\MockObject
     */
    private $l;

    /**
     * @var LoggerInterface|\PHPUnit\Framework\MockObject\MockObject
     */
    private $logger;

    /**
     * The valid configuration used by every "happy path" test.
     *
     * @var array
     */
    private array $configuration = [
        'baseUrl'        => 'https://stuf.gemeente-x.example.nl/inbound',
        'authentication' => ['encryptedToken' => 'ciphertext-blob'],
    ];

    /**
     * Set up test fixtures.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->crypto = $this->createMock(ICrypto::class);
        $this->crypto->method('decrypt')->willReturn('raw-token-value');

        $this->l = $this->createMock(IL10N::class);
        $this->l->method('t')->willReturnArgument(0);

        $this->logger = $this->createMock(LoggerInterface::class);

    }//end setUp()

    /**
     * Build a client whose Guzzle client is backed by a MockHandler queue.
     *
     * @param array<int,Response> $responses The queued mock responses.
     *
     * @return StufZknClient The client under test.
     */
    private function buildClient(array $responses): StufZknClient
    {
        $mock  = new MockHandler($responses);
        $stack = HandlerStack::create($mock);

        $this->history = [];
        $stack->push(Middleware::history($this->history));

        return new StufZknClient(
            new Client(['handler' => $stack]),
            $this->crypto,
            $this->l,
            $this->logger,
            new MtlsConfigResolver($this->crypto),
            new MtlsTransportService(new MtlsTransportOptionsBuilder(), $this->logger)
        );

    }//end buildClient()

    /**
     * getProviderId() returns "rest".
     *
     * @return void
     */
    public function testGetProviderId(): void
    {
        $this->assertSame('rest', $this->buildClient([])->getProviderId());

    }//end testGetProviderId()

    /**
     * send() posts the SOAP-shaped headers (text/xml, empty SOAPAction) and the envelope verbatim.
     *
     * @return void
     *
     * @spec openspec/changes/stuf-zkn-bridge/specs/stuf-zkn-bridge/spec.md#scenario-the-rest-provider-sends-the-expected-content-type-and-mtls-routing
     */
    public function testSendPostsSoapShapedHeadersAndEnvelopeVerbatim(): void
    {
        $client = $this->buildClient([new Response(200, [], '')]);

        $client->send($this->configuration, 'REF-1', '<zkn:zakLk01/>');

        $request = $this->history[0]['request'];
        $this->assertSame('POST', $request->getMethod());
        $this->assertSame('text/xml; charset=utf-8', $request->getHeaderLine('Content-Type'));
        $this->assertSame('""', $request->getHeaderLine('SOAPAction'));
        $this->assertSame('<zkn:zakLk01/>', (string) $request->getBody());
        $this->assertSame('Bearer raw-token-value', $request->getHeaderLine('Authorization'));

    }//end testSendPostsSoapShapedHeadersAndEnvelopeVerbatim()

    /**
     * send() extracts the reply's own referentienummer when the response body parses as XML.
     *
     * @return void
     */
    public function testSendExtractsReferentienummerFromXmlReply(): void
    {
        $reply  = '<soap:Envelope xmlns:soap="http://schemas.xmlsoap.org/soap/envelope/">'
            .'<soap:Body><StUF:Bv03Bericht xmlns:StUF="http://www.egem.nl/StUF/StUF0301">'
            .'<StUF:stuurgegevens><StUF:referentienummer>BV03-xyz</StUF:referentienummer></StUF:stuurgegevens>'
            .'</StUF:Bv03Bericht></soap:Body></soap:Envelope>';
        $client = $this->buildClient([new Response(200, [], $reply)]);

        $ref = $client->send($this->configuration, 'REF-1', '<zkn:zakLk01/>');

        $this->assertSame('BV03-xyz', $ref);

    }//end testSendExtractsReferentienummerFromXmlReply()

    /**
     * send() derives a local reference when the reply body is empty or not usable XML.
     *
     * @return void
     */
    public function testSendDerivesLocalReferenceOnEmptyReply(): void
    {
        $client = $this->buildClient([new Response(200, [], '')]);

        $ref = $client->send($this->configuration, 'REF-1', '<zkn:zakLk01/>');

        $this->assertStringStartsWith('REF-1-ack-', $ref);

    }//end testSendDerivesLocalReferenceOnEmptyReply()

    /**
     * A non-2xx response raises StufZknProviderException.
     *
     * @return void
     */
    public function testSendThrowsOnNon2xxStatus(): void
    {
        $client = $this->buildClient([new Response(500, [], 'internal error')]);

        $this->expectException(StufZknProviderException::class);
        $client->send($this->configuration, 'REF-1', '<zkn:zakLk01/>');

    }//end testSendThrowsOnNon2xxStatus()

    /**
     * A missing baseUrl raises StufZknProviderException before any request is dispatched.
     *
     * @return void
     */
    public function testSendThrowsWhenBaseUrlMissing(): void
    {
        $client = $this->buildClient([]);

        try {
            $client->send(['authentication' => ['encryptedToken' => 'x']], 'REF-1', '<zkn:zakLk01/>');
            $this->fail('Expected StufZknProviderException was not thrown.');
        } catch (StufZknProviderException) {
            $this->assertCount(0, $this->history);
        }

    }//end testSendThrowsWhenBaseUrlMissing()

    /**
     * A missing encryptedToken raises StufZknProviderException before any request is dispatched.
     *
     * @return void
     */
    public function testSendThrowsWhenTokenMissing(): void
    {
        $client = $this->buildClient([]);

        $this->expectException(StufZknProviderException::class);
        $client->send(['baseUrl' => 'https://example.nl'], 'REF-1', '<zkn:zakLk01/>');

    }//end testSendThrowsWhenTokenMissing()

    /**
     * send() routes through MtlsTransportService (not the plain Guzzle client) when
     * `authentication.mode=mtls` is configured — proves the mTLS capability is actually
     * invoked, not merely built (orphaned-capability rule).
     *
     * @return void
     *
     * @spec openspec/specs/mtls-client-certificate-transport/spec.md#requirement-each-adapter-routes-through-the-mtls-transport-only-when-configured-proving-no-orphaned-capability-req-004
     */
    public function testSendRoutesThroughMtlsTransportWhenConfigured(): void
    {
        $mtlsTransport = $this->createMock(MtlsTransportService::class);
        $mtlsTransport->expects($this->once())
            ->method('request')
            ->with(
                $this->anything(),
                'POST',
                'https://stuf.gemeente-x.example.nl/inbound',
                $this->anything(),
                $this->isInstanceOf(MtlsCertificateBundle::class)
            )
            ->willReturn(new Response(200, [], ''));

        $mtlsConfigResolver = $this->createMock(MtlsConfigResolver::class);
        $mtlsConfigResolver->method('isMtlsConfigured')->willReturn(true);
        $mtlsConfigResolver->method('resolve')->willReturn(
            new MtlsCertificateBundle(certificatePem: 'CERT', privateKeyPem: 'KEY')
        );

        $client = new StufZknClient(new Client(), $this->crypto, $this->l, $this->logger, $mtlsConfigResolver, $mtlsTransport);

        $ref = $client->send(
            [
                'baseUrl'        => 'https://stuf.gemeente-x.example.nl/inbound',
                'authentication' => ['mode' => 'mtls', 'mtls' => []],
            ],
            'REF-1',
            '<zkn:zakLk01/>'
        );

        $this->assertStringStartsWith('REF-1-ack-', $ref);

    }//end testSendRoutesThroughMtlsTransportWhenConfigured()

    /**
     * send() never routes through MtlsTransportService when `mode=token` (the default) — proves
     * token mode is unchanged, mirrors the orphaned-capability rule in the other direction.
     *
     * @return void
     */
    public function testSendDoesNotRouteThroughMtlsTransportWhenTokenMode(): void
    {
        $mtlsTransport = $this->createMock(MtlsTransportService::class);
        $mtlsTransport->expects($this->never())->method('request');

        $mtlsConfigResolver = new MtlsConfigResolver($this->crypto);

        $mock  = new MockHandler([new Response(200, [], '')]);
        $stack = HandlerStack::create($mock);
        $this->history = [];
        $stack->push(Middleware::history($this->history));

        $client = new StufZknClient(
            new Client(['handler' => $stack]),
            $this->crypto,
            $this->l,
            $this->logger,
            $mtlsConfigResolver,
            $mtlsTransport
        );

        $client->send($this->configuration, 'REF-1', '<zkn:zakLk01/>');

        $this->assertCount(1, $this->history);

    }//end testSendDoesNotRouteThroughMtlsTransportWhenTokenMode()
}//end class

<?php

/**
 * Unit tests for IStandaardenClient.
 *
 * @category Test
 * @package  OCA\OpenConnector\Tests\Unit\Service\IwmoIjw
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 *
 * @spec openspec/changes/iwmo-ijw-adapter/tasks.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenConnector\Tests\Unit\Service\IwmoIjw;

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use OCA\OpenConnector\Exception\IwmoIjwProviderException;
use OCA\OpenConnector\Service\IwmoIjw\IStandaardenClient;
use OCP\IL10N;
use OCP\Security\ICrypto;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Tests for the generic REST iStandaarden client (auth header, body dispatch, ref extraction).
 *
 * @spec openspec/changes/iwmo-ijw-adapter/specs/iwmo-ijw-adapter/spec.md#requirement-iwmoijw-provider-abstraction-with-log-and-rest-bindings-req-001
 */
class IStandaardenClientTest extends TestCase
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
        'baseUrl'        => 'https://istandaarden.example.nl/api/v1',
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
     * @return IStandaardenClient The client under test.
     */
    private function buildClient(array $responses): IStandaardenClient
    {
        $mock  = new MockHandler($responses);
        $stack = HandlerStack::create($mock);

        $this->history = [];
        $stack->push(Middleware::history($this->history));

        return new IStandaardenClient(
            new Client(['handler' => $stack]),
            $this->crypto,
            $this->l,
            $this->logger
        );

    }//end buildClient()

    /**
     * getProviderId() returns "rest".
     *
     * @return void
     */
    public function testGetProviderId(): void
    {
        $client = $this->buildClient([]);
        $this->assertSame('rest', $client->getProviderId());

    }//end testGetProviderId()

    /**
     * send() carries the default Bearer Authorization header.
     *
     * @return void
     *
     * @spec openspec/changes/iwmo-ijw-adapter/specs/iwmo-ijw-adapter/spec.md#scenario-the-rest-provider-sends-the-expected-bearer-auth-header
     */
    public function testSendSendsBearerAuthorizationHeader(): void
    {
        $client = $this->buildClient([new Response(200, [], 'IWMO-abc123')]);

        $client->send($this->configuration, 'Wmo303', '<Bericht/>');

        $this->assertCount(1, $this->history);
        $this->assertSame(
            'Bearer raw-token-value',
            $this->history[0]['request']->getHeaderLine('Authorization')
        );

    }//end testSendSendsBearerAuthorizationHeader()

    /**
     * A configured authentication.scheme overrides the default Bearer scheme.
     *
     * @return void
     */
    public function testSendHonoursConfiguredAuthScheme(): void
    {
        $configuration                             = $this->configuration;
        $configuration['authentication']['scheme'] = 'Token';

        $client = $this->buildClient([new Response(200, [], 'IWMO-abc123')]);
        $client->send($configuration, 'Wmo303', '<Bericht/>');

        $this->assertSame(
            'Token raw-token-value',
            $this->history[0]['request']->getHeaderLine('Authorization')
        );

    }//end testSendHonoursConfiguredAuthScheme()

    /**
     * send() posts the envelope XML verbatim as the raw request body, with
     * the berichttype carried in an X-Berichttype header.
     *
     * @return void
     */
    public function testSendPostsEnvelopeVerbatim(): void
    {
        $client = $this->buildClient([new Response(200, [], 'IWMO-abc123')]);

        $client->send($this->configuration, 'Wmo303', '<Bericht><foo>bar</foo></Bericht>');

        $request = $this->history[0]['request'];
        $this->assertSame('POST', $request->getMethod());
        $this->assertSame('/api/v1/berichten', $request->getUri()->getPath());
        $this->assertSame('<Bericht><foo>bar</foo></Bericht>', (string) $request->getBody());
        $this->assertSame('Wmo303', $request->getHeaderLine('X-Berichttype'));

    }//end testSendPostsEnvelopeVerbatim()

    /**
     * send() extracts a bare plain-text reference from the response body.
     *
     * @return void
     */
    public function testSendExtractsPlainTextRef(): void
    {
        $client = $this->buildClient([new Response(200, [], "  IWMO-abc123\n")]);

        $ref = $client->send($this->configuration, 'Wmo303', '<Bericht/>');

        $this->assertSame('IWMO-abc123', $ref);

    }//end testSendExtractsPlainTextRef()

    /**
     * send() extracts a `ref` field from a JSON response body.
     *
     * @return void
     */
    public function testSendExtractsJsonRefField(): void
    {
        $client = $this->buildClient([new Response(200, [], json_encode(['ref' => 'IWMO-json-ref']))]);

        $ref = $client->send($this->configuration, 'Wmo303', '<Bericht/>');

        $this->assertSame('IWMO-json-ref', $ref);

    }//end testSendExtractsJsonRefField()

    /**
     * send() extracts a `referentienummer` field from a JSON response body when `ref` is absent.
     *
     * @return void
     */
    public function testSendExtractsJsonReferentienummerField(): void
    {
        $client = $this->buildClient(
            [new Response(200, [], json_encode(['referentienummer' => 'IWMO-refnr']))]
        );

        $ref = $client->send($this->configuration, 'Wmo303', '<Bericht/>');

        $this->assertSame('IWMO-refnr', $ref);

    }//end testSendExtractsJsonReferentienummerField()

    /**
     * A non-2xx response raises IwmoIjwProviderException.
     *
     * @return void
     */
    public function testSendThrowsOnNon2xxStatus(): void
    {
        $client = $this->buildClient([new Response(500, [], 'internal error')]);

        $this->expectException(IwmoIjwProviderException::class);
        $client->send($this->configuration, 'Wmo303', '<Bericht/>');

    }//end testSendThrowsOnNon2xxStatus()

    /**
     * An empty response body raises IwmoIjwProviderException.
     *
     * @return void
     */
    public function testSendThrowsOnEmptyResponseBody(): void
    {
        $client = $this->buildClient([new Response(200, [], '')]);

        $this->expectException(IwmoIjwProviderException::class);
        $client->send($this->configuration, 'Wmo303', '<Bericht/>');

    }//end testSendThrowsOnEmptyResponseBody()

    /**
     * A missing baseUrl raises IwmoIjwProviderException before any request is dispatched.
     *
     * @return void
     */
    public function testSendThrowsWhenBaseUrlMissing(): void
    {
        $client = $this->buildClient([]);

        try {
            $client->send(['authentication' => ['encryptedToken' => 'x']], 'Wmo303', '<Bericht/>');
            $this->fail('Expected IwmoIjwProviderException was not thrown.');
        } catch (IwmoIjwProviderException $exception) {
            $this->assertCount(0, $this->history);
        }

    }//end testSendThrowsWhenBaseUrlMissing()

    /**
     * A missing encryptedToken raises IwmoIjwProviderException before any request is dispatched.
     *
     * @return void
     */
    public function testSendThrowsWhenTokenMissing(): void
    {
        $client = $this->buildClient([]);

        $this->expectException(IwmoIjwProviderException::class);
        $client->send(['baseUrl' => 'https://example.nl'], 'Wmo303', '<Bericht/>');

    }//end testSendThrowsWhenTokenMissing()
}//end class

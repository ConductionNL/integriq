<?php

/**
 * Unit tests for DsoClient.
 *
 * @category Test
 * @package  OCA\OpenConnector\Tests\Unit\Service\Dso
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 *
 * @spec openspec/changes/dso-connector-adapter/tasks.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenConnector\Tests\Unit\Service\Dso;

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use OCA\OpenConnector\Exception\DsoProviderException;
use OCA\OpenConnector\Service\Dso\DsoClient;
use OCP\IL10N;
use OCP\Security\ICrypto;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Tests for the generic REST DSO outbound client (auth header, endpoint
 * routing, ref extraction).
 *
 * @spec openspec/changes/dso-connector-adapter/specs/dso-connector-adapter/spec.md#requirement-dso-outbound-provider-abstraction-with-log-and-rest-bindings-req-001
 */
class DsoClientTest extends TestCase
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
        'baseUrl'        => 'https://dso-lv.example.nl/api/v1',
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
     * @return DsoClient The client under test.
     */
    private function buildClient(array $responses): DsoClient
    {
        $mock  = new MockHandler($responses);
        $stack = HandlerStack::create($mock);

        $this->history = [];
        $stack->push(Middleware::history($this->history));

        return new DsoClient(
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
     * @spec openspec/changes/dso-connector-adapter/specs/dso-connector-adapter/spec.md#scenario-the-rest-provider-sends-the-expected-bearer-auth-header
     */
    public function testSendSendsBearerAuthorizationHeader(): void
    {
        $client = $this->buildClient([new Response(200, [], json_encode(['ref' => 'DSO-abc']))]);

        $client->send($this->configuration, 'dso-12345', 'status', ['status' => 'in_behandeling']);

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

        $client = $this->buildClient([new Response(200, [], json_encode(['ref' => 'DSO-abc']))]);
        $client->send($configuration, 'dso-12345', 'status', []);

        $this->assertSame(
            'Token raw-token-value',
            $this->history[0]['request']->getHeaderLine('Authorization')
        );

    }//end testSendHonoursConfiguredAuthScheme()

    /**
     * send() posts a `status` message to the /statussen path.
     *
     * @return void
     */
    public function testSendPostsStatusToStatussenPath(): void
    {
        $client = $this->buildClient([new Response(200, [], json_encode(['ref' => 'DSO-abc']))]);

        $client->send($this->configuration, 'dso-12345', 'status', ['status' => 'in_behandeling']);

        $request = $this->history[0]['request'];
        $this->assertSame('POST', $request->getMethod());
        $this->assertSame('/api/v1/statussen', $request->getUri()->getPath());
        $body = json_decode((string) $request->getBody(), true);
        $this->assertSame('in_behandeling', $body['status']);

    }//end testSendPostsStatusToStatussenPath()

    /**
     * send() posts a `besluit` message to the /besluiten path.
     *
     * @return void
     */
    public function testSendPostsBesluitToBesluitenPath(): void
    {
        $client = $this->buildClient([new Response(200, [], json_encode(['ref' => 'DSO-abc']))]);

        $client->send($this->configuration, 'dso-12345', 'besluit', ['besluit' => 'verleend']);

        $request = $this->history[0]['request'];
        $this->assertSame('/api/v1/besluiten', $request->getUri()->getPath());

    }//end testSendPostsBesluitToBesluitenPath()

    /**
     * send() extracts a `ref` field from a JSON response body.
     *
     * @return void
     */
    public function testSendExtractsJsonRefField(): void
    {
        $client = $this->buildClient([new Response(200, [], json_encode(['ref' => 'DSO-json-ref']))]);

        $ref = $client->send($this->configuration, 'dso-12345', 'status', []);

        $this->assertSame('DSO-json-ref', $ref);

    }//end testSendExtractsJsonRefField()

    /**
     * send() derives a local reference when the endpoint returns an empty
     * body — the message was still accepted (2xx), it just assigned no ref
     * of its own.
     *
     * @return void
     */
    public function testSendDerivesLocalRefOnEmptyBody(): void
    {
        $client = $this->buildClient([new Response(202, [], '')]);

        $ref = $client->send($this->configuration, 'dso-12345', 'status', []);

        $this->assertStringStartsWith('dso-12345-status-', $ref);

    }//end testSendDerivesLocalRefOnEmptyBody()

    /**
     * A non-2xx response raises DsoProviderException.
     *
     * @return void
     */
    public function testSendThrowsOnNon2xxStatus(): void
    {
        $client = $this->buildClient([new Response(500, [], 'internal error')]);

        $this->expectException(DsoProviderException::class);
        $client->send($this->configuration, 'dso-12345', 'status', []);

    }//end testSendThrowsOnNon2xxStatus()

    /**
     * An unrecognised message type raises DsoProviderException before any
     * request is dispatched.
     *
     * @return void
     */
    public function testSendThrowsOnUnknownType(): void
    {
        $client = $this->buildClient([]);

        try {
            $client->send($this->configuration, 'dso-12345', 'onbekend', []);
            $this->fail('Expected DsoProviderException was not thrown.');
        } catch (DsoProviderException $exception) {
            $this->assertCount(0, $this->history);
        }

    }//end testSendThrowsOnUnknownType()

    /**
     * A missing baseUrl raises DsoProviderException before any request is dispatched.
     *
     * @return void
     */
    public function testSendThrowsWhenBaseUrlMissing(): void
    {
        $client = $this->buildClient([]);

        try {
            $client->send(['authentication' => ['encryptedToken' => 'x']], 'dso-12345', 'status', []);
            $this->fail('Expected DsoProviderException was not thrown.');
        } catch (DsoProviderException $exception) {
            $this->assertCount(0, $this->history);
        }

    }//end testSendThrowsWhenBaseUrlMissing()

    /**
     * A missing encryptedToken raises DsoProviderException before any request is dispatched.
     *
     * @return void
     */
    public function testSendThrowsWhenTokenMissing(): void
    {
        $client = $this->buildClient([]);

        $this->expectException(DsoProviderException::class);
        $client->send(['baseUrl' => 'https://example.nl'], 'dso-12345', 'status', []);

    }//end testSendThrowsWhenTokenMissing()
}//end class

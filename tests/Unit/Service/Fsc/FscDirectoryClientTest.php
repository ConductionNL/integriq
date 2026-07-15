<?php

/**
 * Unit tests for FscDirectoryClient.
 *
 * @category Test
 * @package  OCA\OpenConnector\Tests\Unit\Service\Fsc
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 *
 * @spec openspec/changes/fsc-connectivity/tasks.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenConnector\Tests\Unit\Service\Fsc;

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use OCA\OpenConnector\Exception\FscConnectivityException;
use OCA\OpenConnector\Exception\FscDirectoryException;
use OCA\OpenConnector\Service\Fsc\FscDirectoryClient;
use OCP\IL10N;
use OCP\Security\ICrypto;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Tests for the generic REST FSC directory+call client (resolution, auth header,
 * ref extraction, error mapping).
 *
 * @spec openspec/changes/fsc-connectivity/specs/fsc-connectivity/spec.md#requirement-fsc-provider-abstraction-with-log-and-rest-bindings-req-001
 */
class FscDirectoryClientTest extends TestCase
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
     * The valid directory configuration used by every "happy path" test.
     *
     * @var array
     */
    private array $configuration = [
        'directoryUrl'    => 'https://fsc-directory.example.nl/api/v1',
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
     * @return FscDirectoryClient The client under test.
     */
    private function buildClient(array $responses): FscDirectoryClient
    {
        $mock  = new MockHandler($responses);
        $stack = HandlerStack::create($mock);

        $this->history = [];
        $stack->push(Middleware::history($this->history));

        return new FscDirectoryClient(
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
     * resolveService() issues a GET against the expected directory path.
     *
     * @return void
     *
     * @spec openspec/changes/fsc-connectivity/specs/fsc-connectivity/spec.md#scenario-a-known-organisation-and-service-resolve-to-a-routable-endpoint
     */
    public function testResolveServiceQueriesExpectedPath(): void
    {
        $client = $this->buildClient(
            [new Response(200, [], json_encode(['endpoint' => 'https://outway.example.nl/brp', 'grantRequired' => true]))]
        );

        $resolution = $client->resolveService($this->configuration, 'org-a', 'brp-bevragen');

        $this->assertCount(1, $this->history);
        $request = $this->history[0]['request'];
        $this->assertSame('GET', $request->getMethod());
        $this->assertSame('/api/v1/organisations/org-a/services/brp-bevragen', $request->getUri()->getPath());
        $this->assertSame('https://outway.example.nl/brp', $resolution['endpoint']);
        $this->assertTrue($resolution['grantRequired']);

    }//end testResolveServiceQueriesExpectedPath()

    /**
     * resolveService() raises FscDirectoryException on a 404.
     *
     * @return void
     *
     * @spec openspec/changes/fsc-connectivity/specs/fsc-connectivity/spec.md#scenario-an-unknown-organisation-is-rejected-before-any-call-is-attempted
     */
    public function testResolveServiceThrowsDirectoryExceptionOn404(): void
    {
        $client = $this->buildClient([new Response(404, [], '')]);

        $this->expectException(FscDirectoryException::class);
        $client->resolveService($this->configuration, 'org-a', 'unknown-service');

    }//end testResolveServiceThrowsDirectoryExceptionOn404()

    /**
     * resolveService() raises FscConnectivityException on a non-2xx, non-404 status.
     *
     * @return void
     */
    public function testResolveServiceThrowsConnectivityExceptionOnServerError(): void
    {
        $client = $this->buildClient([new Response(500, [], 'internal error')]);

        $this->expectException(FscConnectivityException::class);
        $this->expectExceptionMessageMatches('/500/');
        $client->resolveService($this->configuration, 'org-a', 'brp-bevragen');

    }//end testResolveServiceThrowsConnectivityExceptionOnServerError()

    /**
     * resolveService() raises FscConnectivityException on a non-JSON response.
     *
     * @return void
     */
    public function testResolveServiceThrowsOnNonJsonResponse(): void
    {
        $client = $this->buildClient([new Response(200, [], 'not-json')]);

        $this->expectException(FscConnectivityException::class);
        $client->resolveService($this->configuration, 'org-a', 'brp-bevragen');

    }//end testResolveServiceThrowsOnNonJsonResponse()

    /**
     * resolveService() raises FscConnectivityException when the response has no endpoint field.
     *
     * @return void
     */
    public function testResolveServiceThrowsWhenEndpointMissing(): void
    {
        $client = $this->buildClient([new Response(200, [], json_encode(['grantRequired' => false]))]);

        $this->expectException(FscConnectivityException::class);
        $client->resolveService($this->configuration, 'org-a', 'brp-bevragen');

    }//end testResolveServiceThrowsWhenEndpointMissing()

    /**
     * resolveService() throws before any request when directoryUrl is missing.
     *
     * @return void
     */
    public function testResolveServiceThrowsWhenDirectoryUrlMissing(): void
    {
        $client = $this->buildClient([]);

        try {
            $client->resolveService([], 'org-a', 'brp-bevragen');
            $this->fail('Expected FscConnectivityException was not thrown.');
        } catch (FscConnectivityException $exception) {
            $this->assertCount(0, $this->history);
        }

    }//end testResolveServiceThrowsWhenDirectoryUrlMissing()

    /**
     * call() carries the default Bearer Authorization header.
     *
     * @return void
     *
     * @spec openspec/changes/fsc-connectivity/specs/fsc-connectivity/spec.md#scenario-the-rest-provider-sends-the-expected-bearer-auth-header-on-call
     */
    public function testCallSendsBearerAuthorizationHeader(): void
    {
        $client     = $this->buildClient([new Response(200, [], json_encode(['ok' => true]))]);
        $resolution = ['endpoint' => 'https://outway.example.nl/brp'];

        $client->call($this->configuration, $resolution, 'POST', ['bsn' => '123']);

        $this->assertCount(1, $this->history);
        $this->assertSame(
            'Bearer raw-token-value',
            $this->history[0]['request']->getHeaderLine('Authorization')
        );

    }//end testCallSendsBearerAuthorizationHeader()

    /**
     * call() posts the payload as a JSON body against the resolved endpoint.
     *
     * @return void
     */
    public function testCallPostsJsonPayloadToResolvedEndpoint(): void
    {
        $client     = $this->buildClient([new Response(200, [], json_encode(['ok' => true]))]);
        $resolution = ['endpoint' => 'https://outway.example.nl/brp'];

        $client->call($this->configuration, $resolution, 'post', ['bsn' => '123']);

        $request = $this->history[0]['request'];
        $this->assertSame('POST', $request->getMethod());
        $this->assertSame('outway.example.nl', $request->getUri()->getHost());
        $this->assertSame('{"bsn":"123"}', (string) $request->getBody());

    }//end testCallPostsJsonPayloadToResolvedEndpoint()

    /**
     * call() extracts the X-FSC-Reference header when present.
     *
     * @return void
     */
    public function testCallExtractsRefFromHeader(): void
    {
        $client = $this->buildClient(
            [new Response(200, ['X-FSC-Reference' => 'FSC-header-ref'], json_encode(['ok' => true]))]
        );
        $resolution = ['endpoint' => 'https://outway.example.nl/brp'];

        $result = $client->call($this->configuration, $resolution, 'POST', []);

        $this->assertSame('FSC-header-ref', $result['ref']);

    }//end testCallExtractsRefFromHeader()

    /**
     * call() extracts a `ref` field from a JSON response body when no header is present.
     *
     * @return void
     */
    public function testCallExtractsRefFromJsonBody(): void
    {
        $client     = $this->buildClient([new Response(200, [], json_encode(['ref' => 'FSC-body-ref']))]);
        $resolution = ['endpoint' => 'https://outway.example.nl/brp'];

        $result = $client->call($this->configuration, $resolution, 'POST', []);

        $this->assertSame('FSC-body-ref', $result['ref']);

    }//end testCallExtractsRefFromJsonBody()

    /**
     * call() generates a reference when neither a header nor a JSON body field is present.
     *
     * @return void
     */
    public function testCallGeneratesRefWhenNoneProvided(): void
    {
        $client     = $this->buildClient([new Response(200, [], 'plain text ok')]);
        $resolution = ['endpoint' => 'https://outway.example.nl/brp'];

        $result = $client->call($this->configuration, $resolution, 'POST', []);

        $this->assertStringStartsWith('FSC-', $result['ref']);

    }//end testCallGeneratesRefWhenNoneProvided()

    /**
     * call() raises FscConnectivityException on a non-2xx status.
     *
     * @return void
     */
    public function testCallThrowsOnNon2xxStatus(): void
    {
        $client     = $this->buildClient([new Response(503, [], 'unavailable')]);
        $resolution = ['endpoint' => 'https://outway.example.nl/brp'];

        $this->expectException(FscConnectivityException::class);
        $client->call($this->configuration, $resolution, 'POST', []);

    }//end testCallThrowsOnNon2xxStatus()

    /**
     * call() throws before any request when the resolution carries no endpoint.
     *
     * @return void
     */
    public function testCallThrowsWhenResolutionHasNoEndpoint(): void
    {
        $client = $this->buildClient([]);

        try {
            $client->call($this->configuration, [], 'POST', []);
            $this->fail('Expected FscConnectivityException was not thrown.');
        } catch (FscConnectivityException $exception) {
            $this->assertCount(0, $this->history);
        }

    }//end testCallThrowsWhenResolutionHasNoEndpoint()

    /**
     * call() throws when the encryptedToken is missing from configuration.
     *
     * @return void
     */
    public function testCallThrowsWhenTokenMissing(): void
    {
        $client     = $this->buildClient([]);
        $resolution = ['endpoint' => 'https://outway.example.nl/brp'];

        $this->expectException(FscConnectivityException::class);
        $client->call(['directoryUrl' => 'https://example.nl'], $resolution, 'POST', []);

    }//end testCallThrowsWhenTokenMissing()
}//end class

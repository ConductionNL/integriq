<?php

/**
 * OpenConnector PDOK Connector Test
 *
 * @category Test
 * @package  OCA\OpenConnector\Tests\Unit\Connectors
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2024 Conduction B.V. <info@conduction.nl>
 *
 * @version GIT: <git-id>
 *
 * @link https://www.OpenConnector.nl
 */

declare(strict_types=1);

namespace OCA\OpenConnector\Tests\Unit\Connectors;

use OCA\OpenConnector\Connectors\PdokConnector;
use OCP\Http\Client\IClient;
use OCP\Http\Client\IClientService;
use OCP\Http\Client\IResponse;
use OCP\ICache;
use OCP\ICacheFactory;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Unit tests for PdokConnector — normalisation, cache behaviour, breaker logic.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 */
class PdokConnectorTest extends TestCase
{
    /**
     * @var IClientService|MockObject
     */
    private $clientService;

    /**
     * @var ICacheFactory|MockObject
     */
    private $cacheFactory;

    /**
     * @var ICache|MockObject
     */
    private $cache;

    /**
     * @var LoggerInterface|MockObject
     */
    private $logger;

    /**
     * @var ContainerInterface|MockObject
     */
    private $container;


    /**
     * Set up mocks shared across tests.
     *
     * @return void
     */
    protected function setUp(): void
    {
        $this->clientService = $this->createMock(IClientService::class);
        $this->cache         = $this->createMock(ICache::class);
        $this->cacheFactory  = $this->createMock(ICacheFactory::class);
        $this->cacheFactory->method('createDistributed')->willReturn($this->cache);
        $this->logger    = $this->createMock(LoggerInterface::class);
        $this->container = $this->createMock(ContainerInterface::class);
        $this->container->method('get')->willThrowException(new \RuntimeException('no OR'));

    }//end setUp()


    /**
     * `normalize` maps every documented PDOK field to the canonical shape on a full address.
     *
     * @return void
     */
    public function testNormalizeFullAddress(): void
    {
        $fixture   = $this->loadFixture('lauriergracht');
        $connector = $this->makeConnector();

        $result = $connector->normalize($fixture['response']['docs'][0]);

        $this->assertSame('adr-0363200000218908', $result['pdokId']);
        $this->assertSame('Lauriergracht', $result['streetAddress']);
        $this->assertSame('14h', $result['houseNumber']);
        $this->assertSame('1016RD', $result['postalCode']);
        $this->assertSame('Amsterdam', $result['addressLocality']);
        $this->assertSame('Noord-Holland', $result['addressRegion']);
        $this->assertSame('NL', $result['addressCountry']);
        $this->assertSame('0363200000218908', $result['bagAddressId']);
        $this->assertSame('pdok', $result['source']);
        $this->assertIsArray($result['location']);
        $this->assertSame('Point', $result['location']['type']);
        $this->assertSame([4.8825, 52.371], $result['location']['coordinates']);

    }//end testNormalizeFullAddress()


    /**
     * Woonplaats-only fixture maps optional fields to null (never absent).
     *
     * @return void
     */
    public function testNormalizeWoonplaatsHasNullFieldsPresent(): void
    {
        $fixture   = $this->loadFixture('woonplaats-tilburg');
        $connector = $this->makeConnector();

        $result = $connector->normalize($fixture['response']['docs'][0]);

        $this->assertArrayHasKey('postalCode', $result);
        $this->assertNull($result['postalCode']);
        $this->assertArrayHasKey('houseNumber', $result);
        $this->assertNull($result['houseNumber']);
        $this->assertArrayHasKey('streetAddress', $result);
        $this->assertNull($result['streetAddress']);
        $this->assertSame('Tilburg', $result['addressLocality']);
        $this->assertSame('pdok', $result['source']);

    }//end testNormalizeWoonplaatsHasNullFieldsPresent()


    /**
     * Stadhuisplein fixture: number without huisletter formatting.
     *
     * @return void
     */
    public function testNormalizeWithoutHuisletter(): void
    {
        $fixture   = $this->loadFixture('stadhuisplein-tilburg');
        $connector = $this->makeConnector();

        $result = $connector->normalize($fixture['response']['docs'][0]);

        $this->assertSame('1', $result['houseNumber']);
        $this->assertSame('5038TC', $result['postalCode']);
        $this->assertSame('Tilburg', $result['addressLocality']);

    }//end testNormalizeWithoutHuisletter()


    /**
     * Cache hit on `suggest` skips the HTTP call entirely.
     *
     * @return void
     */
    public function testSuggestCacheHitSkipsUpstream(): void
    {
        $cached = ['docs' => [['pdokId' => 'cached']], 'numFound' => 1];
        $this->cache->method('get')->willReturn($cached);
        $this->clientService->expects($this->never())->method('newClient');

        $result = $this->makeConnector()->suggest('lauriergracht');
        $this->assertSame($cached, $result);

    }//end testSuggestCacheHitSkipsUpstream()


    /**
     * Open circuit returns the stale-flagged envelope without calling upstream.
     *
     * @return void
     */
    public function testOpenCircuitShortCircuitsUpstream(): void
    {
        $this->cache->method('get')->willReturnCallback(function ($key) {
            if (str_contains($key, '::circuit')) {
                return ['state' => 'open', 'failures' => 5, 'opened_at' => time()];
            }
            return null;
        });
        $this->clientService->expects($this->never())->method('newClient');

        $result = $this->makeConnector()->lookup('adr-0363200000218908');

        $this->assertTrue($result['stale']);
        $this->assertSame(0, $result['numFound']);

    }//end testOpenCircuitShortCircuitsUpstream()


    /**
     * Empty query parameter short-circuits the upstream call.
     *
     * @return void
     */
    public function testEmptyQueryReturnsEmpty(): void
    {
        $this->clientService->expects($this->never())->method('newClient');
        $result = $this->makeConnector()->suggest('   ');
        $this->assertSame(0, $result['numFound']);
        $this->assertSame([], $result['docs']);

    }//end testEmptyQueryReturnsEmpty()


    /**
     * A successful 200 response is decoded, normalised and cached.
     *
     * @return void
     */
    public function testSuccessfulFreeTextSearch(): void
    {
        $fixture = $this->loadFixture('lauriergracht');

        $response = $this->createMock(IResponse::class);
        $response->method('getStatusCode')->willReturn(200);
        $response->method('getBody')->willReturn(json_encode($fixture));

        $client = $this->createMock(IClient::class);
        $client->method('get')->willReturn($response);
        $this->clientService->method('newClient')->willReturn($client);
        $this->cache->method('get')->willReturn(null);

        $result = $this->makeConnector()->free('Lauriergracht');

        $this->assertSame(1, $result['numFound']);
        $this->assertSame('Lauriergracht', $result['docs'][0]['streetAddress']);

    }//end testSuccessfulFreeTextSearch()


    /**
     * Build a fresh connector with the shared mocks.
     *
     * @return PdokConnector
     */
    private function makeConnector(): PdokConnector
    {
        return new PdokConnector(
            $this->clientService,
            $this->cacheFactory,
            $this->logger,
            $this->container
        );

    }//end makeConnector()


    /**
     * Load a raw PDOK fixture by name.
     *
     * @param string $name The fixture file stem.
     *
     * @return array Decoded fixture payload.
     */
    private function loadFixture(string $name): array
    {
        $path = (__DIR__.'/../../fixtures/pdok/fixture-'.$name.'.json');
        return json_decode((string) file_get_contents($path), true);

    }//end loadFixture()


}//end class

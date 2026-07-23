<?php

/**
 * Unit tests for LogFscConnectivityProvider.
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

use OCA\OpenConnector\Exception\FscDirectoryException;
use OCA\OpenConnector\Service\Fsc\LogFscConnectivityProvider;
use PHPUnit\Framework\TestCase;

/**
 * Tests for the sandbox FSC provider (found/unknown-organisation/unknown-service
 * resolution against a static stand-in, synthetic call refs, no network).
 *
 * @spec openspec/changes/fsc-connectivity/specs/fsc-connectivity/spec.md#requirement-directory-resolution-req-002
 */
class LogFscConnectivityProviderTest extends TestCase
{

    /**
     * @var LogFscConnectivityProvider
     */
    private LogFscConnectivityProvider $provider;

    /**
     * A directory configuration with one known organisation/service.
     *
     * @var array
     */
    private array $directoryConfiguration = [
        'knownServices' => [
            '00000001823288444000' => [
                'brp-bevragen' => ['endpoint' => 'https://outway.example.nl/brp', 'grantRequired' => true],
            ],
        ],
    ];

    /**
     * Set up test fixtures.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();
        $this->provider = new LogFscConnectivityProvider();

    }//end setUp()

    /**
     * getProviderId() returns "log".
     *
     * @return void
     */
    public function testGetProviderId(): void
    {
        $this->assertSame('log', $this->provider->getProviderId());

    }//end testGetProviderId()

    /**
     * resolveService() resolves a known organisation+service to its configured endpoint.
     *
     * @return void
     *
     * @spec openspec/changes/fsc-connectivity/specs/fsc-connectivity/spec.md#scenario-a-known-organisation-and-service-resolve-to-a-routable-endpoint
     */
    public function testResolveServiceResolvesKnownEntry(): void
    {
        $resolution = $this->provider->resolveService(
            $this->directoryConfiguration,
            '00000001823288444000',
            'brp-bevragen'
        );

        $this->assertSame('https://outway.example.nl/brp', $resolution['endpoint']);
        $this->assertTrue($resolution['grantRequired']);
        $this->assertSame('00000001823288444000', $resolution['organisation']);
        $this->assertSame('brp-bevragen', $resolution['service']);

    }//end testResolveServiceResolvesKnownEntry()

    /**
     * resolveService() raises FscDirectoryException naming "organisation" for an unknown organisation.
     *
     * @return void
     *
     * @spec openspec/changes/fsc-connectivity/specs/fsc-connectivity/spec.md#scenario-an-unknown-organisation-is-rejected-before-any-call-is-attempted
     */
    public function testResolveServiceThrowsForUnknownOrganisation(): void
    {
        $this->expectException(FscDirectoryException::class);
        $this->expectExceptionMessageMatches('/organisation/');

        $this->provider->resolveService($this->directoryConfiguration, 'unknown-org', 'brp-bevragen');

    }//end testResolveServiceThrowsForUnknownOrganisation()

    /**
     * resolveService() raises FscDirectoryException naming "service" for a known org, unknown service.
     *
     * @return void
     *
     * @spec openspec/changes/fsc-connectivity/specs/fsc-connectivity/spec.md#scenario-a-known-organisation-with-an-unknown-service-is-rejected
     */
    public function testResolveServiceThrowsForUnknownService(): void
    {
        $this->expectException(FscDirectoryException::class);
        $this->expectExceptionMessageMatches('/service/');

        $this->provider->resolveService($this->directoryConfiguration, '00000001823288444000', 'unknown-service');

    }//end testResolveServiceThrowsForUnknownService()

    /**
     * resolveService() with an empty knownServices config throws for any organisation.
     *
     * @return void
     */
    public function testResolveServiceThrowsWithNoKnownServicesConfigured(): void
    {
        $this->expectException(FscDirectoryException::class);
        $this->provider->resolveService([], 'any-org', 'any-service');

    }//end testResolveServiceThrowsWithNoKnownServicesConfigured()

    /**
     * resolveService() defaults endpoint/grantRequired when the entry carries no fields.
     *
     * @return void
     */
    public function testResolveServiceDefaultsWhenEntryHasNoFields(): void
    {
        $configuration = ['knownServices' => ['org-a' => ['svc-a' => []]]];

        $resolution = $this->provider->resolveService($configuration, 'org-a', 'svc-a');

        $this->assertSame('log://org-a/svc-a', $resolution['endpoint']);
        $this->assertFalse($resolution['grantRequired']);

    }//end testResolveServiceDefaultsWhenEntryHasNoFields()

    /**
     * call() performs no network call and returns a synthetic ref.
     *
     * @return void
     *
     * @spec openspec/changes/fsc-connectivity/specs/fsc-connectivity/spec.md#scenario-the-log-provider-performs-no-network-call
     */
    public function testCallReturnsSyntheticRefAndEchoesPayload(): void
    {
        $resolution = $this->provider->resolveService(
            $this->directoryConfiguration,
            '00000001823288444000',
            'brp-bevragen'
        );

        $result = $this->provider->call($this->directoryConfiguration, $resolution, 'POST', ['bsn' => '999995571']);

        $this->assertStringStartsWith('FSC-MOCK-', $result['ref']);
        $this->assertSame(200, $result['statusCode']);
        $this->assertSame(['bsn' => '999995571'], $result['body']);

    }//end testCallReturnsSyntheticRefAndEchoesPayload()

    /**
     * call() issued twice returns two distinct incrementing refs.
     *
     * @return void
     */
    public function testCallReturnsDistinctRefsAcrossInvocations(): void
    {
        $resolution = $this->provider->resolveService(
            $this->directoryConfiguration,
            '00000001823288444000',
            'brp-bevragen'
        );

        $first  = $this->provider->call($this->directoryConfiguration, $resolution, 'POST', []);
        $second = $this->provider->call($this->directoryConfiguration, $resolution, 'POST', []);

        $this->assertNotSame($first['ref'], $second['ref']);

    }//end testCallReturnsDistinctRefsAcrossInvocations()
}//end class

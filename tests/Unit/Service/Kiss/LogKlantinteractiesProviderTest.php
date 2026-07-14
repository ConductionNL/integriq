<?php

/**
 * Unit tests for LogKlantinteractiesProvider.
 *
 * @category Test
 * @package  OCA\OpenConnector\Tests\Unit\Service\Kiss
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 *
 * @spec openspec/changes/kiss-kcc-bridge/tasks.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenConnector\Tests\Unit\Service\Kiss;

use OCA\OpenConnector\Service\Kiss\LogKlantinteractiesProvider;
use PHPUnit\Framework\TestCase;

/**
 * Tests for the sandbox KISS provider (no network call, no secret).
 *
 * @spec openspec/changes/kiss-kcc-bridge/specs/kiss-kcc-bridge/spec.md#requirement-klantinteracties-provider-abstraction-with-log-and-rest-bindings
 */
class LogKlantinteractiesProviderTest extends TestCase
{

    /**
     * @var LogKlantinteractiesProvider
     */
    private LogKlantinteractiesProvider $provider;

    /**
     * Set up test fixtures.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->provider = new LogKlantinteractiesProvider();

    }//end setUp()

    /**
     * listKlantcontacten() always returns an empty page with a null cursor.
     *
     * @return void
     */
    public function testListReturnsEmptyPage(): void
    {
        $result = $this->provider->listKlantcontacten(sourceConfiguration: [], since: '2026-01-01T00:00:00+00:00', pageSize: 10);

        $this->assertSame([], $result['items']);
        $this->assertNull($result['nextCursor']);

    }//end testListReturnsEmptyPage()

    /**
     * createKlantcontact() returns a synthetic MOCK-KISS-<n> id.
     *
     * @return void
     */
    public function testCreateKlantcontactReturnsSyntheticId(): void
    {
        $id = $this->provider->createKlantcontact(sourceConfiguration: [], payload: ['onderwerp' => 'x']);

        $this->assertStringStartsWith('MOCK-KISS-', $id);

    }//end testCreateKlantcontactReturnsSyntheticId()

    /**
     * linkOnderwerpobject() returns a synthetic MOCK-KISS-<n> id.
     *
     * @return void
     */
    public function testLinkOnderwerpobjectReturnsSyntheticId(): void
    {
        $id = $this->provider->linkOnderwerpobject(
            sourceConfiguration: [],
            klantcontactId: 'kc-1',
            caseReference: 'zaak-1',
            caseObjectType: 'zaak'
        );

        $this->assertStringStartsWith('MOCK-KISS-', $id);

    }//end testLinkOnderwerpobjectReturnsSyntheticId()

    /**
     * getProviderId()/getConfigSchema() expose the `log` identity and an empty schema.
     *
     * @return void
     */
    public function testProviderIdentity(): void
    {
        $this->assertSame('log', $this->provider->getProviderId());
        $this->assertSame(['type' => 'object', 'properties' => []], $this->provider->getConfigSchema());

    }//end testProviderIdentity()
}//end class

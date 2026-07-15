<?php

/**
 * Unit tests for LogIwmoIjwProvider.
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

use OCA\OpenConnector\Service\IwmoIjw\LogIwmoIjwProvider;
use PHPUnit\Framework\TestCase;

/**
 * Tests for the sandbox iWMO/iJW provider.
 *
 * @spec openspec/changes/iwmo-ijw-adapter/specs/iwmo-ijw-adapter/spec.md#requirement-iwmoijw-provider-abstraction-with-log-and-rest-bindings-req-001
 */
class LogIwmoIjwProviderTest extends TestCase
{

    /**
     * @var LogIwmoIjwProvider
     */
    private LogIwmoIjwProvider $provider;

    /**
     * Set up test fixtures.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();
        $this->provider = new LogIwmoIjwProvider();

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
     * getConfigSchema() needs no configuration.
     *
     * @return void
     */
    public function testGetConfigSchemaIsEmpty(): void
    {
        $schema = $this->provider->getConfigSchema();
        $this->assertSame('object', $schema['type']);
        $this->assertSame([], $schema['properties']);

    }//end testGetConfigSchemaIsEmpty()

    /**
     * send() returns a synthetic MOCK-IWMO-<n> ref with no configuration needed.
     *
     * @return void
     *
     * @spec openspec/changes/iwmo-ijw-adapter/specs/iwmo-ijw-adapter/spec.md#scenario-the-log-provider-sends-nothing-over-the-network-and-returns-a-synthetic-ref
     */
    public function testSendReturnsSyntheticRef(): void
    {
        $ref = $this->provider->send([], 'Wmo303', '<Bericht/>');
        $this->assertStringStartsWith('MOCK-IWMO-', $ref);

    }//end testSendReturnsSyntheticRef()

    /**
     * Successive send() calls return distinct synthetic refs.
     *
     * @return void
     */
    public function testSendReturnsDistinctRefsAcrossCalls(): void
    {
        $first  = $this->provider->send([], 'Wmo303', '<Bericht/>');
        $second = $this->provider->send([], 'Wmo321', '<Bericht/>');
        $this->assertNotSame($first, $second);

    }//end testSendReturnsDistinctRefsAcrossCalls()
}//end class

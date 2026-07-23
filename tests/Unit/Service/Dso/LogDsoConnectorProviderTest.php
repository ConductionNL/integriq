<?php

/**
 * Unit tests for LogDsoConnectorProvider.
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

use OCA\OpenConnector\Service\Dso\LogDsoConnectorProvider;
use PHPUnit\Framework\TestCase;

/**
 * Tests for the sandbox DSO outbound provider.
 *
 * @spec openspec/changes/dso-connector-adapter/specs/dso-connector-adapter/spec.md#requirement-dso-outbound-provider-abstraction-with-log-and-rest-bindings-req-001
 */
class LogDsoConnectorProviderTest extends TestCase
{

    /**
     * @var LogDsoConnectorProvider
     */
    private LogDsoConnectorProvider $provider;

    /**
     * Set up test fixtures.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();
        $this->provider = new LogDsoConnectorProvider();

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
     * send() returns a synthetic MOCK-DSO-<n> ref with no configuration needed.
     *
     * @return void
     *
     * @spec openspec/changes/dso-connector-adapter/specs/dso-connector-adapter/spec.md#scenario-the-log-provider-sends-nothing-over-the-network-and-returns-a-synthetic-ref
     */
    public function testSendReturnsSyntheticRef(): void
    {
        $ref = $this->provider->send([], 'dso-12345', 'status', ['status' => 'in_behandeling']);
        $this->assertStringStartsWith('MOCK-DSO-', $ref);

    }//end testSendReturnsSyntheticRef()

    /**
     * Successive send() calls return distinct synthetic refs.
     *
     * @return void
     */
    public function testSendReturnsDistinctRefsAcrossCalls(): void
    {
        $first  = $this->provider->send([], 'dso-1', 'status', []);
        $second = $this->provider->send([], 'dso-2', 'besluit', []);
        $this->assertNotSame($first, $second);

    }//end testSendReturnsDistinctRefsAcrossCalls()
}//end class

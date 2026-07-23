<?php

/**
 * Unit tests for LogStufZknProvider.
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

use OCA\OpenConnector\Service\StufZkn\LogStufZknProvider;
use PHPUnit\Framework\TestCase;

/**
 * Tests for the sandbox StUF-ZKN outbound provider.
 *
 * @spec openspec/changes/stuf-zkn-bridge/specs/stuf-zkn-bridge/spec.md#scenario-the-log-provider-sends-nothing-over-the-network-and-returns-a-synthetic-ref
 */
class LogStufZknProviderTest extends TestCase
{

    /**
     * getProviderId() returns "log".
     *
     * @return void
     */
    public function testGetProviderId(): void
    {
        $this->assertSame('log', (new LogStufZknProvider())->getProviderId());

    }//end testGetProviderId()

    /**
     * send() returns a synthetic MOCK-STUFZKN-<n> reference and never touches its arguments.
     *
     * @return void
     */
    public function testSendReturnsSyntheticReference(): void
    {
        $provider = new LogStufZknProvider();
        $ref      = $provider->send([], 'REF-1', '<Envelope/>');

        $this->assertStringStartsWith('MOCK-STUFZKN-', $ref);

    }//end testSendReturnsSyntheticReference()

    /**
     * getConfigSchema() declares no required configuration.
     *
     * @return void
     */
    public function testConfigSchemaIsEmpty(): void
    {
        $schema = (new LogStufZknProvider())->getConfigSchema();
        $this->assertSame(['type' => 'object', 'properties' => []], $schema);

    }//end testConfigSchemaIsEmpty()
}//end class

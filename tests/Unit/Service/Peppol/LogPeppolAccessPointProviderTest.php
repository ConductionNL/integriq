<?php

/**
 * Unit tests for LogPeppolAccessPointProvider.
 *
 * @category Test
 * @package  OCA\OpenConnector\Tests\Unit\Service\Peppol
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 *
 * @spec openspec/changes/peppol-access-point-connector/tasks.md#task-2
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenConnector\Tests\Unit\Service\Peppol;

use OCA\OpenConnector\Service\Peppol\LogPeppolAccessPointProvider;
use PHPUnit\Framework\TestCase;

/**
 * Tests for the sandbox/mock Peppol Access Point provider.
 *
 * @spec openspec/changes/peppol-access-point-connector/specs/peppol-access-point-connector/spec.md#requirement-access-point-provider-abstraction-with-log-and-generic-rest-bindings-req-002
 */
class LogPeppolAccessPointProviderTest extends TestCase
{

    /**
     * @var LogPeppolAccessPointProvider
     */
    private LogPeppolAccessPointProvider $provider;

    /**
     * Set up test fixtures.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();
        $this->provider = new LogPeppolAccessPointProvider();

    }//end setUp()

    /**
     * A participant present in configuration.mockParticipants resolves as existing.
     *
     * @return void
     */
    public function testLookupParticipantFoundInMockList(): void
    {
        $result = $this->provider->lookupParticipant(
            sourceConfiguration: ['mockParticipants' => ['0106:00000000', '0106:11111111']],
            peppolId: '0106:00000000'
        );

        $this->assertTrue($result['exists']);
        $this->assertContains('ubl-invoice-2.1', $result['supportedDocTypes']);

    }//end testLookupParticipantFoundInMockList()

    /**
     * A participant absent from configuration.mockParticipants resolves as not-existing.
     *
     * @return void
     */
    public function testLookupParticipantNotFoundReturnsExistsFalse(): void
    {
        $result = $this->provider->lookupParticipant(
            sourceConfiguration: ['mockParticipants' => ['0106:00000000']],
            peppolId: '0192:9999999999'
        );

        $this->assertFalse($result['exists']);
        $this->assertSame([], $result['supportedDocTypes']);

    }//end testLookupParticipantNotFoundReturnsExistsFalse()

    /**
     * An empty/missing mockParticipants configuration does not error.
     *
     * @return void
     */
    public function testLookupParticipantWithNoMockParticipantsConfigured(): void
    {
        $result = $this->provider->lookupParticipant(sourceConfiguration: [], peppolId: '0192:1234567890');

        $this->assertFalse($result['exists']);

    }//end testLookupParticipantWithNoMockParticipantsConfigured()

    /**
     * submitDocument returns a synthetic MOCK-PEPPOL-<n> id with an incrementing suffix.
     *
     * @return void
     */
    public function testSubmitDocumentReturnsIncrementingMockId(): void
    {
        $first  = $this->provider->submitDocument(
            sourceConfiguration: [],
            recipientPeppolId: '0192:1234567890',
            documentType: 'ubl-invoice-2.1',
            payload: 'https://example.com/invoice.xml'
        );
        $second = $this->provider->submitDocument(
            sourceConfiguration: [],
            recipientPeppolId: '0192:1234567890',
            documentType: 'ubl-invoice-2.1',
            payload: 'https://example.com/invoice-2.xml'
        );

        $this->assertMatchesRegularExpression('/^MOCK-PEPPOL-\d+$/', $first);
        $this->assertMatchesRegularExpression('/^MOCK-PEPPOL-\d+$/', $second);
        $this->assertNotSame($first, $second);

    }//end testSubmitDocumentReturnsIncrementingMockId()
}//end class

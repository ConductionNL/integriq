<?php

/**
 * Unit tests for StufZknAcknowledgementBuilder.
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

use OCA\OpenConnector\Service\StufZkn\StufZknAcknowledgementBuilder;
use PHPUnit\Framework\TestCase;

/**
 * Tests for Bv03/Fo03 StUF-ZKN reply shaping, including the no-internal-detail-leakage guard.
 *
 * @spec openspec/changes/stuf-zkn-bridge/specs/stuf-zkn-bridge/spec.md#requirement-bv03-fo03-acknowledgement-shaping-req-004
 */
class StufZknAcknowledgementBuilderTest extends TestCase
{

    /**
     * @var StufZknAcknowledgementBuilder
     */
    private StufZknAcknowledgementBuilder $builder;

    /**
     * Set up test fixtures.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();
        $this->builder = new StufZknAcknowledgementBuilder();

    }//end setUp()

    /**
     * A successfully processed zakLk01 replies with a well-formed Bv03 correlated via crossRefnummer.
     *
     * @return void
     *
     * @spec openspec/changes/stuf-zkn-bridge/specs/stuf-zkn-bridge/spec.md#scenario-a-successfully-processed-zaklk01-replies-with-a-bv03
     */
    public function testBv03CorrelatesViaCrossRefnummer(): void
    {
        $xml = $this->builder->buildBv03('REF-123', 'OpenConnector', 'Gemeente X');

        $this->assertStringContainsString('StUF:Bv03Bericht', $xml);
        $this->assertStringContainsString('<StUF:crossRefnummer>REF-123</StUF:crossRefnummer>', $xml);
        $this->assertStringContainsString('<StUF:berichtcode>Bv03</StUF:berichtcode>', $xml);

        $parsed = simplexml_load_string($xml);
        $this->assertNotFalse($parsed, 'Bv03 output must be well-formed XML');

    }//end testBv03CorrelatesViaCrossRefnummer()

    /**
     * An unprocessable message replies with a Fo03 and leaks no internal detail — the
     * omschrijving is always the fixed catalogue text, never a raw exception message.
     *
     * @return void
     *
     * @spec openspec/changes/stuf-zkn-bridge/specs/stuf-zkn-bridge/spec.md#scenario-an-unprocessable-message-replies-with-a-fo03-and-leaks-no-internal-detail
     */
    public function testFo03NeverLeaksInternalDetail(): void
    {
        $secret = 'SQLSTATE[42S02]: Base table or view not found: super/secret/path/leak.php:42';
        $xml    = $this->builder->buildFo03($secret, 'REF-456', 'OpenConnector', 'Gemeente X');

        $this->assertStringNotContainsString($secret, $xml);
        $this->assertStringContainsString('StUF:Fo03Bericht', $xml);
        $this->assertStringContainsString('<StUF:crossRefnummer>REF-456</StUF:crossRefnummer>', $xml);
        $this->assertStringContainsString('<StUF:code>StUF055</StUF:code>', $xml);

        $parsed = simplexml_load_string($xml);
        $this->assertNotFalse($parsed, 'Fo03 output must be well-formed XML');

    }//end testFo03NeverLeaksInternalDetail()

    /**
     * A recognised fault reason maps to its documented, fixed catalogue entry.
     *
     * @return void
     */
    public function testRecognisedFaultReasonMapsToCatalogueEntry(): void
    {
        $xml = $this->builder->buildFo03('validation_failed', 'REF-1', 'OpenConnector', 'Gemeente X');
        $this->assertStringContainsString('<StUF:code>StUF063</StUF:code>', $xml);

    }//end testRecognisedFaultReasonMapsToCatalogueEntry()

    /**
     * Fo03 tolerates an empty crossRefnummer (e.g. a malformed envelope that never even yielded a
     * referentienummer) by omitting the element rather than emitting an empty tag.
     *
     * @return void
     */
    public function testFo03WithEmptyCrossRefnummerOmitsElement(): void
    {
        $xml = $this->builder->buildFo03('validation_failed', '', 'OpenConnector', '');
        $this->assertStringNotContainsString('crossRefnummer', $xml);

    }//end testFo03WithEmptyCrossRefnummerOmitsElement()
}//end class

<?php

/**
 * Unit tests for BesluitTranslator.
 *
 * @category Test
 * @package  OCA\OpenConnector\Tests\Unit\Service\ZgwVersion
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 *
 * @spec openspec/changes/zgw-version-translation/tasks.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenConnector\Tests\Unit\Service\ZgwVersion;

use OCA\OpenConnector\Exception\ZgwLiteralLeakException;
use OCA\OpenConnector\Service\ZgwVersion\BesluitTranslator;
use PHPUnit\Framework\TestCase;

/**
 * Tests for the `besluit` resource translator.
 *
 * @spec openspec/changes/zgw-version-translation/specs/zgw-version-translation/spec.md#requirement-per-resource-translator-seam-with-a-literal-leak-guard-req-001
 */
class BesluitTranslatorTest extends TestCase
{

    /**
     * @var BesluitTranslator
     */
    private BesluitTranslator $translator;

    /**
     * Set up test fixtures.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();
        $this->translator = new BesluitTranslator();
    }//end setUp()

    /**
     * A conformant `1.0` besluit fixture.
     *
     * @return array<string, mixed>
     */
    private function conformantPayload(): array
    {
        return [
            'url'           => 'https://host/api/zgw/besluiten/v1/besluiten/bes',
            'uuid'          => 'bes',
            'identificatie' => 'BES-001',
            'zaak'          => 'https://host/api/zgw/zaken/v1/zaken/abc',
            'besluittype'   => 'https://host/api/zgw/catalogi/v1/besluittypen/bt',
            'datum'         => '2026-01-01',
        ];
    }//end conformantPayload()

    /**
     * @return void
     */
    public function testGetResource(): void
    {
        $this->assertSame(expected: 'besluit', actual: $this->translator->getResource());
    }//end testGetResource()

    /**
     * @return void
     */
    public function testTranslateToV16IsStructurallyIdentical(): void
    {
        $payload = $this->conformantPayload();
        $this->assertSame(expected: $payload, actual: $this->translator->translateToV16(payload: $payload));
    }//end testTranslateToV16IsStructurallyIdentical()

    /**
     * @return void
     */
    public function testTranslateToV1xIsStructurallyIdentical(): void
    {
        $payload = $this->conformantPayload();
        $this->assertSame(expected: $payload, actual: $this->translator->translateToV1x(payload: $payload));
    }//end testTranslateToV1xIsStructurallyIdentical()

    /**
     * @return void
     */
    public function testMissingRequiredFieldThrowsLiteralLeak(): void
    {
        $payload = $this->conformantPayload();
        unset($payload['besluittype']);

        $this->expectException(ZgwLiteralLeakException::class);
        $this->translator->translateToV16(payload: $payload);
    }//end testMissingRequiredFieldThrowsLiteralLeak()

    /**
     * @return void
     */
    public function testRoundTripIsLossless(): void
    {
        $payload      = $this->conformantPayload();
        $roundTripped = $this->translator->translateToV1x(
            payload: $this->translator->translateToV16(payload: $payload)
        );

        $this->assertSame(expected: $payload, actual: $roundTripped);
    }//end testRoundTripIsLossless()
}//end class

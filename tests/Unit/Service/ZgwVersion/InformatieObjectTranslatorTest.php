<?php

/**
 * Unit tests for InformatieObjectTranslator.
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
use OCA\OpenConnector\Service\ZgwVersion\InformatieObjectTranslator;
use PHPUnit\Framework\TestCase;

/**
 * Tests for the `enkelvoudiginformatieobject` resource translator.
 *
 * @spec openspec/changes/zgw-version-translation/specs/zgw-version-translation/spec.md#requirement-per-resource-translator-seam-with-a-literal-leak-guard-req-001
 */
class InformatieObjectTranslatorTest extends TestCase
{

    /**
     * @var InformatieObjectTranslator
     */
    private InformatieObjectTranslator $translator;

    /**
     * Set up test fixtures.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();
        $this->translator = new InformatieObjectTranslator();
    }//end setUp()

    /**
     * A conformant `1.0` enkelvoudiginformatieobject fixture.
     *
     * @return array<string, mixed>
     */
    private function conformantPayload(): array
    {
        return [
            'url'                         => 'https://host/api/zgw/documenten/v1/enkelvoudiginformatieobjecten/eio',
            'uuid'                        => 'eio',
            'identificatie'               => 'DOC-001',
            'bronorganisatie'             => '123456782',
            'titel'                       => 'Test document',
            'vertrouwelijkheidaanduiding' => 'openbaar',
            'status'                      => 'definitief',
            'informatieobjecttype'        => 'https://host/api/zgw/catalogi/v1/informatieobjecttypen/iot',
        ];
    }//end conformantPayload()

    /**
     * @return void
     */
    public function testGetResource(): void
    {
        $this->assertSame(expected: 'enkelvoudiginformatieobject', actual: $this->translator->getResource());
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
    public function testOutOfSetStatusEnumThrowsLiteralLeak(): void
    {
        $payload = $this->conformantPayload();
        $payload['status'] = 'published';

        $this->expectException(ZgwLiteralLeakException::class);
        $this->translator->translateToV16(payload: $payload);
    }//end testOutOfSetStatusEnumThrowsLiteralLeak()

    /**
     * @return void
     */
    public function testOutOfSetVertrouwelijkheidEnumThrowsLiteralLeak(): void
    {
        $payload = $this->conformantPayload();
        $payload['vertrouwelijkheidaanduiding'] = 'public-domain';

        $this->expectException(ZgwLiteralLeakException::class);
        $this->translator->translateToV1x(payload: $payload);
    }//end testOutOfSetVertrouwelijkheidEnumThrowsLiteralLeak()

    /**
     * @return void
     */
    public function testMissingRequiredFieldThrowsLiteralLeak(): void
    {
        $payload = $this->conformantPayload();
        unset($payload['informatieobjecttype']);

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

<?php

/**
 * Unit tests for ZaakTranslator.
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
use OCA\OpenConnector\Service\ZgwVersion\ZaakTranslator;
use PHPUnit\Framework\TestCase;

/**
 * Tests for the `zaak` resource translator.
 *
 * @spec openspec/changes/zgw-version-translation/specs/zgw-version-translation/spec.md#requirement-per-resource-translator-seam-with-a-literal-leak-guard-req-001
 */
class ZaakTranslatorTest extends TestCase
{

    /**
     * @var ZaakTranslator
     */
    private ZaakTranslator $translator;

    /**
     * Set up test fixtures.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();
        $this->translator = new ZaakTranslator();
    }//end setUp()

    /**
     * A conformant `1.0` zaak fixture.
     *
     * @return array<string, mixed>
     */
    private function conformantPayload(): array
    {
        return [
            'url'                          => 'https://host/api/zgw/zaken/v1/zaken/abc',
            'uuid'                         => 'abc',
            'identificatie'                => 'ZAAK-001',
            'bronorganisatie'              => '123456782',
            'omschrijving'                 => 'Test zaak',
            'toelichting'                  => '',
            'zaaktype'                     => 'https://host/api/zgw/catalogi/v1/zaaktypen/def',
            'registratiedatum'             => '2026-01-01',
            'startdatum'                   => '2026-01-01',
            'vertrouwelijkheidaanduiding'  => 'openbaar',
            'verantwoordelijkeOrganisatie' => '123456782',
        ];
    }//end conformantPayload()

    /**
     * @return void
     */
    public function testGetResource(): void
    {
        $this->assertSame(expected: 'zaak', actual: $this->translator->getResource());
    }//end testGetResource()

    /**
     * @return void
     */
    public function testTranslateToV16IsStructurallyIdentical(): void
    {
        $payload    = $this->conformantPayload();
        $translated = $this->translator->translateToV16(payload: $payload);

        $this->assertSame(expected: $payload, actual: $translated);
    }//end testTranslateToV16IsStructurallyIdentical()

    /**
     * @return void
     */
    public function testTranslateToV1xIsStructurallyIdentical(): void
    {
        $payload    = $this->conformantPayload();
        $translated = $this->translator->translateToV1x(payload: $payload);

        $this->assertSame(expected: $payload, actual: $translated);
    }//end testTranslateToV1xIsStructurallyIdentical()

    /**
     * @return void
     */
    public function testRoundTripIsLossless(): void
    {
        $payload = $this->conformantPayload();
        $roundTripped = $this->translator->translateToV1x(
            payload: $this->translator->translateToV16(payload: $payload)
        );

        $this->assertSame(expected: $payload, actual: $roundTripped);
    }//end testRoundTripIsLossless()

    /**
     * @return void
     */
    public function testMissingRequiredFieldThrowsLiteralLeak(): void
    {
        $payload = $this->conformantPayload();
        unset($payload['zaaktype']);

        $this->expectException(ZgwLiteralLeakException::class);
        $this->translator->translateToV16(payload: $payload);
    }//end testMissingRequiredFieldThrowsLiteralLeak()

    /**
     * @return void
     */
    public function testOutOfSetEnumValueThrowsLiteralLeak(): void
    {
        $payload = $this->conformantPayload();
        $payload['vertrouwelijkheidaanduiding'] = 'top-secret';

        $this->expectException(ZgwLiteralLeakException::class);
        $this->translator->translateToV16(payload: $payload);
    }//end testOutOfSetEnumValueThrowsLiteralLeak()
}//end class

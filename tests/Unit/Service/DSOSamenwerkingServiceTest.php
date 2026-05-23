<?php

/**
 * Unit tests for DSOSamenwerkingService.
 *
 * @category Test
 * @package  OCA\OpenConnector\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 *
 * @spec openspec/changes/dso-omgevingsloket/tasks.md#task-14
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenConnector\Tests\Unit\Service;

use OCA\OpenConnector\Service\DSOSamenwerkingService;
use OCP\Http\Client\IClientService;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Tests for the DSO-SWF samenwerking service.
 *
 * @spec openspec/changes/dso-omgevingsloket/tasks.md#task-14
 */
class DSOSamenwerkingServiceTest extends TestCase
{

    /**
     * @var DSOSamenwerkingService
     */
    private DSOSamenwerkingService $service;

    /**
     * Set up test fixtures.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        $logger        = $this->createMock(LoggerInterface::class);
        $clientService = $this->createMock(IClientService::class);

        $this->service = new DSOSamenwerkingService(
            logger: $logger,
            clientService: $clientService
        );

    }//end setUp()

    /**
     * Test buildAdviesverzoekPayload includes required fields.
     *
     * @return void
     */
    public function testBuildAdviesverzoekPayloadIncludesRequiredFields(): void
    {
        $zaak = [
            'id'        => 'zaak-001',
            'verzoekId' => 'dso-001',
            'aanvrager' => ['naam' => 'Test BV'],
        ];

        $payload = $this->service->buildAdviesverzoekPayload(
            zaak: $zaak,
            partnerOin: '00000001234567890000',
            termijn: '2024-09-15'
        );

        $this->assertArrayHasKey('zaakId', $payload);
        $this->assertArrayHasKey('partnerOin', $payload);
        $this->assertArrayHasKey('termijn', $payload);

        $this->assertSame('zaak-001', $payload['zaakId']);
        $this->assertSame('00000001234567890000', $payload['partnerOin']);
        $this->assertSame('2024-09-15', $payload['termijn']);

    }//end testBuildAdviesverzoekPayloadIncludesRequiredFields()

    /**
     * Test receiveAdvies validates required fields.
     *
     * @return void
     */
    public function testReceiveAdviesValidatesRequiredFields(): void
    {
        // Payload missing required 'adviesId' field.
        $incompletePayload = [
            'organisatieOin' => '00000001234567890000',
            'advies'         => 'Geen bezwaar',
        ];

        $result = $this->service->receiveAdvies(
            adviesPayload: $incompletePayload,
            zaakId: 'zaak-001'
        );

        $this->assertFalse($result['stored']);
        $this->assertArrayHasKey('error', $result);

    }//end testReceiveAdviesValidatesRequiredFields()

    /**
     * Test receiveAdvies stores a valid advies.
     *
     * @return void
     */
    public function testReceiveAdviesStoresValidAdvies(): void
    {
        $validPayload = [
            'adviesId'       => 'advies-001',
            'organisatieOin' => '00000001234567890000',
            'advies'         => 'Geen bezwaar tegen de aanvraag.',
        ];

        $result = $this->service->receiveAdvies(
            adviesPayload: $validPayload,
            zaakId: 'zaak-001'
        );

        $this->assertTrue($result['stored']);
        $this->assertSame('advies-001', $result['adviesId']);
        $this->assertSame('zaak-001', $result['zaakId']);

    }//end testReceiveAdviesStoresValidAdvies()
}//end class

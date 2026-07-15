<?php

/**
 * Unit tests for ZgwVersionTranslationService.
 *
 * @category Test
 * @package  OCA\OpenConnector\Tests\Unit\Service
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

namespace OCA\OpenConnector\Tests\Unit\Service;

use OCA\OpenConnector\Exception\ZgwLiteralLeakException;
use OCA\OpenConnector\Exception\ZgwUnknownResourceException;
use OCA\OpenConnector\Exception\ZgwUnknownVersionException;
use OCA\OpenConnector\Service\ZgwVersion\BesluitTranslator;
use OCA\OpenConnector\Service\ZgwVersion\InformatieObjectTranslator;
use OCA\OpenConnector\Service\ZgwVersion\ResultaatTranslator;
use OCA\OpenConnector\Service\ZgwVersion\RolTranslator;
use OCA\OpenConnector\Service\ZgwVersion\StatusTranslator;
use OCA\OpenConnector\Service\ZgwVersion\ZaakTranslator;
use OCA\OpenConnector\Service\ZgwVersion\ZaakTypeTranslator;
use OCA\OpenConnector\Service\ZgwVersionNegotiationService;
use OCA\OpenConnector\Service\ZgwVersionTranslationService;
use OCA\OpenRegister\Service\ObjectService as ORObjectService;
use PHPUnit\Framework\TestCase;

/**
 * Tests for resource+version resolution, translator dispatch, passthrough,
 * and translation-log persistence.
 *
 * @spec openspec/changes/zgw-version-translation/specs/zgw-version-translation/spec.md#requirement-persistence-and-observability-zgw_version_translation_log-req-004
 */
class ZgwVersionTranslationServiceTest extends TestCase
{

    /**
     * @var ORObjectService|\PHPUnit\Framework\MockObject\MockObject
     */
    private $objectService;

    /**
     * @var ZgwVersionTranslationService
     */
    private ZgwVersionTranslationService $service;

    /**
     * Set up test fixtures.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->objectService = $this->createMock(ORObjectService::class);

        $this->service = new ZgwVersionTranslationService(
            negotiationService: new ZgwVersionNegotiationService(),
            objectService: $this->objectService,
            zaakTranslator: new ZaakTranslator(),
            zaakTypeTranslator: new ZaakTypeTranslator(),
            informatieObjectTranslator: new InformatieObjectTranslator(),
            besluitTranslator: new BesluitTranslator(),
            rolTranslator: new RolTranslator(),
            statusTranslator: new StatusTranslator(),
            resultaatTranslator: new ResultaatTranslator()
        );
    }//end setUp()

    /**
     * A conformant `1.0` status fixture (the simplest resource — no special-case logic).
     *
     * @return array<string, mixed>
     */
    private function conformantStatusPayload(): array
    {
        return [
            'url'              => 'https://host/api/zgw/zaken/v1/statussen/st',
            'uuid'             => 'st',
            'zaak'             => 'https://host/api/zgw/zaken/v1/zaken/abc',
            'statustype'       => 'https://host/api/zgw/catalogi/v1/statustypen/stt',
            'datumStatusGezet' => '2026-01-01T00:00:00+00:00',
        ];
    }//end conformantStatusPayload()

    /**
     * @return void
     */
    public function testGetSupportedResourcesListsAllSeven(): void
    {
        $resources = $this->service->getSupportedResources();
        sort($resources);

        $this->assertSame(
            expected: [
                'besluit',
                'enkelvoudiginformatieobject',
                'resultaat',
                'rol',
                'status',
                'zaak',
                'zaaktype',
            ],
            actual: $resources
        );
    }//end testGetSupportedResourcesListsAllSeven()

    /**
     * @return void
     */
    public function testSameVersionIsPassthroughAndSkipsTranslator(): void
    {
        $this->objectService->expects($this->once())
            ->method('saveObject')
            ->with(
                $this->anything(),
                $this->anything(),
                $this->callback(
                    static fn (string $schema): bool => $schema === ZgwVersionTranslationService::SCHEMA_LOG
                )
            );

        $payload    = $this->conformantStatusPayload();
        $translated = $this->service->translate(
            resource: 'status',
            fromVersion: '1.0',
            toVersion: '1.0',
            payload: $payload
        );

        $this->assertSame(expected: $payload, actual: $translated);
    }//end testSameVersionIsPassthroughAndSkipsTranslator()

    /**
     * @return void
     */
    public function testSuccessfulTranslationPersistsSuccessLog(): void
    {
        $this->objectService->expects($this->once())->method('saveObject');

        $translated = $this->service->translate(
            resource: 'status',
            fromVersion: '1.0',
            toVersion: '1.6',
            payload: $this->conformantStatusPayload()
        );

        $this->assertSame(expected: $this->conformantStatusPayload(), actual: $translated);
    }//end testSuccessfulTranslationPersistsSuccessLog()

    /**
     * @return void
     */
    public function testUnknownResourceThrowsBeforeAnyPersistence(): void
    {
        $this->objectService->expects($this->never())->method('saveObject');

        $this->expectException(ZgwUnknownResourceException::class);
        $this->service->translate(
            resource: 'besluittype',
            fromVersion: '1.0',
            toVersion: '1.6',
            payload: []
        );
    }//end testUnknownResourceThrowsBeforeAnyPersistence()

    /**
     * @return void
     */
    public function testUnknownVersionThrowsBeforeAnyPersistence(): void
    {
        $this->objectService->expects($this->never())->method('saveObject');

        $this->expectException(ZgwUnknownVersionException::class);
        $this->service->translate(
            resource: 'status',
            fromVersion: '1.0',
            toVersion: '0.9',
            payload: []
        );
    }//end testUnknownVersionThrowsBeforeAnyPersistence()

    /**
     * @return void
     */
    public function testLiteralLeakFailurePersistsFailedLogAndRethrows(): void
    {
        $this->objectService->expects($this->once())->method('saveObject');

        $payload = $this->conformantStatusPayload();
        unset($payload['statustype']);

        $this->expectException(ZgwLiteralLeakException::class);
        $this->service->translate(
            resource: 'status',
            fromVersion: '1.0',
            toVersion: '1.6',
            payload: $payload
        );
    }//end testLiteralLeakFailurePersistsFailedLogAndRethrows()
}//end class

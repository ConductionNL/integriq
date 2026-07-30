<?php
/**
 * Unit tests for `SynchronizationService::resetCursor()` (REQ-019).
 *
 * @category Test
 * @package  OCA\OpenConnector\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenConnector\Tests\Unit\Service;

use OCA\OpenConnector\Service\CallService;
use OCA\OpenConnector\Service\MappingService;
use OCA\OpenConnector\Service\ObjectService;
use OCA\OpenConnector\Service\SynchronizationLogService;
use OCA\OpenConnector\Service\SynchronizationService;
use OCA\OpenConnector\Tests\Helpers\ObjectServiceMockBuilder;
use OCA\OpenRegister\Service\ObjectService as ORObjectService;
use OCP\IAppConfig;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Tests REQ-019: the reset-cursor action clears the stored watermark only.
 *
 * @spec openspec/specs/synchronization-engine/spec.md#requirement-reset-cursor-action-clears-the-stored-watermark-req-019
 */
class SynchronizationServiceResetCursorTest extends TestCase
{

    private const SYNC_ID = 'sync-uuid-reset';

    /**
     * @var SynchronizationService&MockObject
     */
    private $service;

    /**
     * @var ORObjectService&MockObject
     */
    private $orObjectService;

    /**
     * Set up test fixtures.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->orObjectService = ObjectServiceMockBuilder::make($this);
        $logger                = $this->createMock(LoggerInterface::class);
        $callService            = $this->createMock(CallService::class);
        $callService->method('applyConfigDot')->willReturnArgument(0);

        $mappingService = $this->createMock(MappingService::class);
        $container      = $this->createMock(ContainerInterface::class);
        $objectService  = $this->createMock(ObjectService::class);
        $appConfig      = $this->createMock(IAppConfig::class);
        $appConfig->method('hasKey')->willReturn(false);

        $logOrService = ObjectServiceMockBuilder::make($this);
        $userSession  = $this->createMock(\OCP\IUserSession::class);
        $session      = $this->createMock(\OCP\ISession::class);
        $logService   = new SynchronizationLogService($logOrService, $userSession, $session);

        $this->service = $this->getMockBuilder(SynchronizationService::class)
            ->setConstructorArgs(
                [
                    $callService,
                    $mappingService,
                    $container,
                    $this->orObjectService,
                    $objectService,
                    $logger,
                    $logService,
                    $appConfig,
                    $this->createMock(\OCA\OpenConnector\Service\ApprovalService::class),
                ]
            )
            ->onlyMethods([])
            ->getMock();
    }//end setUp()

    /**
     * Scenario: reset-cursor clears the watermark without touching syncMode.
     *
     * @return void
     */
    public function testResetCursorClearsWatermarkWithoutTouchingSyncMode(): void
    {
        $synchronization = ObjectServiceMockBuilder::objectEntity(
            $this,
            [
                'id'              => self::SYNC_ID,
                'uuid'            => self::SYNC_ID,
                'syncMode'        => 'incremental',
                'cursorWatermark' => '2026-07-10T00:00:00Z',
            ],
            self::SYNC_ID
        );

        $captured = null;
        $this->orObjectService->method('saveObject')->willReturnCallback(
            function (array $object, ...$rest) use (&$captured) {
                $captured = $object;
                return ObjectServiceMockBuilder::objectEntity($this, $object, self::SYNC_ID);
            }
        );

        $result = $this->service->resetCursor(synchronization: $synchronization);

        $this->assertNotNull($captured, 'resetCursor() must persist the cleared watermark.');
        $this->assertNull($captured['cursorWatermark']);
        $this->assertSame('incremental', $captured['syncMode']);

        $this->assertNull($result['cursorWatermark']);
        $this->assertSame('incremental', $result['syncMode']);
    }//end testResetCursorClearsWatermarkWithoutTouchingSyncMode()

    /**
     * Scenario: the next run after a reset requests an unfiltered fetch —
     * `{{ cursor }}` renders to an empty string once the watermark is
     * cleared.
     *
     * @return void
     */
    public function testNextRunAfterResetRequestsUnfilteredFetch(): void
    {
        $synchronization = ObjectServiceMockBuilder::objectEntity(
            $this,
            [
                'id'              => self::SYNC_ID,
                'uuid'            => self::SYNC_ID,
                'syncMode'        => 'incremental',
                'cursorWatermark' => '2026-07-10T00:00:00Z',
            ],
            self::SYNC_ID
        );

        $this->orObjectService->method('saveObject')->willReturnCallback(
            fn (array $object, ...$rest) => ObjectServiceMockBuilder::objectEntity($this, $object, self::SYNC_ID)
        );

        $cleared = $this->service->resetCursor(synchronization: $synchronization);

        $this->assertNull($cleared['cursorWatermark']);
        // (Full endpoint-templating behavior after a reset is covered by
        // SynchronizationServiceIncrementalCursorTest::
        // testIncrementalRunWithNoWatermarkPassesEmptyCursor — an absent
        // cursorWatermark renders `{{ cursor }}` to an empty string.)
    }//end testNextRunAfterResetRequestsUnfilteredFetch()
}//end class

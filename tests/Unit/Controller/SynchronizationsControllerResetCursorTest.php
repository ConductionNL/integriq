<?php

/**
 * Unit tests for SynchronizationsController::resetCursor() (REQ-019).
 *
 * @category Test
 * @package  OCA\OpenConnector\Tests\Unit\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 *
 * @spec openspec/specs/synchronization-engine/spec.md#requirement-reset-cursor-action-clears-the-stored-watermark-req-019
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenConnector\Tests\Unit\Controller;

use OCA\OpenConnector\Controller\SynchronizationsController;
use OCA\OpenConnector\Service\ActionAuthService;
use OCA\OpenConnector\Service\SynchronizationService;
use OCA\OpenConnector\Tests\Helpers\ObjectServiceMockBuilder;
use OCA\OpenRegister\Service\ObjectService as OrObjectService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;

/**
 * Tests for the `reset-cursor` REST surface.
 *
 * @spec openspec/specs/synchronization-engine/spec.md#requirement-reset-cursor-action-clears-the-stored-watermark-req-019
 */
class SynchronizationsControllerResetCursorTest extends TestCase
{

    /**
     * @var OrObjectService|\PHPUnit\Framework\MockObject\MockObject
     */
    private $orObjectService;

    /**
     * @var SynchronizationService|\PHPUnit\Framework\MockObject\MockObject
     */
    private $synchronizationService;

    /**
     * @var SynchronizationsController
     */
    private SynchronizationsController $controller;


    /**
     * Set up test fixtures.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->orObjectService        = $this->createMock(OrObjectService::class);
        $this->synchronizationService = $this->createMock(SynchronizationService::class);

        $l = $this->createMock(IL10N::class);
        $l->method('t')->willReturnArgument(0);

        $user = $this->createMock(IUser::class);
        $user->method('getUID')->willReturn('alice');
        $userSession = $this->createMock(IUserSession::class);
        $userSession->method('getUser')->willReturn($user);

        $actionAuth = $this->createMock(ActionAuthService::class);

        $this->controller = new SynchronizationsController(
            'openconnector',
            $this->createMock(IRequest::class),
            $this->orObjectService,
            $this->synchronizationService,
            $l,
            $this->createMock(\Psr\Log\LoggerInterface::class),
            $userSession,
            $actionAuth
        );
    }//end setUp()


    /**
     * REQ-019 — a successful reset delegates to
     * `SynchronizationService::resetCursor()` and reflects the cleared
     * watermark in the response.
     *
     * @return void
     */
    public function testResetCursorClearsWatermarkAndReturnsUpdatedPayload(): void
    {
        $synchronization = ObjectServiceMockBuilder::objectEntity(
            $this,
            ['syncMode' => 'incremental', 'cursorWatermark' => '2026-07-10T00:00:00Z'],
            'sync-1'
        );
        $this->orObjectService->method('find')->willReturn($synchronization);

        $this->synchronizationService->expects($this->once())
            ->method('resetCursor')
            ->with($synchronization)
            ->willReturn(['uuid' => 'sync-1', 'syncMode' => 'incremental', 'cursorWatermark' => null]);

        $response = $this->controller->resetCursor('sync-1');
        $data     = $response->getData();

        $this->assertSame(200, $response->getStatus());
        $this->assertNull($data['cursorWatermark']);
        $this->assertSame('incremental', $data['syncMode']);
    }//end testResetCursorClearsWatermarkAndReturnsUpdatedPayload()


    /**
     * REQ-019 — a missing synchronization id returns 404, without ever
     * calling into SynchronizationService::resetCursor().
     *
     * @return void
     */
    public function testResetCursorReturns404OnUnknownSynchronization(): void
    {
        $this->orObjectService->method('find')->willThrowException(new DoesNotExistException('not found'));
        $this->synchronizationService->expects($this->never())->method('resetCursor');

        $response = $this->controller->resetCursor('missing');

        $this->assertSame(404, $response->getStatus());
    }//end testResetCursorReturns404OnUnknownSynchronization()
}//end class

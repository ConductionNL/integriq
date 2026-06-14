<?php

/**
 * Unit tests for EventsController dead-letter endpoints.
 *
 * @category Test
 * @package  OCA\OpenConnector\Tests\Unit\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 *
 * @spec openspec/changes/openconnector-dead-letter-replay/tasks.md#task-5
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenConnector\Tests\Unit\Controller;

use OCA\OpenConnector\Controller\EventsController;
use OCA\OpenConnector\Exception\InvalidMessageStateException;
use OCA\OpenConnector\Service\ActionAuthService;
use OCA\OpenConnector\Service\EventService;
use OCA\OpenConnector\Tests\Helpers\ObjectServiceMockBuilder;
use OCA\OpenRegister\Service\ObjectService as OrObjectService;
use OCP\AppFramework\Http;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;

/**
 * Tests for the dead-letter REST surface.
 *
 * @spec openspec/changes/openconnector-dead-letter-replay/tasks.md#task-5
 */
class EventsControllerTest extends TestCase
{

    /**
     * @var IRequest|\PHPUnit\Framework\MockObject\MockObject
     */
    private $request;

    /**
     * @var OrObjectService|\PHPUnit\Framework\MockObject\MockObject
     */
    private $orObjectService;

    /**
     * @var EventService|\PHPUnit\Framework\MockObject\MockObject
     */
    private $eventService;

    /**
     * @var IUserSession|\PHPUnit\Framework\MockObject\MockObject
     */
    private $userSession;

    /**
     * @var EventsController
     */
    private EventsController $controller;


    /**
     * Set up test fixtures.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->request         = $this->createMock(IRequest::class);
        $this->orObjectService = $this->createMock(OrObjectService::class);
        $this->eventService    = $this->createMock(EventService::class);
        $this->userSession     = $this->createMock(IUserSession::class);

        $l = $this->createMock(IL10N::class);
        $l->method('t')->willReturnArgument(0);

        $user = $this->createMock(IUser::class);
        $user->method('getUID')->willReturn('alice');
        $this->userSession->method('getUser')->willReturn($user);

        $actionAuth = $this->createMock(ActionAuthService::class);

        $this->controller = new EventsController(
            'openconnector',
            $this->request,
            $this->orObjectService,
            $this->eventService,
            $l,
            $this->userSession,
            $actionAuth
        );
    }//end setUp()


    /**
     * REQ-DLR-001: the default listing returns only failed and abandoned rows.
     *
     * @return void
     */
    public function testDeadLetterIndexDefaultsToFailedAndAbandoned(): void
    {
        $rows = [
            ObjectServiceMockBuilder::objectEntity($this, ['status' => 'failed'], 'm1'),
            ObjectServiceMockBuilder::objectEntity($this, ['status' => 'abandoned'], 'm2'),
            ObjectServiceMockBuilder::objectEntity($this, ['status' => 'discarded'], 'm3'),
        ];
        $this->orObjectService->method('findAll')->willReturn(['results' => $rows, 'total' => 3]);
        $this->request->method('getParam')->willReturnCallback(
            static fn($key, $default=null) => $default
        );

        $response = $this->controller->deadLetterIndex();
        $data     = $response->getData();

        $this->assertSame(2, $data['total']);
        $statuses = array_column($data['results'], 'status');
        $this->assertContains('failed', $statuses);
        $this->assertContains('abandoned', $statuses);
        $this->assertNotContains('discarded', $statuses);
    }//end testDeadLetterIndexDefaultsToFailedAndAbandoned()


    /**
     * REQ-DLR-003: replay maps an invalid-state to HTTP 409.
     *
     * @return void
     */
    public function testReplayReturns409OnInvalidState(): void
    {
        $this->eventService->method('replayMessage')
            ->willThrowException(new InvalidMessageStateException('bad state'));

        $response = $this->controller->replay('m1');

        $this->assertSame(Http::STATUS_CONFLICT, $response->getStatus());
    }//end testReplayReturns409OnInvalidState()


    /**
     * REQ-DLR-005: bulk replay rejects more than 100 ids.
     *
     * @return void
     */
    public function testBulkReplayRejectsOverCap(): void
    {
        $ids = array_map(static fn(int $i) => 'id-'.$i, range(1, 101));
        $this->request->method('getParam')->willReturnCallback(
            static fn($key, $default=null) => $key === 'ids' ? $ids : $default
        );

        $response = $this->controller->bulkReplay();

        $this->assertSame(400, $response->getStatus());
    }//end testBulkReplayRejectsOverCap()


    /**
     * REQ-DLR-005: bulk replay reports mixed per-id outcomes; a partial failure
     * never aborts the batch.
     *
     * @return void
     */
    public function testBulkReplayReportsMixedOutcomes(): void
    {
        $this->request->method('getParam')->willReturnCallback(
            static fn($key, $default=null) => $key === 'ids' ? ['A', 'B', 'C'] : $default
        );

        $this->eventService->method('replayMessage')->willReturnCallback(
            function (string $id) {
                if ($id === 'A') {
                    return ObjectServiceMockBuilder::objectEntity($this, ['status' => 'pending'], 'A');
                }

                if ($id === 'B') {
                    throw new InvalidMessageStateException('delivered');
                }

                throw new \OCP\AppFramework\Db\DoesNotExistException('missing');
            }
        );

        $response = $this->controller->bulkReplay();
        $results  = $response->getData()['results'];

        $this->assertSame('ok', $results['A']);
        $this->assertSame('invalid-state', $results['B']);
        $this->assertSame('not-found', $results['C']);
    }//end testBulkReplayReportsMixedOutcomes()
}//end class

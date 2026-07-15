<?php

/**
 * Unit tests for SyncDeadLetterController.
 *
 * @category Test
 * @package  OCA\OpenConnector\Tests\Unit\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 *
 * @spec openspec/specs/dead-letter-replay/spec.md#requirement-bulk-replay-and-discard-for-sync-item-dead-letters-req-dlr-011
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenConnector\Tests\Unit\Controller;

use OCA\OpenConnector\Controller\SyncDeadLetterController;
use OCA\OpenConnector\Exception\InvalidMessageStateException;
use OCA\OpenConnector\Service\SyncItemDeadLetterService;
use OCA\OpenConnector\Tests\Helpers\ObjectServiceMockBuilder;
use OCA\OpenRegister\Service\ObjectService as OrObjectService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;

/**
 * Tests for the sync-item dead-letter REST surface.
 *
 * @spec openspec/specs/dead-letter-replay/spec.md#requirement-sync-item-dead-letter-listing-with-filters-and-pagination-req-dlr-007
 */
class SyncDeadLetterControllerTest extends TestCase
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
     * @var SyncItemDeadLetterService|\PHPUnit\Framework\MockObject\MockObject
     */
    private $syncItemDeadLetterService;

    /**
     * @var SyncDeadLetterController
     */
    private SyncDeadLetterController $controller;


    /**
     * Set up test fixtures.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->request                  = $this->createMock(IRequest::class);
        $this->orObjectService          = $this->createMock(OrObjectService::class);
        $this->syncItemDeadLetterService = $this->createMock(SyncItemDeadLetterService::class);

        $l = $this->createMock(IL10N::class);
        $l->method('t')->willReturnArgument(0);

        $user = $this->createMock(IUser::class);
        $user->method('getUID')->willReturn('alice');
        $userSession = $this->createMock(IUserSession::class);
        $userSession->method('getUser')->willReturn($user);

        $this->controller = new SyncDeadLetterController(
            'openconnector',
            $this->request,
            $this->orObjectService,
            $this->syncItemDeadLetterService,
            $l,
            $userSession
        );
    }//end setUp()


    /**
     * REQ-DLR-007 — the default listing returns only `failed` rows.
     *
     * @return void
     */
    public function testIndexDefaultsToFailedOnly(): void
    {
        $entries = [
            ObjectServiceMockBuilder::objectEntity($this, ['status' => 'failed'], 'e1'),
            ObjectServiceMockBuilder::objectEntity($this, ['status' => 'replayed'], 'e2'),
            ObjectServiceMockBuilder::objectEntity($this, ['status' => 'discarded'], 'e3'),
        ];

        $this->request->method('getParam')->willReturnCallback(
            static function (string $key, $default=null) {
                return $default;
            }
        );
        $this->orObjectService->method('findAll')->willReturn(['results' => $entries, 'total' => 3]);

        $response = $this->controller->index();
        $data     = $response->getData();

        $this->assertCount(1, $data['results']);
        $this->assertSame('failed', $data['results'][0]['status']);
    }//end testIndexDefaultsToFailedOnly()


    /**
     * REQ-DLR-009 — replay maps an invalid-state exception to HTTP 409.
     *
     * @return void
     */
    public function testReplayReturns409OnInvalidState(): void
    {
        $this->syncItemDeadLetterService->method('replayMessage')
            ->willThrowException(new InvalidMessageStateException('Cannot replay a sync item dead letter in state "replayed"'));

        $response = $this->controller->replay('dl-1');

        $this->assertSame(409, $response->getStatus());
    }//end testReplayReturns409OnInvalidState()


    /**
     * REQ-DLR-009 — replay maps an unknown id to HTTP 404.
     *
     * @return void
     */
    public function testReplayReturns404OnUnknownEntry(): void
    {
        $this->syncItemDeadLetterService->method('replayMessage')
            ->willThrowException(new DoesNotExistException('not found'));

        $response = $this->controller->replay('missing');

        $this->assertSame(404, $response->getStatus());
    }//end testReplayReturns404OnUnknownEntry()


    /**
     * REQ-DLR-011 — bulk replay rejects more than 100 ids with 400.
     *
     * @return void
     */
    public function testBulkReplayRejectsOverCap(): void
    {
        $ids = array_map(static fn($i) => 'id-'.$i, range(1, 101));
        $this->request->method('getParam')->with('ids')->willReturn($ids);

        $response = $this->controller->bulkReplay();

        $this->assertSame(400, $response->getStatus());
    }//end testBulkReplayRejectsOverCap()


    /**
     * REQ-DLR-011 — bulk replay reports mixed per-id outcomes; a partial
     * failure never aborts the batch.
     *
     * @return void
     */
    public function testBulkReplayReportsMixedOutcomes(): void
    {
        $this->request->method('getParam')->with('ids')->willReturn(['A', 'B', 'C']);

        $this->syncItemDeadLetterService->method('replayMessage')->willReturnCallback(
            function (string $id, string $actorUid) {
                if ($id === 'A') {
                    return ObjectServiceMockBuilder::objectEntity($this, ['status' => 'replayed'], 'A');
                }

                if ($id === 'B') {
                    throw new InvalidMessageStateException('already discarded');
                }

                throw new DoesNotExistException('not found');
            }
        );

        $response = $this->controller->bulkReplay();
        $results  = $response->getData()['results'];

        $this->assertSame('ok', $results['A']);
        $this->assertSame('invalid-state', $results['B']);
        $this->assertSame('not-found', $results['C']);
    }//end testBulkReplayReportsMixedOutcomes()

}//end class

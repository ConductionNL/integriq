<?php

/**
 * Unit tests for ExecutionTracesController::show()'s not-found path.
 *
 * @category Test
 * @package  OCA\OpenConnector\Tests\Unit\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/specs/execution-trace/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenConnector\Tests\Unit\Controller;

use OCA\OpenConnector\Controller\ExecutionTracesController;
use OCA\OpenConnector\Service\ExecutionTraceService;
use OCA\OpenRegister\Db\ObjectEntity;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * `ExecutionTraceService::find()` used to catch \Throwable and return null, so
 * an OpenRegister outage was indistinguishable from a trace that does not
 * exist and the UI showed a confident 404. It now returns null only for a
 * miss and propagates everything else.
 *
 * These tests hold both halves of that: a miss is still a 404, and a genuine
 * failure is no longer disguised as one.
 */
class ExecutionTracesControllerShowTest extends TestCase
{

    /**
     * @var IRequest|\PHPUnit\Framework\MockObject\MockObject
     */
    private $request;

    /**
     * @var ExecutionTraceService|\PHPUnit\Framework\MockObject\MockObject
     */
    private $traceService;

    /**
     * @var IL10N|\PHPUnit\Framework\MockObject\MockObject
     */
    private $l;

    /**
     * @var IUserSession|\PHPUnit\Framework\MockObject\MockObject
     */
    private $userSession;

    /**
     * Build the collaborators, with an authenticated user by default.
     *
     * @return void
     */
    protected function setUp(): void
    {
        $this->request      = $this->createMock(IRequest::class);
        $this->traceService = $this->createMock(ExecutionTraceService::class);
        $this->l            = $this->createMock(IL10N::class);
        $this->userSession  = $this->createMock(IUserSession::class);

        $this->l->method('t')->willReturnArgument(0);
        $this->userSession->method('getUser')->willReturn($this->createMock(IUser::class));

    }//end setUp()

    /**
     * Construct the controller under test.
     *
     * @return ExecutionTracesController
     */
    private function controller(): ExecutionTracesController
    {
        return new ExecutionTracesController(
            'openconnector',
            $this->request,
            $this->traceService,
            $this->l,
            $this->userSession
        );

    }//end controller()

    /**
     * A trace that exists is returned.
     *
     * @return void
     */
    public function testShowReturnsAnExistingTrace(): void
    {
        $trace = $this->createMock(ObjectEntity::class);
        $trace->method('getObject')->willReturn(['id' => 'trace-1', 'status' => 'completed']);
        $this->traceService->method('find')->willReturn($trace);

        $response = $this->controller()->show('trace-1');

        $this->assertSame(Http::STATUS_OK, $response->getStatus());
        $this->assertSame(['id' => 'trace-1', 'status' => 'completed'], $response->getData());

    }//end testShowReturnsAnExistingTrace()

    /**
     * A miss is a 404, whether it arrives as null or as the exception.
     *
     * @return void
     */
    public function testANullMissIsA404(): void
    {
        $this->traceService->method('find')->willReturn(null);

        $this->assertSame(Http::STATUS_NOT_FOUND, $this->controller()->show('nope')->getStatus());

    }//end testANullMissIsA404()

    /**
     * A thrown miss is a 404 too.
     *
     * @return void
     */
    public function testAThrownMissIsA404(): void
    {
        $this->traceService->method('find')
            ->willThrowException(new DoesNotExistException('no such trace'));

        $this->assertSame(Http::STATUS_NOT_FOUND, $this->controller()->show('nope')->getStatus());

    }//end testAThrownMissIsA404()

    /**
     * A genuine backend failure is NOT disguised as a 404.
     *
     * This is the half that regressed for as long as the service caught
     * \Throwable: an OpenRegister outage told the operator their trace did not
     * exist. It must now surface, so the caller sees a failure rather than a
     * confident wrong answer.
     *
     * @return void
     */
    public function testABackendFailureIsNotDisguisedAsANotFound(): void
    {
        $this->traceService->method('find')
            ->willThrowException(new RuntimeException('OpenRegister is unavailable'));

        $this->expectException(RuntimeException::class);

        $this->controller()->show('trace-1');

    }//end testABackendFailureIsNotDisguisedAsANotFound()

    /**
     * An unauthenticated caller is refused before any lookup happens.
     *
     * @return void
     */
    public function testAnUnauthenticatedCallerIsRefusedBeforeAnyLookup(): void
    {
        $userSession = $this->createMock(IUserSession::class);
        $userSession->method('getUser')->willReturn(null);
        $this->userSession = $userSession;

        $this->traceService->expects($this->never())->method('find');

        $this->assertSame(
            Http::STATUS_UNAUTHORIZED,
            $this->controller()->show('trace-1')->getStatus()
        );

    }//end testAnUnauthenticatedCallerIsRefusedBeforeAnyLookup()
}//end class

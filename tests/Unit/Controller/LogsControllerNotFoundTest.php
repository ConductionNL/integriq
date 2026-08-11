<?php

/**
 * Unit tests for LogsController's not-found paths.
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
 * @spec openspec/specs/logs-and-statistics/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenConnector\Tests\Unit\Controller;

use OCA\OpenConnector\Controller\LogsController;
use OCA\OpenConnector\Service\ActionAuthService;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService as OrObjectService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http;
use OCP\IAppConfig;
use OCP\IGroupManager;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;

/**
 * OpenRegister's `find()` does not only return null for a miss — it throws
 * DoesNotExistException. `show()` and `destroy()` each had a null branch
 * written to produce a 404 and no catch, so an unknown id took the throw
 * instead and the caller got a 500 from a path that was meant to answer 404.
 *
 * Both shapes are asserted here, each paired with the happy path so the tests
 * cannot pass by the controller answering 404 to everything.
 */
class LogsControllerNotFoundTest extends TestCase
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
     * @var IL10N|\PHPUnit\Framework\MockObject\MockObject
     */
    private $l;

    /**
     * @var IUserSession|\PHPUnit\Framework\MockObject\MockObject
     */
    private $userSession;

    /**
     * Whether the session user passes ActionAuthService's admin break-glass.
     *
     * @var boolean
     */
    private bool $isAdmin = true;

    /**
     * Build the collaborators, with an authenticated user by default.
     *
     * @return void
     */
    protected function setUp(): void
    {
        $this->request         = $this->createMock(IRequest::class);
        $this->orObjectService = $this->createMock(OrObjectService::class);
        $this->l               = $this->createMock(IL10N::class);
        $this->userSession     = $this->createMock(IUserSession::class);
        $this->isAdmin         = true;

        $this->l->method('t')->willReturnArgument(0);

        $user = $this->createMock(IUser::class);
        $user->method('getUID')->willReturn('tester');
        $this->userSession->method('getUser')->willReturn($user);

    }//end setUp()

    /**
     * Construct the controller under test.
     *
     * The action-authorization collaborator is the REAL ActionAuthService over
     * a mocked IAppConfig and IGroupManager — not a double of the service
     * itself. A double shaped to what the controller calls would be green
     * whatever the service actually decides; this way the seeded default
     * (`{}` → every action admin-only) is the thing under test.
     *
     * @return LogsController
     */
    private function controller(): LogsController
    {
        $appConfig = $this->createMock(IAppConfig::class);
        $appConfig->method('getValueString')->willReturn('{}');

        $groupManager = $this->createMock(IGroupManager::class);
        $groupManager->method('isAdmin')->willReturn($this->isAdmin);
        $groupManager->method('getUserGroupIds')->willReturn([]);

        return new LogsController(
            'openconnector',
            $this->request,
            $this->orObjectService,
            $this->l,
            $this->userSession,
            new ActionAuthService($appConfig, $groupManager)
        );

    }//end controller()

    /**
     * A log that OpenRegister answers for is returned.
     *
     * @return void
     */
    public function testShowReturnsAnExistingLog(): void
    {
        $log = $this->createMock(ObjectEntity::class);
        $log->method('getObject')->willReturn(['id' => 'log-1', 'level' => 'INFO']);
        $this->orObjectService->method('find')->willReturn($log);

        $response = $this->controller()->show('log-1');

        $this->assertSame(Http::STATUS_OK, $response->getStatus());
        $this->assertSame(['id' => 'log-1', 'level' => 'INFO'], $response->getData());

    }//end testShowReturnsAnExistingLog()

    /**
     * An id OpenRegister THROWS on is a 404, not a 500.
     *
     * @return void
     */
    public function testShowTranslatesAThrownMissIntoA404(): void
    {
        $this->orObjectService->method('find')
            ->willThrowException(new DoesNotExistException('no such object'));

        $response = $this->controller()->show('no-such-log');

        $this->assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());

    }//end testShowTranslatesAThrownMissIntoA404()

    /**
     * The null return is still a 404 — the catch did not replace it.
     *
     * @return void
     */
    public function testShowStillTranslatesANullMissIntoA404(): void
    {
        $this->orObjectService->method('find')->willReturn(null);

        $response = $this->controller()->show('no-such-log');

        $this->assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());

    }//end testShowStillTranslatesANullMissIntoA404()

    /**
     * A log that exists is deleted.
     *
     * @return void
     */
    public function testDestroyDeletesAnExistingLog(): void
    {
        $log = $this->createMock(ObjectEntity::class);
        $log->method('getUuid')->willReturn('uuid-1');
        $this->orObjectService->method('find')->willReturn($log);

        $this->orObjectService->expects($this->once())
            ->method('deleteObject')
            ->with('uuid-1');

        $response = $this->controller()->destroy('log-1');

        $this->assertSame(Http::STATUS_OK, $response->getStatus());

    }//end testDestroyDeletesAnExistingLog()

    /**
     * An id the read throws on is a 404, and nothing is deleted.
     *
     * @return void
     */
    public function testDestroyTranslatesAThrownMissIntoA404(): void
    {
        $this->orObjectService->method('find')
            ->willThrowException(new DoesNotExistException('no such object'));

        $this->orObjectService->expects($this->never())->method('deleteObject');

        $response = $this->controller()->destroy('no-such-log');

        $this->assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());

    }//end testDestroyTranslatesAThrownMissIntoA404()

    /**
     * Losing a race with a concurrent delete is a 404 too: the read found the
     * log, the write did not, and either way it is gone.
     *
     * @return void
     */
    public function testDestroyTranslatesALostDeleteRaceIntoA404(): void
    {
        $log = $this->createMock(ObjectEntity::class);
        $log->method('getUuid')->willReturn('uuid-1');
        $this->orObjectService->method('find')->willReturn($log);
        $this->orObjectService->method('deleteObject')
            ->willThrowException(new DoesNotExistException('already gone'));

        $response = $this->controller()->destroy('log-1');

        $this->assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());

    }//end testDestroyTranslatesALostDeleteRaceIntoA404()

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

        $this->orObjectService->expects($this->never())->method('find');

        $response = $this->controller()->show('log-1');

        $this->assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());

    }//end testAnUnauthenticatedCallerIsRefusedBeforeAnyLookup()
}//end class

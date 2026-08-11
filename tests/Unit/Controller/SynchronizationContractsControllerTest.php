<?php

/**
 * Unit tests for SynchronizationContractsController's activate / deactivate
 * endpoints.
 *
 * @category Test
 * @package  OCA\OpenConnector\Tests\Unit\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenConnector\Tests\Unit\Controller;

use OCA\OpenConnector\Controller\SynchronizationContractsController;
use OCA\OpenConnector\Service\ActionAuthService;
use OCA\OpenConnector\Tests\Helpers\ObjectServiceMockBuilder;
use OCA\OpenRegister\Service\ObjectService as OrObjectService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http;
use OCP\AppFramework\OCS\OCSForbiddenException;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Wire-contract tests for POST /api/synchronization-contracts/{id}/activate and
 * /deactivate.
 *
 * Both are `#[NoAdminRequired] #[NoCSRFRequired]` POST endpoints, so the only
 * thing between an arbitrary authenticated caller and an arbitrary contract id
 * is the ADR-023 action check. That, the 401 for anonymous callers and the 404
 * for an unknown id are this controller's real observable contract, and each is
 * asserted below.
 */
class SynchronizationContractsControllerTest extends TestCase
{

    /**
     * @var OrObjectService|MockObject
     */
    private $orObjectService;

    /**
     * @var ActionAuthService|MockObject
     */
    private $actionAuth;


    /**
     * Build the controller under test.
     *
     * @param IUser|null $user The authenticated user (null = anonymous).
     *
     * @return SynchronizationContractsController
     */
    private function buildController(?IUser $user): SynchronizationContractsController
    {
        $this->orObjectService = $this->getMockBuilder(OrObjectService::class)
            ->disableOriginalConstructor()
            ->getMock();

        $this->actionAuth = $this->createMock(ActionAuthService::class);

        $userSession = $this->createMock(IUserSession::class);
        $userSession->method('getUser')->willReturn($user);

        $l = $this->createMock(IL10N::class);
        $l->method('t')->willReturnArgument(0);

        return new SynchronizationContractsController(
            'openconnector',
            $this->createMock(IRequest::class),
            $this->orObjectService,
            $l,
            $userSession,
            $this->actionAuth
        );

    }//end buildController()


    /**
     * A test user.
     *
     * @return IUser|MockObject
     */
    private function user()
    {
        $user = $this->createMock(IUser::class);
        $user->method('getUID')->willReturn('alice');

        return $user;

    }//end user()


    /**
     * An anonymous caller gets 401 and the contract is never looked up.
     *
     * @return void
     */
    public function testActivateReturns401WhenNotAuthenticated(): void
    {
        $controller = $this->buildController(null);
        $this->orObjectService->expects($this->never())->method('find');

        $response = $controller->activate('contract-1');

        $this->assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());

    }//end testActivateReturns401WhenNotAuthenticated()


    /**
     * An anonymous caller gets 401 and the contract is never looked up.
     *
     * @return void
     */
    public function testDeactivateReturns401WhenNotAuthenticated(): void
    {
        $controller = $this->buildController(null);
        $this->orObjectService->expects($this->never())->method('find');

        $response = $controller->deactivate('contract-1');

        $this->assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());

    }//end testDeactivateReturns401WhenNotAuthenticated()


    /**
     * The ADR-023 action is demanded by its exact name.
     *
     * The name matters as much as the call: a misspelled action resolves to no
     * matrix entry, and `requireAction()` then decides on a rule nobody wrote.
     *
     * @return void
     */
    public function testActivateDemandsTheActivateAction(): void
    {
        $controller = $this->buildController($this->user());
        $this->orObjectService->method('find')
            ->willReturn(ObjectServiceMockBuilder::objectEntity($this, ['status' => 'inactive'], 'contract-1'));

        $this->actionAuth->expects($this->once())
            ->method('requireAction')
            ->with($this->anything(), 'synchronization-contract.activate');

        $controller->activate('contract-1');

    }//end testActivateDemandsTheActivateAction()


    /**
     * The ADR-023 action is demanded by its exact name.
     *
     * @return void
     */
    public function testDeactivateDemandsTheDeactivateAction(): void
    {
        $controller = $this->buildController($this->user());
        $this->orObjectService->method('find')
            ->willReturn(ObjectServiceMockBuilder::objectEntity($this, ['status' => 'active'], 'contract-1'));

        $this->actionAuth->expects($this->once())
            ->method('requireAction')
            ->with($this->anything(), 'synchronization-contract.deactivate');

        $controller->deactivate('contract-1');

    }//end testDeactivateDemandsTheDeactivateAction()


    /**
     * A denied action aborts before the contract is read.
     *
     * `requireAction()` throws; the endpoint must not swallow it into a 200.
     *
     * @return void
     */
    public function testActivatePropagatesADeniedAction(): void
    {
        $controller = $this->buildController($this->user());
        $this->actionAuth->method('requireAction')
            ->willThrowException(new OCSForbiddenException('denied'));
        $this->orObjectService->expects($this->never())->method('find');

        $this->expectException(OCSForbiddenException::class);

        $controller->activate('contract-1');

    }//end testActivatePropagatesADeniedAction()


    /**
     * A denied action aborts before the contract is read.
     *
     * @return void
     */
    public function testDeactivatePropagatesADeniedAction(): void
    {
        $controller = $this->buildController($this->user());
        $this->actionAuth->method('requireAction')
            ->willThrowException(new OCSForbiddenException('denied'));
        $this->orObjectService->expects($this->never())->method('find');

        $this->expectException(OCSForbiddenException::class);

        $controller->deactivate('contract-1');

    }//end testDeactivatePropagatesADeniedAction()


    /**
     * An unknown contract id is a 404, not a success envelope.
     *
     * @return void
     */
    public function testActivateReturns404ForAnUnknownContract(): void
    {
        $controller = $this->buildController($this->user());
        $this->orObjectService->method('find')
            ->willThrowException(new DoesNotExistException('missing'));

        $response = $controller->activate('nope');

        $this->assertSame(404, $response->getStatus());
        $this->assertArrayHasKey('error', $response->getData());

    }//end testActivateReturns404ForAnUnknownContract()


    /**
     * An unknown contract id is a 404, not a success envelope.
     *
     * @return void
     */
    public function testDeactivateReturns404ForAnUnknownContract(): void
    {
        $controller = $this->buildController($this->user());
        $this->orObjectService->method('find')
            ->willThrowException(new DoesNotExistException('missing'));

        $response = $controller->deactivate('nope');

        $this->assertSame(404, $response->getStatus());
        $this->assertArrayHasKey('error', $response->getData());

    }//end testDeactivateReturns404ForAnUnknownContract()


    /**
     * The contract is read from the openconnector register's
     * `synchronization_contract` schema, by the id in the URL.
     *
     * @return void
     */
    public function testActivateLooksTheContractUpBySchemaAndId(): void
    {
        $controller = $this->buildController($this->user());

        $captured = [];
        $this->orObjectService->method('find')->willReturnCallback(
            function (...$args) use (&$captured) {
                $captured = $args;
                return ObjectServiceMockBuilder::objectEntity($this, ['status' => 'inactive'], 'contract-1');
            }
        );

        $controller->activate('contract-1');

        $this->assertContains('contract-1', $captured);
        $this->assertContains('openconnector', $captured);
        $this->assertContains('synchronization_contract', $captured);

    }//end testActivateLooksTheContractUpBySchemaAndId()


    /**
     * CHARACTERIZATION TEST — activate() / deactivate() DO NOT PERSIST ANYTHING.
     *
     * Both handlers read the contract and then return
     * "Contract activated successfully" without writing a single field; the
     * source carries the comment "For now, we'll just return success". This
     * test records that, so the gap is visible in the suite rather than implied
     * by a green "returns 200" assertion.
     *
     * IF YOU ARE IMPLEMENTING THESE ENDPOINTS and this test fails: that is the
     * intended outcome. Delete this test and replace it with one asserting the
     * state transition you added.
     *
     * @return void
     */
    public function testActivateAndDeactivateCurrentlyPersistNothing(): void
    {
        $controller = $this->buildController($this->user());
        $this->orObjectService->method('find')
            ->willReturn(ObjectServiceMockBuilder::objectEntity($this, ['status' => 'inactive'], 'contract-1'));
        $this->orObjectService->expects($this->never())->method('saveObject');

        $activated = $controller->activate('contract-1');
        $this->assertSame(Http::STATUS_OK, $activated->getStatus());
        $this->assertArrayHasKey('message', $activated->getData());

        $controller = $this->buildController($this->user());
        $this->orObjectService->method('find')
            ->willReturn(ObjectServiceMockBuilder::objectEntity($this, ['status' => 'active'], 'contract-1'));
        $this->orObjectService->expects($this->never())->method('saveObject');

        $deactivated = $controller->deactivate('contract-1');
        $this->assertSame(Http::STATUS_OK, $deactivated->getStatus());
        $this->assertArrayHasKey('message', $deactivated->getData());

    }//end testActivateAndDeactivateCurrentlyPersistNothing()

}//end class

<?php

/**
 * Unit tests for FscController.
 *
 * @category Test
 * @package  OCA\OpenConnector\Tests\Unit\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 *
 * @spec openspec/changes/fsc-connectivity/tasks.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenConnector\Tests\Unit\Controller;

use OCA\OpenConnector\Controller\FscController;
use OCA\OpenConnector\Exception\FscConnectivityException;
use OCA\OpenConnector\Exception\FscDirectoryException;
use OCA\OpenConnector\Service\ActionAuthService;
use OCA\OpenConnector\Service\FscCallService;
use OCP\AppFramework\Http;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Tests for the FSC list (GET /api/fsc/services) and call (POST /api/fsc/call) endpoints.
 *
 * @spec openspec/changes/fsc-connectivity/specs/fsc-connectivity/spec.md#requirement-rest-surface-for-sibling-apps-req-005
 */
class FscControllerTest extends TestCase
{

    /**
     * @var IRequest|\PHPUnit\Framework\MockObject\MockObject
     */
    private $request;

    /**
     * @var FscCallService|\PHPUnit\Framework\MockObject\MockObject
     */
    private $callService;

    /**
     * @var IUserSession|\PHPUnit\Framework\MockObject\MockObject
     */
    private $userSession;

    /**
     * @var ActionAuthService|\PHPUnit\Framework\MockObject\MockObject
     */
    private $actionAuth;

    /**
     * @var IL10N|\PHPUnit\Framework\MockObject\MockObject
     */
    private $l;

    /**
     * @var LoggerInterface|\PHPUnit\Framework\MockObject\MockObject
     */
    private $logger;

    /**
     * @var FscController
     */
    private FscController $controller;

    /**
     * Set up test fixtures.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->request     = $this->createMock(IRequest::class);
        $this->callService = $this->createMock(FscCallService::class);
        $this->userSession = $this->createMock(IUserSession::class);
        $this->actionAuth  = $this->createMock(ActionAuthService::class);
        $this->l           = $this->createMock(IL10N::class);
        $this->l->method('t')->willReturnArgument(0);
        $this->logger = $this->createMock(LoggerInterface::class);

        $user = $this->createMock(IUser::class);
        $this->userSession->method('getUser')->willReturn($user);

        $this->controller = $this->buildController();

    }//end setUp()

    /**
     * Build a controller instance wired to the current mocks.
     *
     * @return FscController
     */
    private function buildController(): FscController
    {
        return new FscController(
            'openconnector',
            $this->request,
            $this->callService,
            $this->userSession,
            $this->actionAuth,
            $this->l,
            $this->logger
        );

    }//end buildController()

    /**
     * listServices() requires authentication before invoking the call service.
     *
     * @return void
     */
    public function testListServicesRequiresAuthentication(): void
    {
        $this->userSession = $this->createMock(IUserSession::class);
        $this->userSession->method('getUser')->willReturn(null);
        $this->controller  = $this->buildController();

        $this->callService->expects($this->never())->method('listResolvableServices');

        $response = $this->controller->listServices();

        $this->assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());

    }//end testListServicesRequiresAuthentication()

    /**
     * listServices() returns the call service's list verbatim, proving the route actually invokes it.
     *
     * @return void
     *
     * @spec openspec/changes/fsc-connectivity/specs/fsc-connectivity/spec.md#scenario-listing-services-returns-the-current-cache
     */
    public function testListServicesReturnsResult(): void
    {
        $this->callService->expects($this->once())
            ->method('listResolvableServices')
            ->willReturn([['organisation' => 'org-a', 'service' => 'svc-a']]);

        $response = $this->controller->listServices();

        $this->assertSame(Http::STATUS_OK, $response->getStatus());
        $this->assertSame(
            ['services' => [['organisation' => 'org-a', 'service' => 'svc-a']]],
            $response->getData()
        );

    }//end testListServicesReturnsResult()

    /**
     * listServices() returns an empty list, not an error, when unconfigured.
     *
     * @return void
     *
     * @spec openspec/changes/fsc-connectivity/specs/fsc-connectivity/spec.md#scenario-listing-services-when-unconfigured-returns-an-empty-list-not-an-error
     */
    public function testListServicesReturnsEmptyListWhenUnconfigured(): void
    {
        $this->callService->method('listResolvableServices')->willReturn([]);

        $response = $this->controller->listServices();

        $this->assertSame(Http::STATUS_OK, $response->getStatus());
        $this->assertSame(['services' => []], $response->getData());

    }//end testListServicesReturnsEmptyListWhenUnconfigured()

    /**
     * call() requires authentication before invoking the call service.
     *
     * @return void
     */
    public function testCallRequiresAuthentication(): void
    {
        $this->userSession = $this->createMock(IUserSession::class);
        $this->userSession->method('getUser')->willReturn(null);
        $this->controller  = $this->buildController();

        $this->callService->expects($this->never())->method('callService');

        $response = $this->controller->call();

        $this->assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());

    }//end testCallRequiresAuthentication()

    /**
     * call() with a missing organisation/service is rejected 400 before the call service runs.
     *
     * @return void
     */
    public function testCallRequiresOrganisationAndService(): void
    {
        $this->request->method('getParams')->willReturn(['organisation' => 'org-a']);

        $this->callService->expects($this->never())->method('callService');

        $response = $this->controller->call();

        $this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
        $this->assertSame('missing_fields', $response->getData()['error']);

    }//end testCallRequiresOrganisationAndService()

    /**
     * A valid call request returns the call service's result verbatim, proving the route actually invokes it.
     *
     * @return void
     *
     * @spec openspec/changes/fsc-connectivity/specs/fsc-connectivity/spec.md#scenario-a-valid-call-request-returns-ref-statuscode-and-body
     */
    public function testCallReturnsResult(): void
    {
        $this->request->method('getParams')->willReturn(['organisation' => 'org-a', 'service' => 'svc-a']);

        $this->callService->expects($this->once())
            ->method('callService')
            ->willReturn(['ref' => 'FSC-abc123', 'statusCode' => 200, 'body' => []]);

        $response = $this->controller->call();

        $this->assertSame(Http::STATUS_OK, $response->getStatus());
        $this->assertSame(['ref' => 'FSC-abc123', 'statusCode' => 200, 'body' => []], $response->getData());

    }//end testCallReturnsResult()

    /**
     * An FscDirectoryException (unknown organisation/service) maps to 404 unknown_service.
     *
     * @return void
     *
     * @spec openspec/changes/fsc-connectivity/specs/fsc-connectivity/spec.md#scenario-a-call-request-for-an-unknown-organisation-or-service-returns-404
     */
    public function testCallMapsDirectoryExceptionTo404(): void
    {
        $this->request->method('getParams')->willReturn(['organisation' => 'org-x', 'service' => 'svc-a']);

        $this->callService->method('callService')->willThrowException(
            new FscDirectoryException(message: 'Unknown organisation "org-x" — not present in the configured directory.')
        );

        $response = $this->controller->call();

        $this->assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
        $this->assertSame('unknown_service', $response->getData()['error']);

    }//end testCallMapsDirectoryExceptionTo404()

    /**
     * When no FSC source is configured, the endpoint reports a clean 503 not_configured.
     *
     * @return void
     *
     * @spec openspec/changes/fsc-connectivity/specs/fsc-connectivity/spec.md#scenario-a-call-request-with-no-active-source-returns-not_configured
     */
    public function testCallReportsNotConfiguredCleanly(): void
    {
        $this->request->method('getParams')->willReturn(['organisation' => 'org-a', 'service' => 'svc-a']);

        $this->callService->method('callService')->willThrowException(
            new FscConnectivityException(
                message: 'No active FSC source is configured (register "openconnector", schema "source", '
                    .'type "fsc", isEnabled=true). Configure one before using FSC connectivity.'
            )
        );

        $response = $this->controller->call();

        $this->assertSame(Http::STATUS_SERVICE_UNAVAILABLE, $response->getStatus());
        $this->assertSame('not_configured', $response->getData()['error']);

    }//end testCallReportsNotConfiguredCleanly()

    /**
     * A generic transport failure (source configured, but transport itself errors) maps to 502.
     *
     * @return void
     */
    public function testCallMapsProviderFailureTo502(): void
    {
        $this->request->method('getParams')->willReturn(['organisation' => 'org-a', 'service' => 'svc-a']);

        $this->callService->method('callService')->willThrowException(
            new FscConnectivityException(message: 'FSC endpoint responded with HTTP 503.')
        );

        $response = $this->controller->call();

        $this->assertSame(Http::STATUS_BAD_GATEWAY, $response->getStatus());
        $this->assertSame('fsc_call_failed', $response->getData()['error']);

    }//end testCallMapsProviderFailureTo502()
}//end class

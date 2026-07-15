<?php

/**
 * Unit tests for IwmoIjwController.
 *
 * @category Test
 * @package  OCA\OpenConnector\Tests\Unit\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 *
 * @spec openspec/changes/iwmo-ijw-adapter/tasks.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenConnector\Tests\Unit\Controller;

use OCA\OpenConnector\Controller\IwmoIjwController;
use OCA\OpenConnector\Exception\IwmoIjwProviderException;
use OCA\OpenConnector\Exception\IwmoIjwTranslationException;
use OCA\OpenConnector\Service\ActionAuthService;
use OCA\OpenConnector\Service\IwmoIjwSyncService;
use OCA\OpenConnector\Service\WebhookSignatureService;
use OCA\OpenRegister\Db\ObjectEntity;
use OCP\AppFramework\Http;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Tests for the iWMO/iJW push (createBericht) endpoint and the signed inbound retour receiver.
 *
 * @spec openspec/changes/iwmo-ijw-adapter/specs/iwmo-ijw-adapter/spec.md#requirement-push-endpoint-and-signed-inbound-retour-receiver-req-004
 */
class IwmoIjwControllerTest extends TestCase
{

    /**
     * @var IRequest|\PHPUnit\Framework\MockObject\MockObject
     */
    private $request;

    /**
     * @var IwmoIjwSyncService|\PHPUnit\Framework\MockObject\MockObject
     */
    private $syncService;

    /**
     * @var WebhookSignatureService|\PHPUnit\Framework\MockObject\MockObject
     */
    private $signatureService;

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
     * @var IwmoIjwController
     */
    private IwmoIjwController $controller;

    /**
     * Set up test fixtures.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->request          = $this->createMock(IRequest::class);
        $this->syncService      = $this->createMock(IwmoIjwSyncService::class);
        $this->signatureService = $this->createMock(WebhookSignatureService::class);
        $this->userSession      = $this->createMock(IUserSession::class);
        $this->actionAuth       = $this->createMock(ActionAuthService::class);
        $this->l                = $this->createMock(IL10N::class);
        $this->l->method('t')->willReturnArgument(0);
        $this->logger = $this->createMock(LoggerInterface::class);

        $user = $this->createMock(IUser::class);
        $this->userSession->method('getUser')->willReturn($user);

        $this->controller = $this->buildController();

    }//end setUp()

    /**
     * Build a controller instance wired to the current mocks.
     *
     * @return IwmoIjwController
     */
    private function buildController(): IwmoIjwController
    {
        return new IwmoIjwController(
            'openconnector',
            $this->request,
            $this->syncService,
            $this->signatureService,
            $this->userSession,
            $this->actionAuth,
            $this->l,
            $this->logger
        );

    }//end buildController()

    /**
     * An unauthenticated caller gets 401 without reaching the sync service.
     *
     * @return void
     */
    public function testCreateBerichtRequiresAuthentication(): void
    {
        $this->userSession = $this->createMock(IUserSession::class);
        $this->userSession->method('getUser')->willReturn(null);
        $this->controller  = $this->buildController();

        $this->syncService->expects($this->never())->method('sendBericht');

        $response = $this->controller->createBericht();

        $this->assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());

    }//end testCreateBerichtRequiresAuthentication()

    /**
     * A missing required field (`kind`/`domain`) is rejected with 400 before the sync service is called.
     *
     * @return void
     */
    public function testCreateBerichtRequiresKindAndDomain(): void
    {
        $this->request->method('getParams')->willReturn(['kind' => 'toewijzing']);

        $this->syncService->expects($this->never())->method('sendBericht');

        $response = $this->controller->createBericht();

        $this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
        $this->assertSame('missing_fields', $response->getData()['error']);

    }//end testCreateBerichtRequiresKindAndDomain()

    /**
     * A valid push request returns the sync service's result verbatim.
     *
     * @return void
     *
     * @spec openspec/changes/iwmo-ijw-adapter/specs/iwmo-ijw-adapter/spec.md#scenario-a-valid-push-request-returns-a-ref-and-berichttype
     */
    public function testCreateBerichtReturnsResult(): void
    {
        $this->request->method('getParams')->willReturn(['kind' => 'toewijzing', 'domain' => 'wmo']);

        $this->syncService->expects($this->once())
            ->method('sendBericht')
            ->willReturn(['ref' => 'WMO-abc123', 'berichttype' => 'Wmo303']);

        $response = $this->controller->createBericht();

        $this->assertSame(Http::STATUS_OK, $response->getStatus());
        $this->assertSame(['ref' => 'WMO-abc123', 'berichttype' => 'Wmo303'], $response->getData());

    }//end testCreateBerichtReturnsResult()

    /**
     * An IwmoIjwTranslationException (incomplete payload) maps to 400 `invalid_bericht`.
     *
     * @return void
     */
    public function testCreateBerichtMapsTranslationExceptionTo400(): void
    {
        $this->request->method('getParams')->willReturn(['kind' => 'toewijzing', 'domain' => 'wmo']);

        $this->syncService->method('sendBericht')->willThrowException(
            new IwmoIjwTranslationException(message: 'Required field "productcode" is missing or empty.')
        );

        $response = $this->controller->createBericht();

        $this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
        $this->assertSame('invalid_bericht', $response->getData()['error']);

    }//end testCreateBerichtMapsTranslationExceptionTo400()

    /**
     * When no iWMO/iJW source is configured, the endpoint reports a clean 503 `not_configured`.
     *
     * @return void
     *
     * @spec openspec/changes/iwmo-ijw-adapter/specs/iwmo-ijw-adapter/spec.md#scenario-a-push-request-with-no-active-source-returns-not_configured
     */
    public function testCreateBerichtReportsNotConfiguredCleanly(): void
    {
        $this->request->method('getParams')->willReturn(['kind' => 'toewijzing', 'domain' => 'wmo']);

        $this->syncService->method('sendBericht')->willThrowException(
            new IwmoIjwProviderException(message: 'No active iWMO/iJW source is configured (register "openconnector", schema "source", type "iwmo-ijw", isEnabled=true). Configure one before using the iWMO/iJW bridge.')
        );

        $response = $this->controller->createBericht();

        $this->assertSame(Http::STATUS_SERVICE_UNAVAILABLE, $response->getStatus());
        $this->assertSame('not_configured', $response->getData()['error']);

    }//end testCreateBerichtReportsNotConfiguredCleanly()

    /**
     * A generic transport failure (source configured, but transport itself errors) maps to 502.
     *
     * @return void
     */
    public function testCreateBerichtMapsProviderFailureTo502(): void
    {
        $this->request->method('getParams')->willReturn(['kind' => 'toewijzing', 'domain' => 'wmo']);

        $this->syncService->method('sendBericht')->willThrowException(
            new IwmoIjwProviderException(message: 'iStandaarden endpoint responded with HTTP 503.')
        );

        $response = $this->controller->createBericht();

        $this->assertSame(Http::STATUS_BAD_GATEWAY, $response->getStatus());
        $this->assertSame('iwmo_ijw_send_failed', $response->getData()['error']);

    }//end testCreateBerichtMapsProviderFailureTo502()

    /**
     * No iWMO/iJW source configured at all fails the inbound webhook closed (401).
     *
     * @return void
     *
     * @spec openspec/changes/iwmo-ijw-adapter/specs/iwmo-ijw-adapter/spec.md#scenario-an-unsigned-retour-is-rejected-before-any-processing
     */
    public function testInboundWithNoSourceConfiguredReturns401(): void
    {
        $this->syncService->method('resolveActiveSource')
            ->willThrowException(new IwmoIjwProviderException(message: 'no source'));
        $this->signatureService->expects($this->never())->method('verify');

        $response = $this->controller->inbound();

        $this->assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());

    }//end testInboundWithNoSourceConfiguredReturns401()

    /**
     * An unsigned/tampered retour is rejected 401 before any state change.
     *
     * @return void
     *
     * @spec openspec/changes/iwmo-ijw-adapter/specs/iwmo-ijw-adapter/spec.md#scenario-an-unsigned-retour-is-rejected-before-any-processing
     */
    public function testInboundInvalidSignatureReturns401BeforeAnySideEffect(): void
    {
        $source = new ObjectEntity();
        $source->setObject(['configuration' => ['webhookSignature' => ['secret' => 'whsec_test']]]);
        $this->syncService->method('resolveActiveSource')->willReturn($source);
        $this->signatureService->method('verify')->willReturn(false);

        $this->syncService->expects($this->never())->method('receiveRetour');

        $response = $this->controller->inbound();

        $this->assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());
        $this->assertSame('invalid signature', $response->getData()['error']);

    }//end testInboundInvalidSignatureReturns401BeforeAnySideEffect()

    /**
     * A verified retour is routed to receiveRetour() and always acknowledges receipt.
     *
     * @return void
     */
    public function testInboundVerifiedRetourIsRoutedAndAcknowledged(): void
    {
        $source = new ObjectEntity();
        $source->setObject(['configuration' => ['webhookSignature' => ['secret' => 'whsec_test']]]);
        $this->syncService->method('resolveActiveSource')->willReturn($source);
        $this->signatureService->method('verify')->willReturn(true);

        $this->syncService->expects($this->once())->method('receiveRetour');

        $response = $this->controller->inbound();

        $this->assertSame(Http::STATUS_OK, $response->getStatus());
        $this->assertTrue($response->getData()['received']);

    }//end testInboundVerifiedRetourIsRoutedAndAcknowledged()

    /**
     * A processing exception after a verified signature never surfaces as a 500.
     *
     * @return void
     *
     * @spec openspec/changes/iwmo-ijw-adapter/specs/iwmo-ijw-adapter/spec.md#scenario-a-verified-retour-always-acknowledges-receipt
     */
    public function testInboundNeverCrashesOnProcessingException(): void
    {
        $source = new ObjectEntity();
        $source->setObject(['configuration' => ['webhookSignature' => ['secret' => 'whsec_test']]]);
        $this->syncService->method('resolveActiveSource')->willReturn($source);
        $this->signatureService->method('verify')->willReturn(true);
        $this->syncService->method('receiveRetour')->willThrowException(new \RuntimeException('boom'));

        $response = $this->controller->inbound();

        $this->assertSame(Http::STATUS_OK, $response->getStatus());
        $this->assertTrue($response->getData()['received']);

    }//end testInboundNeverCrashesOnProcessingException()
}//end class

<?php

/**
 * Unit tests for StufZknController.
 *
 * @category Test
 * @package  OCA\OpenConnector\Tests\Unit\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 *
 * @spec openspec/changes/stuf-zkn-bridge/tasks.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenConnector\Tests\Unit\Controller;

use OCA\OpenConnector\Controller\StufZknController;
use OCA\OpenConnector\Exception\StufZknProviderException;
use OCA\OpenConnector\Exception\StufZknTranslationException;
use OCA\OpenConnector\Service\ActionAuthService;
use OCA\OpenConnector\Service\StufZknSyncService;
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
 * Tests for the StUF-ZKN inbound SOAP endpoint (signature gate, Bv03 reply on success) and the
 * authenticated outbound push endpoint.
 *
 * @spec openspec/changes/stuf-zkn-bridge/specs/stuf-zkn-bridge/spec.md
 */
class StufZknControllerTest extends TestCase
{

    /**
     * @var IRequest|\PHPUnit\Framework\MockObject\MockObject
     */
    private $request;

    /**
     * @var StufZknSyncService|\PHPUnit\Framework\MockObject\MockObject
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
     * @var StufZknController
     */
    private StufZknController $controller;

    /**
     * Set up test fixtures.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->request          = $this->createMock(IRequest::class);
        $this->syncService      = $this->createMock(StufZknSyncService::class);
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
     * @return StufZknController
     */
    private function buildController(): StufZknController
    {
        return new StufZknController(
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
     * No stuf-zkn source configured at all fails the inbound endpoint closed (401) — no secret
     * to verify against.
     *
     * @return void
     */
    public function testInboundWithNoSourceConfiguredReturns401(): void
    {
        $this->syncService->method('resolveActiveSource')
            ->willThrowException(new StufZknProviderException(message: 'no source'));
        $this->signatureService->expects($this->never())->method('verify');

        $response = $this->controller->inbound();

        $this->assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());

    }//end testInboundWithNoSourceConfiguredReturns401()

    /**
     * An unsigned/tampered inbound request is rejected 401 before any processing.
     *
     * @return void
     */
    public function testInboundInvalidSignatureReturns401BeforeAnySideEffect(): void
    {
        $source = new ObjectEntity();
        $source->setObject(['configuration' => ['webhookSignature' => ['secret' => 'whsec_test']]]);
        $this->syncService->method('resolveActiveSource')->willReturn($source);
        $this->signatureService->method('verify')->willReturn(false);

        $this->syncService->expects($this->never())->method('receiveInbound');

        $response = $this->controller->inbound();

        $this->assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());
        $this->assertSame('invalid signature', $response->getData()['error']);

    }//end testInboundInvalidSignatureReturns401BeforeAnySideEffect()

    /**
     * A verified inbound request is routed to receiveInbound() and its Bv03/Fo03 reply is
     * returned verbatim as an XML body.
     *
     * @return void
     *
     * @spec openspec/changes/stuf-zkn-bridge/specs/stuf-zkn-bridge/spec.md#requirement-inbound-soap-endpoint-with-bv03-fo03-shaping-req-005
     */
    public function testInboundVerifiedRequestReturnsSyncServiceReplyVerbatim(): void
    {
        $source = new ObjectEntity();
        $source->setObject(['configuration' => ['webhookSignature' => ['secret' => 'whsec_test']]]);
        $this->syncService->method('resolveActiveSource')->willReturn($source);
        $this->signatureService->method('verify')->willReturn(true);

        $ackXml = '<soap:Envelope><soap:Body><StUF:Bv03Bericht/></soap:Body></soap:Envelope>';
        $this->syncService->expects($this->once())->method('receiveInbound')->willReturn($ackXml);

        $response = $this->controller->inbound();

        $this->assertSame(Http::STATUS_OK, $response->getStatus());
        $this->assertSame($ackXml, $response->render());

        // Response::getHeaders() needs a booted \OC (it appends the CSP
        // header via the server container), so read the raw protected
        // headers property instead — standalone-suite safe (mirrors
        // ConfigurationControllerTest::testExportReturnsAttachmentWithServiceDocument()).
        $property = new \ReflectionProperty(\OCP\AppFramework\Http\Response::class, 'headers');
        $headers  = $property->getValue($response);
        $this->assertSame('text/xml; charset=utf-8', $headers['Content-Type']);

    }//end testInboundVerifiedRequestReturnsSyncServiceReplyVerbatim()

    /**
     * An unauthenticated caller gets 401 on the outbound push endpoint without reaching the
     * sync service.
     *
     * @return void
     */
    public function testOutboundRequiresAuthentication(): void
    {
        $this->userSession = $this->createMock(IUserSession::class);
        $this->userSession->method('getUser')->willReturn(null);
        $this->controller  = $this->buildController();

        $this->syncService->expects($this->never())->method('sendKennisgeving');

        $response = $this->controller->outbound();

        $this->assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());

    }//end testOutboundRequiresAuthentication()

    /**
     * A missing required field (`zaak`/`verwerkingssoort`) is rejected with 400.
     *
     * @return void
     */
    public function testOutboundRequiresZaakAndVerwerkingssoort(): void
    {
        $this->request->method('getParams')->willReturn(['zaak' => ['identificatie' => 'X']]);

        $this->syncService->expects($this->never())->method('sendKennisgeving');

        $response = $this->controller->outbound();

        $this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
        $this->assertSame('missing_fields', $response->getData()['error']);

    }//end testOutboundRequiresZaakAndVerwerkingssoort()

    /**
     * A valid push request returns the sync service's result verbatim.
     *
     * @return void
     */
    public function testOutboundReturnsResult(): void
    {
        $this->request->method('getParams')->willReturn(
            ['zaak' => ['identificatie' => 'ZAAK-1'], 'verwerkingssoort' => 'T']
        );

        $this->syncService->expects($this->once())
            ->method('sendKennisgeving')
            ->willReturn(['referentienummer' => 'ZKN-abc', 'ref' => 'ack-1']);

        $response = $this->controller->outbound();

        $this->assertSame(Http::STATUS_OK, $response->getStatus());
        $this->assertSame(['referentienummer' => 'ZKN-abc', 'ref' => 'ack-1'], $response->getData());

    }//end testOutboundReturnsResult()

    /**
     * A StufZknTranslationException (incomplete zaak) maps to 400 `invalid_kennisgeving`.
     *
     * @return void
     */
    public function testOutboundMapsTranslationExceptionTo400(): void
    {
        $this->request->method('getParams')->willReturn(
            ['zaak' => ['identificatie' => 'ZAAK-1'], 'verwerkingssoort' => 'T']
        );

        $this->syncService->method('sendKennisgeving')->willThrowException(
            new StufZknTranslationException(message: 'Required field "omschrijving" is missing or empty.')
        );

        $response = $this->controller->outbound();

        $this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
        $this->assertSame('invalid_kennisgeving', $response->getData()['error']);

    }//end testOutboundMapsTranslationExceptionTo400()

    /**
     * When no stuf-zkn source is configured, the endpoint reports a clean 503 `not_configured`.
     *
     * @return void
     */
    public function testOutboundReportsNotConfiguredCleanly(): void
    {
        $this->request->method('getParams')->willReturn(
            ['zaak' => ['identificatie' => 'ZAAK-1'], 'verwerkingssoort' => 'T']
        );

        $this->syncService->method('sendKennisgeving')->willThrowException(
            new StufZknProviderException(message: 'No active StUF-ZKN source is configured (register "openconnector", schema "source", type "stuf-zkn", isEnabled=true). Configure one before using the StUF-ZKN bridge.')
        );

        $response = $this->controller->outbound();

        $this->assertSame(Http::STATUS_SERVICE_UNAVAILABLE, $response->getStatus());
        $this->assertSame('not_configured', $response->getData()['error']);

    }//end testOutboundReportsNotConfiguredCleanly()

    /**
     * A generic transport failure (source configured, but transport itself errors) maps to 502.
     *
     * @return void
     */
    public function testOutboundMapsProviderFailureTo502(): void
    {
        $this->request->method('getParams')->willReturn(
            ['zaak' => ['identificatie' => 'ZAAK-1'], 'verwerkingssoort' => 'T']
        );

        $this->syncService->method('sendKennisgeving')->willThrowException(
            new StufZknProviderException(message: 'StUF-ZKN consumer endpoint responded with HTTP 503.')
        );

        $response = $this->controller->outbound();

        $this->assertSame(Http::STATUS_BAD_GATEWAY, $response->getStatus());
        $this->assertSame('stuf_zkn_send_failed', $response->getData()['error']);

    }//end testOutboundMapsProviderFailureTo502()
}//end class

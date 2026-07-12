<?php
/**
 * Unit tests for EudiWalletController.
 *
 * @category Test
 * @package  OCA\OpenConnector\Tests\Unit\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 *
 * @spec openspec/changes/eudi-wallet-credential-issuance/specs/eudi-wallet-credential-issuance/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenConnector\Tests\Unit\Controller;

use OCA\OpenConnector\Controller\EudiWalletController;
use OCA\OpenConnector\Exception\AuthenticationException;
use OCA\OpenConnector\Exception\EudiIssuanceException;
use OCA\OpenConnector\Service\AuthorizationService;
use OCA\OpenConnector\Service\EudiCredentialOfferService;
use OCA\OpenConnector\Service\EudiIssuerKeyService;
use OCA\OpenConnector\Service\EudiStatusListService;
use OCA\OpenRegister\Db\ObjectEntity;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\DataDisplayResponse;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Tests for the EUDI wallet issuance controller — auth gating on the
 * app-facing routes and dispatch/error-mapping on the wallet-facing routes.
 *
 * @spec openspec/changes/eudi-wallet-credential-issuance/specs/eudi-wallet-credential-issuance/spec.md
 */
class EudiWalletControllerTest extends TestCase
{

    /**
     * @var \PHPUnit\Framework\MockObject\MockObject|IRequest
     */
    private $request;

    /**
     * @var \PHPUnit\Framework\MockObject\MockObject|EudiCredentialOfferService
     */
    private $offerService;

    /**
     * @var \PHPUnit\Framework\MockObject\MockObject|EudiIssuerKeyService
     */
    private $keyService;

    /**
     * @var \PHPUnit\Framework\MockObject\MockObject|EudiStatusListService
     */
    private $statusListService;

    /**
     * @var \PHPUnit\Framework\MockObject\MockObject|AuthorizationService
     */
    private $authorizationService;

    /**
     * @var EudiWalletController
     */
    private EudiWalletController $controller;

    /**
     * Set up test fixtures.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->request              = $this->createMock(IRequest::class);
        $this->offerService         = $this->createMock(EudiCredentialOfferService::class);
        $this->keyService           = $this->createMock(EudiIssuerKeyService::class);
        $this->statusListService    = $this->createMock(EudiStatusListService::class);
        $this->authorizationService = $this->createMock(AuthorizationService::class);

        $this->request->method('getServerProtocol')->willReturn('https');
        $this->request->method('getServerHost')->willReturn('example.test');

        $this->controller = new EudiWalletController(
            'openconnector',
            $this->request,
            $this->offerService,
            $this->keyService,
            $this->statusListService,
            $this->authorizationService,
            $this->createMock(LoggerInterface::class)
        );

    }//end setUp()

    /**
     * REQ-EUDI-004: a missing bearer token is rejected 401 before the offer
     * service is ever called.
     *
     * @return void
     */
    public function testCreateOfferWithoutBearerTokenIs401(): void
    {
        $this->request->method('getHeader')->with('Authorization')->willReturn('');
        $this->offerService->expects($this->never())->method('createOffer');

        $response = $this->controller->createOffer();

        $this->assertInstanceOf(JSONResponse::class, $response);
        $this->assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());

    }//end testCreateOfferWithoutBearerTokenIs401()

    /**
     * REQ-EUDI-004: a JWT that fails verification is rejected 401 before the
     * offer service is ever called.
     *
     * @return void
     */
    public function testCreateOfferWithInvalidJwtIs401(): void
    {
        $this->request->method('getHeader')->with('Authorization')->willReturn('Bearer garbage');
        $this->authorizationService->method('authorizeJwt')
            ->willThrowException(new AuthenticationException('bad token', []));
        $this->offerService->expects($this->never())->method('createOffer');

        $response = $this->controller->createOffer();

        $this->assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());

    }//end testCreateOfferWithInvalidJwtIs401()

    /**
     * A correctly-authenticated consumer creates an offer and receives
     * `{offerUrl, credentialOfferUri, qrPayload}`.
     *
     * @return void
     */
    public function testCreateOfferSuccessReturnsOfferUrls(): void
    {
        $this->request->method('getHeader')->with('Authorization')->willReturn('Bearer valid.jwt.token');
        $this->request->method('getParams')->willReturn(['format' => 'jwt_vc_json']);

        $consumer = new ObjectEntity();
        $consumer->setUuid('consumer-1');

        $this->authorizationService->method('getResolvedConsumer')->willReturn($consumer);
        $this->offerService->expects($this->once())
            ->method('createOffer')
            ->with(['format' => 'jwt_vc_json'], 'consumer-1')
            ->willReturn(['uuid' => 'offer-uuid-1', 'offerCode' => 'plaintext-code']);

        $response = $this->controller->createOffer();
        $data     = $response->getData();

        $this->assertSame(Http::STATUS_OK, $response->getStatus());
        $this->assertStringContainsString('offer-uuid-1', $data['credentialOfferUri']);
        $this->assertStringStartsWith('openid-credential-offer://', $data['offerUrl']);
        $this->assertSame($data['offerUrl'], $data['qrPayload']);

    }//end testCreateOfferSuccessReturnsOfferUrls()

    /**
     * A service-level rejection (EudiIssuanceException) is rendered with its
     * declared HTTP status.
     *
     * @return void
     */
    public function testCreateOfferMapsServiceRejectionToDeclaredStatus(): void
    {
        $this->request->method('getHeader')->with('Authorization')->willReturn('Bearer valid.jwt.token');
        $this->request->method('getParams')->willReturn([]);

        $consumer = new ObjectEntity();
        $consumer->setUuid('consumer-1');
        $this->authorizationService->method('getResolvedConsumer')->willReturn($consumer);

        $this->offerService->method('createOffer')
            ->willThrowException(new EudiIssuanceException('subjectId is required', 400, 'invalid_request'));

        $response = $this->controller->createOffer();

        $this->assertSame(400, $response->getStatus());
        $this->assertSame('invalid_request', $response->getData()['error']);

    }//end testCreateOfferMapsServiceRejectionToDeclaredStatus()

    /**
     * REQ-EUDI-005: an unresolvable offer id returns a generic 404.
     *
     * @return void
     */
    public function testResolveOfferNotFoundReturns404(): void
    {
        $this->offerService->method('resolveOfferForWallet')->willReturn(null);

        $response = $this->controller->resolveOffer('unknown-id');

        $this->assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());

    }//end testResolveOfferNotFoundReturns404()

    /**
     * REQ-EUDI-008: a published status list is served as a raw JWT with the
     * `application/statuslist+jwt` content type, not wrapped in JSON.
     *
     * @return void
     */
    public function testStatusListReturnsRawJwtWithCorrectContentType(): void
    {
        $this->statusListService->method('getPublishedToken')->with('list-1')->willReturn('a.b.c');

        $response = $this->controller->statusList('list-1');

        $this->assertInstanceOf(DataDisplayResponse::class, $response);
        $this->assertSame('a.b.c', $response->render());

        // getHeaders() merges in NC-server-derived headers (CSP, request id)
        // that require a bootstrapped \OC::$server — not available in a unit
        // test. Read the raw per-response header map via reflection instead.
        $reflection = new \ReflectionProperty(\OCP\AppFramework\Http\Response::class, 'headers');
        $reflection->setAccessible(true);
        $this->assertSame('application/statuslist+jwt', $reflection->getValue($response)['Content-Type']);

    }//end testStatusListReturnsRawJwtWithCorrectContentType()

    /**
     * A missing status list row returns a JSON 404, not a raw response.
     *
     * @return void
     */
    public function testStatusListNotFoundReturnsJson404(): void
    {
        $this->statusListService->method('getPublishedToken')->willReturn(null);

        $response = $this->controller->statusList('unknown-id');

        $this->assertInstanceOf(JSONResponse::class, $response);
        $this->assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());

    }//end testStatusListNotFoundReturnsJson404()

    /**
     * REQ-EUDI-006: token() delegates to the offer service and maps a
     * rejection to its declared error code.
     *
     * @return void
     */
    public function testTokenMapsInvalidGrantRejection(): void
    {
        $this->request->method('getParams')->willReturn(['grant_type' => 'unsupported']);
        $this->offerService->method('exchangeToken')
            ->willThrowException(new EudiIssuanceException('bad grant', 400, 'invalid_grant'));

        $response = $this->controller->token();

        $this->assertSame(400, $response->getStatus());
        $this->assertSame('invalid_grant', $response->getData()['error']);

    }//end testTokenMapsInvalidGrantRejection()

    /**
     * REQ-EUDI-009: revoke() requires the same consumer authentication as
     * offer creation.
     *
     * @return void
     */
    public function testRevokeWithoutBearerTokenIs401(): void
    {
        $this->request->method('getHeader')->with('Authorization')->willReturn('');
        $this->offerService->expects($this->never())->method('revoke');

        $response = $this->controller->revoke('offer-1');

        $this->assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());

    }//end testRevokeWithoutBearerTokenIs401()
}//end class

<?php

/**
 * Contract tests for LtiController's Platform-role (inbound) endpoints.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 *
 * @spec openspec/specs/lti-platform/spec.md
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Controller;

use OCA\Integriq\Controller\LtiController;
use OCA\Integriq\Exception\LtiValidationException;
use OCA\Integriq\Service\Lti\LtiAgsService;
use OCA\Integriq\Service\Lti\LtiKeyService;
use OCA\Integriq\Service\Lti\LtiLaunchService;
use OCA\Integriq\Service\Lti\LtiNrpsService;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Http\RedirectResponse;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Every endpoint here is `#[PublicPage]` — reachable by any unauthenticated
 * caller on the internet — and each one is the boundary where an external
 * Platform's or Tool's assertion is either trusted or refused. The contract
 * that matters is therefore the refusal: a request that fails to present a
 * credential must be rejected BEFORE any service is asked to do work, and a
 * rejection must keep the status the protocol assigns it rather than being
 * flattened into a generic error the caller cannot act on.
 *
 * @spec openspec/specs/lti-platform/spec.md
 */
class LtiControllerInboundTest extends TestCase {

	/**
	 * @var \PHPUnit\Framework\MockObject\MockObject|IRequest
	 */
	private $request;

	/**
	 * @var \PHPUnit\Framework\MockObject\MockObject|LtiLaunchService
	 */
	private $launchService;

	/**
	 * @var \PHPUnit\Framework\MockObject\MockObject|LtiAgsService
	 */
	private $agsService;

	/**
	 * @var \PHPUnit\Framework\MockObject\MockObject|LtiNrpsService
	 */
	private $nrpsService;

	/**
	 * @var \PHPUnit\Framework\MockObject\MockObject|LtiKeyService
	 */
	private $keyService;

	/**
	 * @var LtiController
	 */
	private LtiController $controller;

	/**
	 * Set up test fixtures.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->request = $this->createMock(IRequest::class);
		$this->launchService = $this->createMock(LtiLaunchService::class);
		$this->agsService = $this->createMock(LtiAgsService::class);
		$this->nrpsService = $this->createMock(LtiNrpsService::class);
		$this->keyService = $this->createMock(LtiKeyService::class);

		$this->controller = new LtiController(
			'openconnector',
			$this->request,
			$this->launchService,
			$this->agsService,
			$this->nrpsService,
			$this->keyService,
			$this->createMock(LoggerInterface::class)
		);

	}//end setUp()

	// ---------------------------------------------------------------------
	// REQ-LTI-005 — launch
	// ---------------------------------------------------------------------

	/**
	 * A launch with no `id_token` is a 400 and the launch service is never
	 * asked to validate anything. There is nothing to validate, and calling in
	 * anyway is how a missing credential turns into an unchecked one.
	 *
	 * @return void
	 */
	public function testLaunchWithoutAnIdTokenIs400AndNeverValidates(): void {
		$this->request->method('getParam')->willReturn('');
		$this->launchService->expects($this->never())->method('validateLaunch');

		$response = $this->controller->launch('deployment-1');

		$this->assertInstanceOf(JSONResponse::class, $response);
		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertSame('Missing id_token', $response->getData()['error']);

	}//end testLaunchWithoutAnIdTokenIs400AndNeverValidates()

	/**
	 * A launch carries the state twice — once in the POST body and once in the
	 * cookie set at login — and the pair is what defeats CSRF on a cross-site
	 * POST. Both must reach the validator, along with the deployment from the
	 * route.
	 *
	 * @return void
	 */
	public function testLaunchForwardsBothStatesAndTheDeploymentToTheValidator(): void {
		$this->request->method('getParam')->willReturnMap(
			[
				['id_token', '', 'the-id-token'],
				['state', null, 'presented-state'],
			]
		);
		$this->request->method('getCookie')
			->with('oc_lti_state_deployment-1')
			->willReturn('cookie-state');

		$this->launchService->expects($this->once())
			->method('validateLaunch')
			->with(
				$this->identicalTo('the-id-token'),
				$this->identicalTo('deployment-1'),
				$this->identicalTo('cookie-state'),
				$this->identicalTo('presented-state')
			)
			->willReturn(['redirectUrl' => 'https://tool.test/launched']);

		$response = $this->controller->launch('deployment-1');

		$this->assertInstanceOf(RedirectResponse::class, $response);
		$this->assertSame('https://tool.test/launched', $response->getRedirectURL());

	}//end testLaunchForwardsBothStatesAndTheDeploymentToTheValidator()

	/**
	 * A failed launch validation must NOT redirect. Rendering the rejection is
	 * the whole point — a 302 on an invalid launch would hand the browser to a
	 * target the assertion never authorised.
	 *
	 * @return void
	 */
	public function testLaunchRejectionIsRenderedNotRedirected(): void {
		$this->request->method('getParam')->willReturnMap(
			[
				['id_token', '', 'the-id-token'],
				['state', null, 'presented-state'],
			]
		);
		$this->request->method('getCookie')->willReturn('cookie-state');

		$this->launchService->method('validateLaunch')->willThrowException(
			new LtiValidationException(message: 'nonce replay', details: [], httpStatus: 401)
		);

		$response = $this->controller->launch('deployment-1');

		$this->assertNotInstanceOf(RedirectResponse::class, $response);
		$this->assertInstanceOf(JSONResponse::class, $response);
		$this->assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());
		$this->assertSame('nonce replay', $response->getData()['error']);

	}//end testLaunchRejectionIsRenderedNotRedirected()

	// ---------------------------------------------------------------------
	// REQ-LTI-007 — AGS inbound (Platform role)
	// ---------------------------------------------------------------------

	/**
	 * A score POST with no bearer token is 401 and the score is never received.
	 *
	 * @return void
	 */
	public function testAgsScoreWithoutABearerTokenIs401AndNeverReceivesTheScore(): void {
		$this->request->method('getHeader')->with('Authorization')->willReturn('');
		$this->agsService->expects($this->never())->method('receiveScore');

		$response = $this->controller->agsScore('deployment-1', 'lineitem-1');

		$this->assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());
		$this->assertSame('Missing bearer token', $response->getData()['error']);

	}//end testAgsScoreWithoutABearerTokenIs401AndNeverReceivesTheScore()

	/**
	 * An `Authorization` header that is present but not a Bearer credential is
	 * treated as absent, not passed through as a token value.
	 *
	 * @return void
	 */
	public function testAgsScoreRejectsANonBearerAuthorizationHeader(): void {
		$this->request->method('getHeader')->with('Authorization')->willReturn('Basic dXNlcjpwYXNz');
		$this->agsService->expects($this->never())->method('receiveScore');

		$response = $this->controller->agsScore('deployment-1', 'lineitem-1');

		$this->assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());

	}//end testAgsScoreRejectsANonBearerAuthorizationHeader()

	/**
	 * A line-item read with no bearer token is 401 and the deployment scope is
	 * never asserted — the token IS the thing being scoped, so there is nothing
	 * to check.
	 *
	 * @return void
	 */
	public function testAgsLineItemWithoutABearerTokenIs401AndNeverAssertsScope(): void {
		$this->request->method('getHeader')->with('Authorization')->willReturn('');
		$this->agsService->expects($this->never())->method('assertScopedToDeployment');

		$response = $this->controller->agsLineItem('deployment-1', 'lineitem-1');

		$this->assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());
		$this->assertSame('Missing bearer token', $response->getData()['error']);

	}//end testAgsLineItemWithoutABearerTokenIs401AndNeverAssertsScope()

	/**
	 * An authorized line-item read must be scoped to the `lineitem` scope and
	 * the deployment from the route — a token valid for another deployment must
	 * not read this one's line item.
	 *
	 * @return void
	 */
	public function testAgsLineItemAssertsTheLineItemScopeAgainstTheRouteDeployment(): void {
		$this->request->method('getHeader')->with('Authorization')->willReturn('Bearer service-token');

		$this->agsService->expects($this->once())
			->method('assertScopedToDeployment')
			->with(
				$this->identicalTo('service-token'),
				$this->identicalTo('deployment-1'),
				$this->identicalTo(LtiAgsService::SCOPE_LINEITEM)
			);

		$response = $this->controller->agsLineItem('deployment-1', 'lineitem-7');

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame('lineitem-7', $response->getData()['id']);

	}//end testAgsLineItemAssertsTheLineItemScopeAgainstTheRouteDeployment()

	// ---------------------------------------------------------------------
	// REQ-LTI-009 — NRPS inbound (Platform role)
	// ---------------------------------------------------------------------

	/**
	 * A membership request with no bearer token is 401 and no roster is read.
	 * The roster is personal data; reading it first and authorising afterwards
	 * would already be the disclosure.
	 *
	 * @return void
	 */
	public function testNrpsMembershipWithoutABearerTokenIs401AndNeverReadsTheRoster(): void {
		$this->request->method('getHeader')->with('Authorization')->willReturn('');
		$this->nrpsService->expects($this->never())->method('readRoster');

		$response = $this->controller->nrpsMembership('deployment-1');

		$this->assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());
		$this->assertSame('Missing bearer token', $response->getData()['error']);

	}//end testNrpsMembershipWithoutABearerTokenIs401AndNeverReadsTheRoster()

	/**
	 * A wrong-scope token is refused with the 403 the spec assigns it
	 * (REQ-LTI-009 scenario 2), not a 401 or a generic 400.
	 *
	 * @return void
	 */
	public function testNrpsMembershipWrongScopeKeepsThe403TheSpecAssigns(): void {
		$this->request->method('getHeader')->with('Authorization')->willReturn('Bearer wrong-scope-token');

		$this->nrpsService->method('readRoster')->willThrowException(
			new LtiValidationException(message: 'insufficient scope', details: [], httpStatus: 403)
		);

		$response = $this->controller->nrpsMembership('deployment-1');

		$this->assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
		$this->assertSame('insufficient scope', $response->getData()['error']);

	}//end testNrpsMembershipWrongScopeKeepsThe403TheSpecAssigns()

	// ---------------------------------------------------------------------
	// REQ-LTI-002 — JWKS publish
	// ---------------------------------------------------------------------

	/**
	 * The JWKS endpoint publishes only what the key service hands it, for the
	 * registration named in the route. This is the document remote parties pin
	 * signatures against, so both the lookup arguments and the payload matter.
	 *
	 * @return void
	 */
	public function testJwksPublishesTheKeyServiceDocumentForTheRoutedRegistration(): void {
		$jwks = ['keys' => [['kid' => 'key-1', 'kty' => 'RSA']]];

		$this->keyService->expects($this->once())
			->method('getPublishableJwks')
			->with($this->identicalTo('lti_platform'), $this->identicalTo('registration-1'))
			->willReturn($jwks);

		$response = $this->controller->jwks('lti_platform', 'registration-1');

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame($jwks, $response->getData());

	}//end testJwksPublishesTheKeyServiceDocumentForTheRoutedRegistration()

	/**
	 * An unknown registration type is a 400 — never a 500 leaking the
	 * underlying throwable, and never an empty `{"keys": []}` document that a
	 * relying party would read as "this registration has no keys".
	 *
	 * @return void
	 */
	public function testJwksUnknownRegistrationTypeIs400NotAnEmptyKeySet(): void {
		$this->keyService->method('getPublishableJwks')
			->willThrowException(new \RuntimeException('no such registration type'));

		$response = $this->controller->jwks('not-a-type', 'registration-1');

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertSame('Unknown registration type', $response->getData()['error']);
		$this->assertArrayNotHasKey('keys', $response->getData());

	}//end testJwksUnknownRegistrationTypeIs400NotAnEmptyKeySet()
}//end class

<?php

/**
 * Tests for LtiController's login initiation, token endpoint, inbound AGS
 * score and the key administration endpoints.
 *
 * Pins what each answers to a valid request, to a rejection from its service
 * (the rejection's own status and details), and to any other failure; and
 * that a request without a bearer token or with a malformed grant never
 * reaches its service. The services and the request are doubles.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 *
 * @spec openspec/specs/lti-platform/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Controller;

use OCA\Integriq\Controller\LtiController;
use OCA\Integriq\Exception\LtiValidationException;
use OCA\Integriq\Service\Lti\LtiAgsServiceInterface;
use OCA\Integriq\Service\Lti\LtiKeyServiceInterface;
use OCA\Integriq\Service\Lti\LtiLaunchServiceInterface;
use OCA\Integriq\Service\Lti\LtiNrpsServiceInterface;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\RedirectResponse;
use OCP\IRequest;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * @covers \OCA\Integriq\Controller\LtiController
 * @uses   \OCA\Integriq\Controller\BearerToken
 * @uses   \OCA\Integriq\Exception\LtiValidationException
 * @uses   \OCA\Integriq\Exception\AuthenticationException
 */
class LtiControllerKeysAndTokenTest extends TestCase {

	/**
	 * The request parameters.
	 *
	 * @var array<string, mixed>
	 */
	private array $params = [];

	/**
	 * The Authorization header.
	 *
	 * @var string
	 */
	private string $authorization = '';

	/**
	 * The launch service.
	 *
	 * @var LtiLaunchServiceInterface&MockObject
	 */
	private LtiLaunchServiceInterface&MockObject $launch;

	/**
	 * The AGS service.
	 *
	 * @var LtiAgsServiceInterface&MockObject
	 */
	private LtiAgsServiceInterface&MockObject $ags;

	/**
	 * The key service.
	 *
	 * @var LtiKeyServiceInterface&MockObject
	 */
	private LtiKeyServiceInterface&MockObject $keys;

	/**
	 * The controller under test.
	 *
	 * @var LtiController
	 */
	private LtiController $controller;

	/**
	 * Build the controller over doubles.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->params        = [];
		$this->authorization = '';

		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturnCallback(
			fn (string $key, mixed $default = null): mixed => ($this->params[$key] ?? $default)
		);
		$request->method('getParams')->willReturnCallback(fn (): array => $this->params);
		$request->method('getHeader')->willReturnCallback(fn (string $name): string => ($name === 'Authorization' ? $this->authorization : ''));
		$request->method('getServerProtocol')->willReturn('https');
		$request->method('getServerHost')->willReturn('nc.example.org');

		$this->launch = $this->createMock(LtiLaunchServiceInterface::class);
		$this->ags    = $this->createMock(LtiAgsServiceInterface::class);
		$this->keys   = $this->createMock(LtiKeyServiceInterface::class);

		$this->controller = new LtiController(
			'integriq',
			$request,
			$this->launch,
			$this->ags,
			$this->createMock(LtiNrpsServiceInterface::class),
			$this->keys,
			$this->createMock(LoggerInterface::class)
		);

	}//end setUp()

	public function testLoginRedirectsToThePlatformWithTheStateCookie(): void {
		$this->params = ['iss' => 'https://lms.example.org', 'login_hint' => 'u1'];
		$this->launch->expects($this->once())
			->method('initiateLogin')
			->with('dep-1', $this->params, 'https://nc.example.org/index.php/apps/integriq/api/lti/dep-1/launch')
			->willReturn(['redirectUrl' => 'https://lms.example.org/auth?state=s1', 'state' => 's1']);

		$response = $this->controller->login('dep-1');

		$this->assertInstanceOf(RedirectResponse::class, $response);
		$this->assertSame('https://lms.example.org/auth?state=s1', $response->getRedirectURL());
		$cookie = $response->getCookies()['oc_lti_state_dep-1'];
		$this->assertSame('s1', $cookie['value']);
		$this->assertSame('None', $cookie['sameSite']);

	}//end testLoginRedirectsToThePlatformWithTheStateCookie()

	public function testALoginTheServiceRejectsAnswersItsStatusAndDetails(): void {
		$this->launch->method('initiateLogin')->willThrowException(
			new LtiValidationException(message: 'Unknown deployment', details: ['deployment' => 'dep-x'], httpStatus: 404)
		);

		$response = $this->controller->login('dep-x');

		$this->assertSame(404, $response->getStatus());
		$this->assertSame(['error' => 'Unknown deployment', 'details' => ['deployment' => 'dep-x']], $response->getData());

	}//end testALoginTheServiceRejectsAnswersItsStatusAndDetails()

	/**
	 * A token request with the wrong grant, assertion type or no assertion is
	 * a 400 and never reaches the AGS service.
	 *
	 * @return void
	 */
	public function testAMalformedTokenRequestIsRefusedBeforeTheService(): void {
		$this->ags->expects($this->never())->method('issueAccessToken');
		$valid = [
			'grant_type' => 'client_credentials',
			'client_assertion_type' => 'urn:ietf:params:oauth:client-assertion-type:jwt-bearer',
			'client_assertion' => 'jwt',
		];

		foreach (['grant_type' => 'password', 'client_assertion_type' => 'other', 'client_assertion' => ''] as $key => $bad) {
			$this->params = [$key => $bad] + $valid;
			$response     = $this->controller->token();
			$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus(), $key);
			$this->assertSame(['error' => 'Invalid token request', 'details' => []], $response->getData(), $key);
		}

	}//end testAMalformedTokenRequestIsRefusedBeforeTheService()

	public function testATokenIsIssuedForTheScopeAndOnlyNarrowedWhenADeploymentIsNamed(): void {
		$base = [
			'grant_type' => 'client_credentials',
			'client_assertion_type' => 'urn:ietf:params:oauth:client-assertion-type:jwt-bearer',
			'client_assertion' => 'jwt',
			'scope' => 'score',
		];
		$this->ags->expects($this->exactly(2))
			->method('issueAccessToken')
			->willReturnCallback(
				fn (string $clientAssertion, string $requestedScope, ?string $deploymentUuid): array => ['access_token' => 't', 'for' => $deploymentUuid, 'scope' => $requestedScope]
			);

		$this->params = $base;
		$this->assertSame(['access_token' => 't', 'for' => null, 'scope' => 'score'], $this->controller->token()->getData());

		$this->params = $base + ['deployment_id' => 'dep-2'];
		$this->assertSame(['access_token' => 't', 'for' => 'dep-2', 'scope' => 'score'], $this->controller->token()->getData());

	}//end testATokenIsIssuedForTheScopeAndOnlyNarrowedWhenADeploymentIsNamed()

	public function testATokenTheServiceRejectsAnswersItsStatus(): void {
		$this->params = [
			'grant_type' => 'client_credentials',
			'client_assertion_type' => 'urn:ietf:params:oauth:client-assertion-type:jwt-bearer',
			'client_assertion' => 'jwt',
		];
		$this->ags->method('issueAccessToken')->willThrowException(new LtiValidationException(message: 'Bad signature'));

		$response = $this->controller->token();

		$this->assertSame(401, $response->getStatus());
		$this->assertSame('Bad signature', $response->getData()['error']);

	}//end testATokenTheServiceRejectsAnswersItsStatus()

	public function testAScoreWithoutABearerTokenIs401BeforeTheService(): void {
		$this->ags->expects($this->never())->method('receiveScore');

		foreach (['', 'Basic abc', 'Bearer   '] as $header) {
			$this->authorization = $header;
			$response            = $this->controller->agsScore('dep-1', 'li-1');
			$this->assertSame(401, $response->getStatus(), $header);
			$this->assertSame(['error' => 'Missing bearer token', 'details' => []], $response->getData(), $header);
		}

	}//end testAScoreWithoutABearerTokenIs401BeforeTheService()

	public function testAScoreIsReceivedWithTheTokenAndThePayload(): void {
		$this->authorization = 'Bearer tok-1';
		$this->params        = ['userId' => 'u1', 'scoreGiven' => 8];
		$this->ags->expects($this->once())
			->method('receiveScore')
			->with('tok-1', 'dep-1', 'li-1', ['userId' => 'u1', 'scoreGiven' => 8])
			->willReturn(['stored' => true]);

		$this->assertSame(['stored' => true], $this->controller->agsScore('dep-1', 'li-1')->getData());

	}//end testAScoreIsReceivedWithTheTokenAndThePayload()

	public function testAScoreTheServiceRejectsAnswersItsStatus(): void {
		$this->authorization = 'Bearer tok-1';
		$this->ags->method('receiveScore')->willThrowException(new LtiValidationException(message: 'Wrong deployment', httpStatus: 403));

		$this->assertSame(403, $this->controller->agsScore('dep-1', 'li-1')->getStatus());

	}//end testAScoreTheServiceRejectsAnswersItsStatus()

	/**
	 * Generate, rotate, approve and suspend answer the service's entry, its
	 * rejection's status, or 400 with the message of any other failure.
	 *
	 * @return void
	 */
	public function testTheKeyAdministrationEndpoints(): void {
		$cases = [
			'generateKey' => fn () => $this->controller->generateKey('tool', 'r1'),
			'rotateKey' => fn () => $this->controller->rotateKey('tool', 'r1'),
			'approve' => fn () => $this->controller->approve('tool', 'r1'),
			'suspend' => fn () => $this->controller->suspend('tool', 'r1'),
		];

		foreach ($cases as $method => $call) {
			$keys = $this->createMock(LtiKeyServiceInterface::class);
			$keys->expects($this->exactly(3))
				->method($method)
				->with('tool', 'r1')
				->willReturnOnConsecutiveCalls(
					['kid' => 'k1', 'method' => $method],
					$this->throwException(new LtiValidationException(message: 'Not approvable', httpStatus: 409)),
					$this->throwException(new RuntimeException('Unknown registration'))
				);
			$this->swapKeyService($keys);

			$this->assertSame(['kid' => 'k1', 'method' => $method], $call()->getData(), $method);
			$this->assertSame(409, $call()->getStatus(), $method);
			$failed = $call();
			$this->assertSame(Http::STATUS_BAD_REQUEST, $failed->getStatus(), $method);
			$this->assertSame(['error' => 'Unknown registration'], $failed->getData(), $method);
		}

	}//end testTheKeyAdministrationEndpoints()

	public function testJwksForAnUnknownTypeIs400WithoutTheCause(): void {
		$this->keys->method('getPublishableJwks')->willThrowException(new RuntimeException('no such table'));

		$response = $this->controller->jwks('bogus', 'r1');

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertSame(['error' => 'Unknown registration type'], $response->getData());

	}//end testJwksForAnUnknownTypeIs400WithoutTheCause()

	/**
	 * Rebuild the controller around another key service.
	 *
	 * @param LtiKeyServiceInterface $keys The key service.
	 *
	 * @return void
	 */
	private function swapKeyService(LtiKeyServiceInterface $keys): void {
		$request = $this->createMock(IRequest::class);
		$this->controller = new LtiController(
			'integriq',
			$request,
			$this->launch,
			$this->ags,
			$this->createMock(LtiNrpsServiceInterface::class),
			$keys,
			$this->createMock(LoggerInterface::class)
		);

	}//end swapKeyService()
}//end class

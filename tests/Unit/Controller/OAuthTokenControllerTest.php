<?php

/**
 * OAuthTokenController maps the token issuer and issuer key service onto HTTP.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/access-oauth-and-token-validation/specs/authorization-jwt/spec.md#requirement-integriq-issues-client-credentials-tokens-to-consumers-req-tokv-003
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Controller;

use OCA\Integriq\Controller\OAuthTokenController;
use OCA\Integriq\Service\OAuth\IssuerKeyService;
use OCA\Integriq\Service\OAuth\OAuthError;
use OCA\Integriq\Service\OAuth\TokenIssuer;
use OCP\AppFramework\Http\Attribute\AnonRateLimit;
use OCP\AppFramework\Http\Attribute\AuthorizedAdminSetting;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Http\Response;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use ReflectionProperty;
use RuntimeException;

/**
 * The token endpoint, the key set and the two admin acts.
 *
 * @covers \OCA\Integriq\Controller\OAuthTokenController
 * @uses   \OCA\Integriq\Service\OAuth\OAuthError
 */
final class OAuthTokenControllerTest extends TestCase {

	/**
	 * The token issuer.
	 *
	 * @var TokenIssuer&MockObject
	 */
	private TokenIssuer&MockObject $issuer;

	/**
	 * The issuer key service.
	 *
	 * @var IssuerKeyService&MockObject
	 */
	private IssuerKeyService&MockObject $issuerKey;

	/**
	 * The user session.
	 *
	 * @var IUserSession&MockObject
	 */
	private IUserSession&MockObject $userSession;

	/**
	 * Fresh mocks for every test.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->issuer      = $this->createMock(TokenIssuer::class);
		$this->issuerKey   = $this->createMock(IssuerKeyService::class);
		$this->userSession = $this->createMock(IUserSession::class);
	}//end setUp()

	/**
	 * A controller over a request with these parameters and this Authorization header.
	 *
	 * @param array<string, mixed> $params        The request parameters.
	 * @param string               $authorization The Authorization header.
	 *
	 * @return OAuthTokenController
	 */
	private function controller(array $params = [], string $authorization = ''): OAuthTokenController {
		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturnCallback(static fn (string $key, mixed $default = null): mixed => ($params[$key] ?? $default));
		$request->method('getHeader')->willReturnCallback(static fn (string $name): string => ($name === 'Authorization') ? $authorization : '');

		return new OAuthTokenController(appName: 'integriq', request: $request, issuer: $this->issuer, issuerKey: $this->issuerKey, userSession: $this->userSession);
	}//end controller()

	/**
	 * The headers a response carries.
	 *
	 * @param Response $response The response.
	 *
	 * @return array<string, string>
	 */
	private static function headers(Response $response): array {
		return (new ReflectionProperty(Response::class, 'headers'))->getValue($response);
	}//end headers()

	/**
	 * HTTP Basic credentials are url-decoded and passed on with the grant, scope and resource; the answer is no-store.
	 *
	 * @return void
	 */
	public function testBasicCredentialsAreDecodedAndTheGrantIsNoStore(): void {
		$this->issuer->expects($this->once())->method('issue')->with(
			'client_credentials',
			'c:partner',
			's3cr et',
			'zaken:read',
			'https://cloud.example.nl/apps/integriq/api/endpoints/zaken'
		)->willReturn(['access_token' => 'jwt', 'token_type' => 'Bearer']);

		$response = $this->controller(
			['grant_type' => 'client_credentials', 'scope' => 'zaken:read', 'resource' => 'https://cloud.example.nl/apps/integriq/api/endpoints/zaken'],
			'Basic ' . base64_encode(urlencode('c:partner') . ':' . urlencode('s3cr et'))
		)->token();

		$this->assertSame(200, $response->getStatus());
		$this->assertSame(['access_token' => 'jwt', 'token_type' => 'Bearer'], $response->getData());
		$headers = self::headers(response: $response);
		$this->assertSame('no-store', $headers['Cache-Control']);
		$this->assertSame('no-cache', $headers['Pragma']);
	}//end testBasicCredentialsAreDecodedAndTheGrantIsNoStore()

	/**
	 * Without a Basic header the body credentials are used; a blank scope and resource are passed as null.
	 *
	 * @return void
	 */
	public function testBodyCredentialsAndBlankOptionalsBecomeNull(): void {
		$this->issuer->expects($this->once())->method('issue')->with('client_credentials', 'c-partner', 'secret', null, null)->willReturn([]);

		$this->controller(['grant_type' => 'client_credentials', 'client_id' => 'c-partner', 'client_secret' => 'secret', 'scope' => '  ', 'resource' => ['x']])->token();
	}//end testBodyCredentialsAndBlankOptionalsBecomeNull()

	/**
	 * A malformed Basic header falls back to the body credentials.
	 *
	 * @return void
	 */
	public function testAMalformedBasicHeaderFallsBackToTheBody(): void {
		$this->issuer->expects($this->once())->method('issue')->with('client_credentials', 'body-id', 'body-secret', null, null)->willReturn([]);

		$this->controller(['grant_type' => 'client_credentials', 'client_id' => 'body-id', 'client_secret' => 'body-secret'], 'Basic ' . base64_encode('no-colon'))->token();
	}//end testAMalformedBasicHeaderFallsBackToTheBody()

	/**
	 * An invalid client is a 401 with the RFC 6749 body, a Basic challenge and no-store.
	 *
	 * @return void
	 */
	public function testAnInvalidClientIsA401WithAChallenge(): void {
		$this->issuer->method('issue')->willThrowException(new OAuthError(error: 'invalid_client', description: 'Unknown client', status: 401));

		$response = $this->controller(['grant_type' => 'client_credentials'])->token();

		$this->assertSame(401, $response->getStatus());
		$this->assertSame(['error' => 'invalid_client', 'error_description' => 'Unknown client'], $response->getData());
		$headers = self::headers(response: $response);
		$this->assertSame('Basic realm="integriq"', $headers['WWW-Authenticate']);
		$this->assertSame('no-store', $headers['Cache-Control']);
	}//end testAnInvalidClientIsA401WithAChallenge()

	/**
	 * Any other refusal keeps its own status and carries no challenge.
	 *
	 * @return void
	 */
	public function testOtherRefusalsCarryNoChallenge(): void {
		$this->issuer->method('issue')->willThrowException(new OAuthError(error: 'invalid_scope', description: 'Not held', status: 400));

		$response = $this->controller(['grant_type' => 'client_credentials'])->token();

		$this->assertSame(400, $response->getStatus());
		$this->assertSame('invalid_scope', $response->getData()['error']);
		$this->assertArrayNotHasKey('WWW-Authenticate', self::headers(response: $response));
	}//end testOtherRefusalsCarryNoChallenge()

	/**
	 * The key set is the issuer key service's.
	 *
	 * @return void
	 */
	public function testJwksReturnsThePublishedKeySet(): void {
		$this->issuerKey->method('jwks')->willReturn(['keys' => [['kid' => 'k1']]]);

		$response = $this->controller()->jwks();

		$this->assertInstanceOf(JSONResponse::class, $response);
		$this->assertSame(['keys' => [['kid' => 'k1']]], $response->getData());
	}//end testJwksReturnsThePublishedKeySet()

	/**
	 * The public endpoints are rate limited for anonymous callers; the admin acts are not public.
	 *
	 * @return void
	 */
	public function testPublicEndpointsAreRateLimitedAndAdminActsAreNot(): void {
		foreach (['token', 'jwks'] as $method) {
			$reflection = new ReflectionMethod(OAuthTokenController::class, $method);
			$this->assertCount(1, $reflection->getAttributes(PublicPage::class), $method);
			$this->assertCount(1, $reflection->getAttributes(AnonRateLimit::class), $method);
		}

		foreach (['createIssuerKey', 'clientSecret'] as $method) {
			$reflection = new ReflectionMethod(OAuthTokenController::class, $method);
			$this->assertCount(0, $reflection->getAttributes(PublicPage::class), $method);
			$this->assertCount(1, $reflection->getAttributes(AuthorizedAdminSetting::class), $method);
		}
	}//end testPublicEndpointsAreRateLimitedAndAdminActsAreNot()

	/**
	 * Creating the issuer key names the signed-in admin as owner and answers 201 with the public key.
	 *
	 * @return void
	 */
	public function testCreateIssuerKeyAnswers201WithThePublicKey(): void {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('admin');
		$this->userSession->method('getUser')->willReturn($user);
		$this->issuerKey->expects($this->once())->method('create')->with('admin')->willReturn(['kid' => 'k1', 'kty' => 'RSA']);

		$response = $this->controller()->createIssuerKey();

		$this->assertSame(201, $response->getStatus());
		$this->assertSame(['kid' => 'k1', 'kty' => 'RSA'], $response->getData());
	}//end testCreateIssuerKeyAnswers201WithThePublicKey()

	/**
	 * A key that cannot be created is a 503 with the reason.
	 *
	 * @return void
	 */
	public function testCreateIssuerKeyFailureIsA503(): void {
		$this->issuerKey->expects($this->once())->method('create')->with('')->willThrowException(new RuntimeException('No default organisation to hold the issuer key.'));

		$response = $this->controller()->createIssuerKey();

		$this->assertSame(503, $response->getStatus());
		$this->assertSame(['message' => 'No default organisation to hold the issuer key.'], $response->getData());
	}//end testCreateIssuerKeyFailureIsA503()

	/**
	 * A new client secret is shown once, with 201 and no-store.
	 *
	 * @return void
	 */
	public function testClientSecretIsShownOnceWithNoStore(): void {
		$this->issuer->expects($this->once())->method('setClientSecret')->with('c-partner')->willReturn('abc123');

		$response = $this->controller()->clientSecret(id: 'c-partner');

		$this->assertSame(201, $response->getStatus());
		$this->assertSame(['clientId' => 'c-partner', 'clientSecret' => 'abc123'], $response->getData());
		$this->assertSame('no-store', self::headers(response: $response)['Cache-Control']);
	}//end testClientSecretIsShownOnceWithNoStore()

	/**
	 * A consumer that is not a client credentials consumer is a 404.
	 *
	 * @return void
	 */
	public function testClientSecretOfAnUnknownConsumerIsA404(): void {
		$this->issuer->method('setClientSecret')->willReturn(null);

		$response = $this->controller()->clientSecret(id: 'c-gone');

		$this->assertSame(404, $response->getStatus());
		$this->assertSame(['message' => 'No client credentials consumer with this id'], $response->getData());
	}//end testClientSecretOfAnUnknownConsumerIsA404()
}//end class

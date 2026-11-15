<?php

/**
 * Unit tests for the token requests AuthenticationService sends: the OAuth
 * client-credentials and password grants, the JWT-bearer client assertion
 * and the Decos token call.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @spec openspec/specs/authentication-twig/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Service;

use GuzzleHttp\Exception\ConnectException;
use Jose\Component\Core\AlgorithmManager;
use Jose\Component\KeyManagement\JWKFactory;
use Jose\Component\Signature\Algorithm\PS256;
use Jose\Component\Signature\JWSVerifier;
use Jose\Component\Signature\Serializer\CompactSerializer;
use OCA\Integriq\Service\AuthenticationService;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;
use Twig\Loader\ArrayLoader;

/**
 * The service builds its own HTTP client, so each request is observed on the
 * way out: the token host is in the reserved `.invalid` TLD, the connection
 * fails at name resolution, and the ConnectException carries the exact
 * request that would have been sent. Proxy variables are cleared for the
 * duration so the request never leaves this machine.
 *
 * @covers \OCA\Integriq\Service\AuthenticationService
 * @uses   \OCA\Integriq\Service\PemKeyFile
 * @uses   \OCA\Integriq\Service\PemKeyFileRequest
 */
class AuthenticationServiceCoverageTest extends TestCase {

	/**
	 * A token endpoint that passes the SSRF guard but never resolves.
	 *
	 * @var string
	 */
	private const TOKEN_URL = 'https://token.invalid/oauth/token';

	/**
	 * The proxy variables cleared for each test.
	 *
	 * @var list<string>
	 */
	private const PROXY_VARIABLES = ['HTTPS_PROXY', 'https_proxy', 'HTTP_PROXY', 'http_proxy', 'ALL_PROXY', 'all_proxy'];

	/**
	 * The proxy variables as they were, to restore afterwards.
	 *
	 * @var array<string,array{env: string|false, server: mixed}>
	 */
	private array $savedProxy = [];

	/**
	 * The service under test.
	 *
	 * @var AuthenticationService
	 */
	private AuthenticationService $service;

	/**
	 * Build the service and take proxies out of the way.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		foreach (self::PROXY_VARIABLES as $name) {
			$this->savedProxy[$name] = ['env' => getenv($name), 'server' => ($_SERVER[$name] ?? null)];
			putenv($name);
			unset($_SERVER[$name]);
		}

		$this->service = new AuthenticationService(new ArrayLoader([]));
	}//end setUp()

	/**
	 * Put the proxy variables back.
	 *
	 * @return void
	 */
	protected function tearDown(): void {
		foreach ($this->savedProxy as $name => $saved) {
			if ($saved['env'] !== false) {
				putenv($name . '=' . $saved['env']);
			}

			if ($saved['server'] !== null) {
				$_SERVER[$name] = $saved['server'];
			}
		}

		parent::tearDown();
	}//end tearDown()

	/**
	 * The request a token call sends, taken from the connection failure.
	 *
	 * @param callable $call The token call.
	 *
	 * @return RequestInterface The request.
	 */
	private function sentRequest(callable $call): RequestInterface {
		try {
			$call();
		} catch (ConnectException $exception) {
			return $exception->getRequest();
		}

		$this->fail('The token call did not reach the HTTP client.');
	}//end sentRequest()

	/**
	 * The form fields of a request body.
	 *
	 * @param RequestInterface $request The request.
	 *
	 * @return array<string,string> The fields.
	 */
	private static function form(RequestInterface $request): array {
		parse_str((string)$request->getBody(), $fields);
		return $fields;
	}//end form()

	/**
	 * A client-credentials grant authenticated in the body POSTs the client
	 * id and secret as form fields to the token URL.
	 *
	 * @return void
	 */
	public function testClientCredentialsInTheBodyArePostedAsFormFields(): void {
		$request = $this->sentRequest(
			fn () => $this->service->fetchOAuthTokens(
				configuration: [
					'grant_type' => 'client_credentials',
					'tokenUrl' => self::TOKEN_URL,
					'scope' => 'api',
					'authentication' => 'body',
					'client_id' => 'client-1',
					'client_secret' => 's3cret',
				]
			)
		);

		$this->assertSame('POST', $request->getMethod());
		$this->assertSame(self::TOKEN_URL, (string)$request->getUri());
		$this->assertSame('application/x-www-form-urlencoded', $request->getHeaderLine('Content-Type'));
		$this->assertSame(
			['grant_type' => 'client_credentials', 'scope' => 'api', 'client_id' => 'client-1', 'client_secret' => 's3cret'],
			self::form(request: $request)
		);
		$this->assertFalse($request->hasHeader('Authorization'));
	}//end testClientCredentialsInTheBodyArePostedAsFormFields()

	/**
	 * A client-credentials grant with basic auth keeps the client id and
	 * secret out of the form and authenticates with a Basic header.
	 *
	 * @return void
	 */
	public function testClientCredentialsWithBasicAuthUseTheAuthorizationHeader(): void {
		$request = $this->sentRequest(
			fn () => $this->service->fetchOAuthTokens(
				configuration: [
					'grant_type' => 'client_credentials',
					'tokenUrl' => self::TOKEN_URL,
					'scope' => 'api',
					'authentication' => 'basic_auth',
					'client_id' => 'client-1',
					'client_secret' => 's3cret',
				]
			)
		);

		// The credentials are kept out of the form and an Authorization header
		// is sent. Its VALUE is not asserted: the service passes Guzzle's `auth`
		// option as ['username' => .., 'password' => ..] where Guzzle reads a
		// list [0, 1], so today the header carries empty credentials ("Basic Og==").
		// Reported as a production bug rather than pinned here.
		$this->assertTrue($request->hasHeader('Authorization'));
		$this->assertStringStartsWith('Basic ', $request->getHeaderLine('Authorization'));
		$this->assertSame(['grant_type' => 'client_credentials', 'scope' => 'api'], self::form(request: $request));
	}//end testClientCredentialsWithBasicAuthUseTheAuthorizationHeader()

	/**
	 * A JWT-bearer client assertion is a PS256 token signed with the
	 * configured private key, naming the thumbprint and carrying the payload.
	 *
	 * @return void
	 */
	public function testAJwtBearerClientAssertionIsSignedWithThePrivateKey(): void {
		$key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
		$this->assertNotFalse($key);
		openssl_pkey_export($key, $pem);
		$public = openssl_pkey_get_details($key)['key'];
		$bearer = 'urn:ietf:params:oauth:client-assertion-type:jwt-bearer';

		$request = $this->sentRequest(
			fn () => $this->service->fetchOAuthTokens(
				configuration: [
					'grant_type' => 'client_credentials',
					'tokenUrl' => self::TOKEN_URL,
					'scope' => 'api',
					'authentication' => 'none',
					'client_id' => 'client-1',
					'client_secret' => 'unused',
					'client_assertion_type' => $bearer,
					'private_key' => base64_encode($pem),
					'x5t' => 'dGh1bWJwcmludA',
					'payload' => '{"iss": "client-1", "aud": "https://token.invalid"}',
				]
			)
		);

		$form = self::form(request: $request);
		$this->assertSame(['grant_type', 'scope', 'client_assertion_type', 'client_assertion'], array_keys($form));
		$this->assertSame($bearer, $form['client_assertion_type']);

		$jws = (new CompactSerializer())->unserialize($form['client_assertion']);
		$this->assertSame(
			['alg' => 'PS256', 'typ' => 'JWT', 'x5t' => 'dGh1bWJwcmludA'],
			$jws->getSignature(0)->getProtectedHeader()
		);
		$this->assertSame(['iss' => 'client-1', 'aud' => 'https://token.invalid'], json_decode($jws->getPayload(), true));
		$verifier = new JWSVerifier(new AlgorithmManager([new PS256()]));
		$this->assertTrue($verifier->verifyWithKey($jws, JWKFactory::createFromKey($public), 0));
	}//end testAJwtBearerClientAssertionIsSignedWithThePrivateKey()

	/**
	 * A password grant authenticated in the body posts the user's credentials as form fields.
	 *
	 * @return void
	 */
	public function testAPasswordGrantPostsTheUserCredentials(): void {
		$request = $this->sentRequest(
			fn () => $this->service->fetchOAuthTokens(
				configuration: [
					'grant_type' => 'password',
					'tokenUrl' => self::TOKEN_URL,
					'scope' => 'openid',
					'authentication' => 'body',
					'username' => 'alice',
					'password' => 'pa ss',
				]
			)
		);

		$this->assertSame(
			['grant_type' => 'password', 'scope' => 'openid', 'username' => 'alice', 'password' => 'pa ss'],
			self::form(request: $request)
		);
	}//end testAPasswordGrantPostsTheUserCredentials()

	/**
	 * A password grant with basic auth keeps the user's credentials out of the form and sends a Basic header.
	 *
	 * @return void
	 */
	public function testAPasswordGrantWithBasicAuthUsesTheAuthorizationHeader(): void {
		$request = $this->sentRequest(
			fn () => $this->service->fetchOAuthTokens(
				configuration: [
					'grant_type' => 'password',
					'tokenUrl' => self::TOKEN_URL,
					'scope' => 'openid',
					'authentication' => 'basic_auth',
					'username' => 'alice',
					'password' => 'secret',
				]
			)
		);

		// The credentials are kept out of the form and an Authorization header
		// is sent. Its VALUE is not asserted: the service passes Guzzle's `auth`
		// option as ['username' => .., 'password' => ..] where Guzzle reads a
		// list [0, 1], so today the header carries empty credentials ("Basic Og==").
		// Reported as a production bug rather than pinned here.
		$this->assertTrue($request->hasHeader('Authorization'));
		$this->assertStringStartsWith('Basic ', $request->getHeaderLine('Authorization'));
		$this->assertSame(['grant_type' => 'password', 'scope' => 'openid'], self::form(request: $request));
	}//end testAPasswordGrantWithBasicAuthUsesTheAuthorizationHeader()

	/**
	 * A Decos token call POSTs the source configuration as JSON to the token
	 * URL, with the token URL itself left out of the body.
	 *
	 * @return void
	 */
	public function testADecosTokenCallPostsTheConfigurationAsJson(): void {
		$request = $this->sentRequest(
			fn () => $this->service->fetchDecosToken(
				configuration: [
					'tokenUrl' => self::TOKEN_URL,
					'tokenLocation' => 'token',
					'username' => 'decos-user',
					'password' => 'decos-pass',
				]
			)
		);

		$this->assertSame('POST', $request->getMethod());
		$this->assertSame(self::TOKEN_URL, (string)$request->getUri());
		$this->assertSame('application/json', $request->getHeaderLine('Content-Type'));
		$this->assertSame(
			['tokenLocation' => 'token', 'username' => 'decos-user', 'password' => 'decos-pass'],
			json_decode((string)$request->getBody(), true)
		);
	}//end testADecosTokenCallPostsTheConfigurationAsJson()
}//end class

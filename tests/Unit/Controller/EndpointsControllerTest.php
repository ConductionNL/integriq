<?php

/**
 * Unit tests for EndpointsController's CORS preflight endpoint.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Controller;

use OCA\Integriq\Controller\EndpointsController;
use OCA\Integriq\Service\Consumer\OpenRegisterCredentialBridge;
use OCA\Integriq\Service\EndpointCacheService;
use OCA\Integriq\Service\EndpointCorsPolicy;
use OCA\Integriq\Service\EndpointService;
use OCA\Integriq\Service\ObjectService;
use OCA\OpenRegister\Db\ObjectEntity;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Http\Response;
use OCP\IConfig;
use OCP\IL10N;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Wire-contract tests for OPTIONS on the generic endpoint surface.
 *
 * `preflightedCors()` is `#[PublicPage] #[NoCSRFRequired]` — it answers before
 * any authentication runs, on every endpoint the app publishes. Its headers
 * therefore ARE the security posture of the whole endpoint runtime for
 * cross-origin callers, which is why they are asserted individually rather
 * than as "a Response came back".
 */
class EndpointsControllerTest extends TestCase {

	/**
	 * Build the controller with a request reporting the given server vars.
	 *
	 * NEVER HAND-IMPLEMENT `IRequest` HERE. The first version of this file
	 * declared a `ServerAwareRequestStub implements IRequest` so that `$server`
	 * could be a real property rather than a dynamic one. It passed locally and
	 * killed every PHPUnit leg in CI with
	 *
	 *   PHP Fatal error: Class ServerAwareRequestStub contains 2 abstract
	 *   methods … (OCP\IRequest::throwDecodingExceptionIfAny,
	 *   OCP\IRequest::getFormat)
	 *
	 * because the two environments do not agree on what `IRequest` IS: locally
	 * the interface comes from the pinned `vendor/nextcloud/ocp` (21 methods),
	 * in CI it comes from the real Nextcloud server the tests run inside (23).
	 * Any hand-written implementation of a framework interface is pinned to one
	 * of those and fatals against the other. A PHPUnit mock reflects whichever
	 * interface is actually loaded, so it is correct in both.
	 *
	 * The cost is that `$server` is a MAGIC property
	 * (`@property-read string[] $server`), so assigning it to a mock creates a
	 * dynamic property and PHP 8.2+ emits a deprecation. That is a notice, not
	 * a failure — `phpunit-unit.xml` sets `failOnDeprecation="false"` — and it
	 * is the right trade against a fatal.
	 *
	 * @param array<string, string> $server The $_SERVER-alike map the request exposes.
	 *
	 * @return EndpointsController
	 */
	private function buildController(array $server): EndpointsController {
		$request = $this->createMock(IRequest::class);
		$request->server = $server;

		return new EndpointsController(
			'integriq',
			$request,
			$this->createMock(EndpointService::class),
			$this->createMock(OpenRegisterCredentialBridge::class),
			$this->createMock(ObjectService::class),
			$this->createMock(EndpointCacheService::class),
			$this->createMock(LoggerInterface::class),
			$this->createMock(IL10N::class),
			new EndpointCorsPolicy($this->createMock(IConfig::class))
		);

	}//end buildController()

	/**
	 * Read a Response's raw, handler-set `headers` property via reflection.
	 *
	 * {@see \OCP\AppFramework\Http\Response::getHeaders()} merges in a live
	 * `\OC::$server`-resolved IRequest, which does not exist in a standalone
	 * unit-test environment. The same helper is used by
	 * EndpointServiceTierPolicyTest for the same reason.
	 *
	 * @param Response $response The response to inspect.
	 *
	 * @return array<string, mixed>
	 */
	private function rawHeaders(Response $response): array {
		$property = new \ReflectionProperty(Response::class, 'headers');
		$property->setAccessible(true);

		return $property->getValue($response);
	}//end rawHeaders()

	/**
	 * The preflight echoes the caller's Origin and advertises the configured
	 * methods, headers and max-age.
	 *
	 * @return void
	 */
	public function testPreflightedCorsEchoesTheOriginAndAdvertisesTheConfiguredPolicy(): void {
		$response = $this->buildController(['HTTP_ORIGIN' => 'https://partner.example.org'])
			->preflightedCors();

		$headers = $this->rawHeaders($response);

		$this->assertSame('https://partner.example.org', $headers['Access-Control-Allow-Origin']);
		$this->assertSame('PUT, POST, GET, DELETE, PATCH', $headers['Access-Control-Allow-Methods']);
		$this->assertSame('Authorization, Content-Type, Accept', $headers['Access-Control-Allow-Headers']);
		$this->assertSame('1728000', $headers['Access-Control-Max-Age']);

	}//end testPreflightedCorsEchoesTheOriginAndAdvertisesTheConfiguredPolicy()

	/**
	 * With no Origin header the policy falls back to the wildcard.
	 *
	 * @return void
	 */
	public function testPreflightedCorsFallsBackToTheWildcardWhenNoOriginIsSent(): void {
		$headers = $this->rawHeaders($this->buildController([])->preflightedCors());

		$this->assertSame('*', $headers['Access-Control-Allow-Origin']);

	}//end testPreflightedCorsFallsBackToTheWildcardWhenNoOriginIsSent()

	/**
	 * THE ASSERTION THAT MATTERS: this preflight reflects an arbitrary,
	 * unauthenticated caller's Origin, so it must never also grant credentials.
	 *
	 * `Allow-Origin: <attacker origin>` together with
	 * `Allow-Credentials: true` lets any site read authenticated responses from
	 * every published endpoint — the endpoint runtime's whole surface. Unlike
	 * UserController's preflight (which pins a concrete origin BECAUSE it sets
	 * credentials true), this one must stay credential-free.
	 *
	 * @return void
	 */
	public function testPreflightedCorsNeverGrantsCredentialsToAReflectedOrigin(): void {
		foreach (['https://evil.example', '*', ''] as $origin) {
			$server = $origin === '' ? [] : ['HTTP_ORIGIN' => $origin];
			$headers = $this->rawHeaders($this->buildController($server)->preflightedCors());

			$this->assertSame(
				'false',
				$headers['Access-Control-Allow-Credentials'],
				'origin "' . $origin . '": a reflected origin with Allow-Credentials:true exposes every '
				. 'authenticated endpoint response to any site the user visits'
			);
		}

	}//end testPreflightedCorsNeverGrantsCredentialsToAReflectedOrigin()

	/**
	 * The preflight is a bare Response — no body, 200.
	 *
	 * @return void
	 */
	public function testPreflightedCorsReturnsAnEmptyOkResponse(): void {
		$response = $this->buildController(['HTTP_ORIGIN' => 'https://partner.example.org'])
			->preflightedCors();

		$this->assertInstanceOf(Response::class, $response);
		$this->assertSame(200, $response->getStatus());

	}//end testPreflightedCorsReturnsAnEmptyOkResponse()

	/**
	 * DECISIONS row 39: the ORI endpoints' CORS values, as the seed declares them.
	 *
	 * @var array<string, mixed>
	 */
	private const ORI_CORS = [
		'allowedOrigin' => 'self',
		'allowedMethods' => ['GET', 'OPTIONS'],
		'allowedHeaders' => ['Authorization', 'Content-Type', 'X-Requested-With'],
	];

	/**
	 * Build the controller around one endpoint the cache finds, with the real CORS
	 * policy.
	 *
	 * @param array<string, mixed>  $endpointData The endpoint the path resolves to.
	 * @param array<string, string> $server       The $_SERVER-alike map the request exposes.
	 * @param string                $method       The request method.
	 * @param JSONResponse|null     $served       What EndpointService answers, if called.
	 *
	 * @return EndpointsController
	 */
	private function controllerFor(array $endpointData, array $server, string $method, ?JSONResponse $served=null): EndpointsController {
		$request = $this->createMock(IRequest::class);
		$request->server = $server;
		$request->method('getMethod')->willReturn($method);
		$request->method('getHeader')->willReturnCallback(
			fn (string $name) => ($name === 'Access-Control-Request-Method' ? 'GET' : '')
		);

		$endpoint = new ObjectEntity();
		$endpoint->setObject($endpointData);
		$cache = $this->createMock(EndpointCacheService::class);
		$cache->method('findByPathRegex')->willReturnCallback(
			function (string $path, string $method) use ($endpoint) {
				$this->assertSame('GET', $method, 'A preflight looks up the endpoint for the method it asks about.');
				return $path === 'ori-parity/v1/motions' ? $endpoint : null;
			}
		);

		$endpointService = $this->createMock(EndpointService::class);
		$endpointService->method('handleRequest')->willReturn($served ?? new JSONResponse(['items' => []]));

		$config = $this->createMock(IConfig::class);
		$config->method('getSystemValueString')->willReturnCallback(
			fn (string $key, string $default='') => ($key === 'overwrite.cli.url' ? 'https://raad.example.nl/index.php' : $default)
		);

		// AuthorizationService::corsAfterController() reads Response::getHeaders(),
		// which needs a live \OC server; this double does what it does for a
		// credential-free answer: echo the caller's origin.
		$authorization = $this->createMock(OpenRegisterCredentialBridge::class);
		$authorization->method('corsAfterController')->willReturnCallback(
			function (IRequest $request, Response $response) use ($server) {
				if (isset($server['HTTP_ORIGIN']) === true) {
					$response->addHeader('Access-Control-Allow-Origin', $server['HTTP_ORIGIN']);
				}

				return $response;
			}
		);

		return new EndpointsController(
			'integriq',
			$request,
			$endpointService,
			$authorization,
			$this->createMock(ObjectService::class),
			$cache,
			$this->createMock(LoggerInterface::class),
			$this->createMock(IL10N::class),
			new EndpointCorsPolicy($config)
		);

	}//end controllerFor()

	/**
	 * TC-13, REQ-EP-014: the preflight of an endpoint that declares its own
	 * CORS policy answers that policy, not the echo of the caller's origin.
	 *
	 * Red before: every preflight echoed the caller's origin and allowed
	 * PUT, POST, GET, DELETE and PATCH.
	 *
	 * @return void
	 */
	public function testAPreflightAnswersTheEndpointsOwnCorsPolicy(): void {
		$endpoint = ['method' => 'GET', 'cors' => self::ORI_CORS];
		$headers = $this->rawHeaders(
			$this->controllerFor($endpoint, ['HTTP_ORIGIN' => 'https://evil.example'], 'OPTIONS')
				->preflightedCors('ori-parity/v1/motions')
		);

		$this->assertSame('https://raad.example.nl', $headers['Access-Control-Allow-Origin']);
		$this->assertSame('GET, OPTIONS', $headers['Access-Control-Allow-Methods']);
		$this->assertSame('Authorization, Content-Type, X-Requested-With', $headers['Access-Control-Allow-Headers']);
		$this->assertSame('false', $headers['Access-Control-Allow-Credentials']);

	}//end testAPreflightAnswersTheEndpointsOwnCorsPolicy()

	/**
	 * An endpoint without a CORS policy keeps integriq's preflight unchanged.
	 *
	 * @return void
	 */
	public function testAPreflightForAnEndpointWithoutAPolicyIsUnchanged(): void {
		$headers = $this->rawHeaders(
			$this->controllerFor(['method' => 'GET'], ['HTTP_ORIGIN' => 'https://partner.example.org'], 'OPTIONS')
				->preflightedCors('ori-parity/v1/motions')
		);

		$this->assertSame('https://partner.example.org', $headers['Access-Control-Allow-Origin']);
		$this->assertSame('PUT, POST, GET, DELETE, PATCH', $headers['Access-Control-Allow-Methods']);

		$unknown = $this->rawHeaders(
			$this->controllerFor(['cors' => self::ORI_CORS], ['HTTP_ORIGIN' => 'https://partner.example.org'], 'OPTIONS')
				->preflightedCors('no/such/path')
		);
		$this->assertSame('https://partner.example.org', $unknown['Access-Control-Allow-Origin'], 'No endpoint matched: the default.');

	}//end testAPreflightForAnEndpointWithoutAPolicyIsUnchanged()

	/**
	 * REQ-EP-014: the answer itself carries the endpoint's policy too, after
	 * corsAfterController() echoed the caller's origin.
	 *
	 * @return void
	 */
	public function testAServedAnswerCarriesTheEndpointsOwnCorsPolicy(): void {
		$endpoint = ['method' => 'GET', 'targetType' => 'register/schema', 'cors' => self::ORI_CORS];
		$response = $this->controllerFor($endpoint, ['HTTP_ORIGIN' => 'https://evil.example'], 'GET')
			->handlePath('ori-parity/v1/motions');
		$headers = $this->rawHeaders($response);

		$this->assertSame(200, $response->getStatus());
		$this->assertSame('https://raad.example.nl', $headers['Access-Control-Allow-Origin']);
		$this->assertSame('GET, OPTIONS', $headers['Access-Control-Allow-Methods']);

		$plain = $this->rawHeaders(
			$this->controllerFor(['method' => 'GET', 'targetType' => 'register/schema'], ['HTTP_ORIGIN' => 'https://partner.example.org'], 'GET')
				->handlePath('ori-parity/v1/motions')
		);
		$this->assertSame('https://partner.example.org', $plain['Access-Control-Allow-Origin'], 'Control: without a policy the origin is echoed.');

	}//end testAServedAnswerCarriesTheEndpointsOwnCorsPolicy()

	/**
	 * Whether the fast path would serve this endpoint on a GET.
	 *
	 * @param array $endpointData The endpoint.
	 *
	 * @return boolean True when the fast path serves it.
	 */
	private function takesTheFastPath(array $endpointData): bool {
		$request = $this->createMock(IRequest::class);
		$request->method('getMethod')->willReturn('GET');
		$controller = new EndpointsController(
			'integriq',
			$request,
			$this->createMock(EndpointService::class),
			$this->createMock(OpenRegisterCredentialBridge::class),
			$this->createMock(ObjectService::class),
			$this->createMock(EndpointCacheService::class),
			$this->createMock(LoggerInterface::class),
			$this->createMock(IL10N::class),
			new EndpointCorsPolicy($this->createMock(IConfig::class))
		);
		$endpoint = new \OCA\OpenRegister\Db\ObjectEntity();
		$endpoint->setObject($endpointData);

		$method = new \ReflectionMethod(EndpointsController::class, 'isSimpleEndpoint');
		$method->setAccessible(true);

		return $method->invoke($controller, $endpoint);

	}//end takesTheFastPath()

	/**
	 * REQ-EP-012: a public endpoint with fixed filters never takes the fast
	 * path, which answers a single object without the id-fetch guard.
	 *
	 * Red before: an isPublic endpoint whose only extra was fixedFilters went
	 * the fast path, so /motions/{id} answered an amendment again.
	 *
	 * @return void
	 */
	public function testAnEndpointWithFixedFiltersNeverTakesTheFastPath(): void {
		$endpoint = ['isPublic' => true, 'targetType' => 'register/schema', 'targetId' => '1/2'];
		$this->assertTrue($this->takesTheFastPath($endpoint), 'Control: without fixed filters the fast path serves it.');

		$endpoint['fixedFilters'] = ['decisionType' => 'motion'];
		$this->assertFalse($this->takesTheFastPath($endpoint));

		unset($endpoint['fixedFilters']);
		$endpoint['anonymousRateLimit'] = ['requestsPerWindow' => 120, 'windowSeconds' => 60];
		$this->assertFalse($this->takesTheFastPath($endpoint), 'The fast path would skip the anonymous rate limit too (REQ-EP-013).');

	}//end testAnEndpointWithFixedFiltersNeverTakesTheFastPath()

}//end class

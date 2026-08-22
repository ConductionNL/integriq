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
use OCA\Integriq\Service\AuthorizationService;
use OCA\Integriq\Service\EndpointCacheService;
use OCA\Integriq\Service\EndpointService;
use OCA\Integriq\Service\ObjectService;
use OCP\AppFramework\Http\Response;
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
			'openconnector',
			$request,
			$this->createMock(EndpointService::class),
			$this->createMock(AuthorizationService::class),
			$this->createMock(ObjectService::class),
			$this->createMock(EndpointCacheService::class),
			$this->createMock(LoggerInterface::class),
			$this->createMock(IL10N::class)
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

}//end class

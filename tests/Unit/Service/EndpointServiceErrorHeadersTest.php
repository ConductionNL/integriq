<?php

/**
 * EndpointServiceErrorHeadersTest.
 *
 * An error answer turned into a problem document keeps its headers: a
 * token-protected endpoint's 401 carries the WWW-Authenticate challenge that
 * names where to get a token (RFC 9728), and dropping it left a client with a
 * 401 and no way to find the authorization server.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/access-oauth-and-token-validation/specs/authorization-jwt/spec.md#requirement-a-protected-endpoint-publishes-where-to-get-a-token-req-tokv-004
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Service;

use OCA\Integriq\Http\ProblemResponse;
use OCA\Integriq\Service\EndpointService;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Http\Response;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * The problem document of an error answer carries the answer's headers.
 */
class EndpointServiceErrorHeadersTest extends TestCase {

	/**
	 * The 401 of a token-protected endpoint.
	 *
	 * @return JSONResponse
	 */
	private static function challenged(): JSONResponse {
		$response = new JSONResponse(
			data: ['error' => 'unauthorized', 'details' => 'This endpoint requires a bearer token'],
			statusCode: 401
		);
		$response->addHeader(name: 'WWW-Authenticate', value: 'Bearer resource_metadata="https://cloud.example.nl/apps/integriq/.well-known/oauth-protected-resource/zaken"');

		return $response;
	}//end challenged()

	/**
	 * Call one private method of the service on an instance built without its collaborators.
	 *
	 * @param string              $method    The method.
	 * @param array<string,mixed> $arguments The named arguments.
	 *
	 * @return Response
	 */
	private function call(string $method, array $arguments): Response {
		$class = new ReflectionClass(objectOrClass: EndpointService::class);
		$service = $class->newInstanceWithoutConstructor();

		return $class->getMethod(name: $method)->invokeArgs(object: $service, args: $arguments);
	}//end call()

	/**
	 * The request the problem's instance member is read from.
	 *
	 * @return IRequest
	 */
	private function request(): IRequest {
		$request = $this->createMock(originalClassName: IRequest::class);
		$request->method('getId')->willReturn('req-1');

		return $request;
	}//end request()

	/**
	 * The error transform keeps the challenge and sets the problem content type.
	 *
	 * @return void
	 */
	public function testTheErrorTransformKeepsTheChallenge(): void {
		$problem = $this->call(method: 'transformError', arguments: ['result' => self::challenged(), 'request' => $this->request()]);

		$this->assertInstanceOf(expected: ProblemResponse::class, actual: $problem);
		$this->assertSame(expected: 401, actual: $problem->getStatus());
		// Response::getHeaders() needs a running server; read the stored headers.
		$headers = (new \ReflectionProperty(Response::class, 'headers'))->getValue($problem);
		$this->assertSame(
			expected: 'Bearer resource_metadata="https://cloud.example.nl/apps/integriq/.well-known/oauth-protected-resource/zaken"',
			actual: ($headers['WWW-Authenticate'] ?? null)
		);
		$this->assertSame(expected: ProblemResponse::CONTENT_TYPE, actual: ($headers['Content-Type'] ?? null));
	}//end testTheErrorTransformKeepsTheChallenge()

	/**
	 * A success passes the transform unchanged.
	 *
	 * @return void
	 */
	public function testASuccessIsLeftAlone(): void {
		$ok = new JSONResponse(data: ['ok' => true], statusCode: 200);

		$this->assertSame(expected: $ok, actual: $this->call(method: 'transformError', arguments: ['result' => $ok, 'request' => $this->request()]));
	}//end testASuccessIsLeftAlone()

}//end class

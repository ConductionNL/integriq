<?php

/**
 * Unit tests for the API contract middleware.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Middleware
 * @author   Conduction Development Team <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/integriq
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Middleware;

use OCA\Integriq\Middleware\ApiContractMiddleware;
use OCP\App\IAppManager;
use OCP\AppFramework\Http\DataDisplayResponse;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Http\Response;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;

/**
 * Pins the `API-Version` header and the problem details shape of API errors.
 *
 * @covers \OCA\Integriq\Middleware\ApiContractMiddleware
 */
class ApiContractMiddlewareTest extends TestCase {

	/**
	 * Run the middleware for a request on the given path.
	 *
	 * @param string|false $path     The request path info.
	 * @param Response     $response The controller response.
	 *
	 * @return Response The decorated response.
	 */
	private function decorate(string|false $path, Response $response): Response {
		$request = $this->createMock(originalClassName: IRequest::class);
		$request->method('getPathInfo')->willReturn($path);
		$request->method('getRequestUri')->willReturn('/index.php'.(string) $path.'?q=1');
		$appManager = $this->createMock(originalClassName: IAppManager::class);
		$appManager->method('getAppVersion')->willReturn('1.2.3');

		return (new ApiContractMiddleware(request: $request, appManager: $appManager))
			->afterController(controller: null, methodName: 'any', response: $response);
	}//end decorate()

	/**
	 * A successful API response carries the installed version and keeps its body.
	 *
	 * @return void
	 */
	public function testApiResponseCarriesTheVersionHeader(): void {
		$response = $this->decorate(path: '/apps/integriq/api/things', response: new JSONResponse(data: ['id' => 1]));

		$this->assertSame(expected: '1.2.3', actual: $this->headersOf(response: $response)['API-Version']);
		$this->assertSame(expected: ['id' => 1], actual: $response->getData());
		$this->assertSame(expected: 'application/json; charset=utf-8', actual: $this->headersOf(response: $response)['Content-Type']);
	}//end testApiResponseCarriesTheVersionHeader()

	/**
	 * A page response outside the API is left alone.
	 *
	 * @return void
	 */
	public function testNonApiResponseIsUntouched(): void {
		$response = $this->decorate(path: '/apps/integriq/', response: new JSONResponse(data: ['error' => 'x'], statusCode: 404));

		$this->assertArrayNotHasKey(key: 'API-Version', array: $this->headersOf(response: $response));
		$this->assertSame(expected: ['error' => 'x'], actual: $response->getData());

		$missing = $this->decorate(path: false, response: new JSONResponse(data: []));
		$this->assertArrayNotHasKey(key: 'API-Version', array: $this->headersOf(response: $missing));
	}//end testNonApiResponseIsUntouched()

	/**
	 * A JSON error becomes problem details and keeps the members it had.
	 *
	 * @return void
	 */
	public function testJsonErrorBecomesProblemDetails(): void {
		$response = $this->decorate(
			path: '/apps/integriq/api/things/7',
			response: new JSONResponse(data: ['error' => 'Thing not found'], statusCode: 404)
		);

		$expected = [
			'type'   => 'about:blank',
			'title'  => 'Not Found',
			'status' => 404,
			'detail' => 'Thing not found',
			'error'  => 'Thing not found',
		];
		$this->assertSame(expected: $expected, actual: $response->getData());
		$this->assertSame(expected: ApiContractMiddleware::PROBLEM_CONTENT_TYPE, actual: $this->headersOf(response: $response)['Content-Type']);
		$this->assertSame(expected: '1.2.3', actual: $this->headersOf(response: $response)['API-Version']);
	}//end testJsonErrorBecomesProblemDetails()

	/**
	 * `message` is used as detail when there is no `detail`, and an unknown status still gets a title.
	 *
	 * @return void
	 */
	public function testMessageIsUsedAsDetailAndUnknownStatusHasATitle(): void {
		$response = $this->decorate(
			path: '/apps/integriq/api/things',
			response: new JSONResponse(data: ['message' => 'Teapot', 'error' => 'ignored'], statusCode: 418)
		);

		$data = $response->getData();
		$this->assertSame(expected: 'Error', actual: $data['title']);
		$this->assertSame(expected: 'Teapot', actual: $data['detail']);
		$this->assertSame(expected: 418, actual: $data['status']);
	}//end testMessageIsUsedAsDetailAndUnknownStatusHasATitle()

	/**
	 * A body that uses a problem member for something else, or is a list, keeps its shape.
	 *
	 * @return void
	 */
	public function testConflictingOrListBodiesAreLeftAlone(): void {
		$conflict = $this->decorate(
			path: '/apps/integriq/api/things',
			response: new JSONResponse(data: ['status' => 'error', 'message' => 'No'], statusCode: 400)
		);
		$this->assertSame(expected: ['status' => 'error', 'message' => 'No'], actual: $conflict->getData());
		$this->assertSame(expected: 'application/json; charset=utf-8', actual: $this->headersOf(response: $conflict)['Content-Type']);

		$list = $this->decorate(path: '/apps/integriq/api/things', response: new JSONResponse(data: ['a', 'b'], statusCode: 400));
		$this->assertSame(expected: ['a', 'b'], actual: $list->getData());
	}//end testConflictingOrListBodiesAreLeftAlone()

	/**
	 * A body that already is problem details is kept, and an empty body gets the members.
	 *
	 * @return void
	 */
	public function testExistingProblemAndEmptyBodies(): void {
		$problem = ['type' => 'about:blank', 'title' => 'Forbidden', 'status' => 403, 'detail' => 'No access'];
		$kept    = $this->decorate(path: '/apps/integriq/api/things', response: new JSONResponse(data: $problem, statusCode: 403));
		$this->assertSame(expected: $problem, actual: $kept->getData());

		$empty = $this->decorate(path: '/apps/integriq/api/things', response: new JSONResponse(data: [], statusCode: 500));
		$this->assertSame(expected: ['type' => 'about:blank', 'title' => 'Internal Server Error', 'status' => 500], actual: $empty->getData());
	}//end testExistingProblemAndEmptyBodies()

	/**
	 * SCIM and OAuth token errors keep the format their standard fixes.
	 *
	 * @return void
	 */
	public function testForeignProtocolErrorsKeepTheirFormat(): void {
		$oauth    = ['error' => 'invalid_grant', 'error_description' => 'Expired'];
		$response = $this->decorate(path: '/apps/integriq/api/eudi/token', response: new JSONResponse(data: $oauth, statusCode: 400));
		$this->assertSame(expected: $oauth, actual: $response->getData());
		$this->assertSame(expected: 'application/json; charset=utf-8', actual: $this->headersOf(response: $response)['Content-Type']);

		$offer = $this->decorate(
			path: '/apps/integriq/api/eudi/credential-offers/1',
			response: new JSONResponse(data: ['error' => 'not_found'], statusCode: 404)
		);
		$this->assertSame(expected: 'about:blank', actual: $offer->getData()['type']);
	}//end testForeignProtocolErrorsKeepTheirFormat()

	/**
	 * The versioned base keeps the same split: a protocol path under /api/v1/
	 * keeps its format, any other path becomes problem details.
	 *
	 * @return void
	 */
	public function testTheVersionedBaseKeepsTheSameErrorFormats(): void {
		$oauth = ['error' => 'invalid_grant', 'error_description' => 'Expired'];
		foreach (['/apps/integriq/api/v1/eudi/token', '/apps/integriq/api/v1/lti/token'] as $path) {
			$this->assertSame(expected: $oauth, actual: $this->decorate(path: $path, response: new JSONResponse(data: $oauth, statusCode: 400))->getData());
		}

		$scim = ['schemas' => ['urn:ietf:params:scim:api:messages:2.0:Error'], 'status' => '404'];
		$this->assertSame(
			expected: $scim,
			actual: $this->decorate(path: '/apps/integriq/api/v1/scim/v2/Users/x', response: new JSONResponse(data: $scim, statusCode: 404))->getData()
		);

		$offer = $this->decorate(
			path: '/apps/integriq/api/v1/eudi/credential-offers/1',
			response: new JSONResponse(data: ['error' => 'not_found'], statusCode: 404)
		);
		$this->assertSame(expected: 'about:blank', actual: $offer->getData()['type']);
	}//end testTheVersionedBaseKeepsTheSameErrorFormats()

	/**
	 * A non-JSON error response gets the header but no body rewrite.
	 *
	 * @return void
	 */
	public function testNonJsonErrorOnlyGetsTheHeader(): void {
		$response = $this->decorate(path: '/apps/integriq/api/files', response: new DataDisplayResponse(data: 'gone', statusCode: 410));

		$this->assertSame(expected: '1.2.3', actual: $this->headersOf(response: $response)['API-Version']);
		$this->assertSame(expected: 'gone', actual: $response->getData());
	}//end testNonJsonErrorOnlyGetsTheHeader()

	/**
	 * An unversioned API path keeps answering and names its `/api/v1/` successor.
	 *
	 * @return void
	 */
	public function testUnversionedPathAnnouncesItsSuccessor(): void {
		$response = $this->decorate(path: '/apps/integriq/api/things/7/parts', response: new JSONResponse(data: ['id' => 7]));
		$headers  = $this->headersOf(response: $response);

		$this->assertSame(expected: ['id' => 7], actual: $response->getData());
		$this->assertSame(expected: '@1794441600', actual: $headers['Deprecation']);
		$this->assertSame(expected: '</index.php/apps/integriq/api/v1/things/7/parts>; rel="successor-version"', actual: $headers['Link']);
	}//end testUnversionedPathAnnouncesItsSuccessor()

	/**
	 * A versioned API path is not deprecated, and still carries the version.
	 *
	 * @return void
	 */
	public function testVersionedPathIsNotDeprecated(): void {
		$headers = $this->headersOf(response: $this->decorate(path: '/apps/integriq/api/v1/things/7', response: new JSONResponse(data: [])));

		$this->assertSame(expected: '1.2.3', actual: $headers['API-Version']);
		$this->assertArrayNotHasKey(key: 'Deprecation', array: $headers);
		$this->assertArrayNotHasKey(key: 'Link', array: $headers);
	}//end testVersionedPathIsNotDeprecated()

	/**
	 * A path that speaks a protocol defined elsewhere keeps its error format and is not deprecated.
	 *
	 * @return void
	 */
	public function testStandardProtocolPathIsUntouched(): void {
		$response = $this->decorate(path: '/apps/integriq/api/scim/v2/Users', response: new JSONResponse(data: ['error' => 'x'], statusCode: 400));
		$headers  = $this->headersOf(response: $response);

		$this->assertSame(expected: ['error' => 'x'], actual: $response->getData());
		$this->assertSame(expected: '1.2.3', actual: $headers['API-Version']);
		$this->assertArrayNotHasKey(key: 'Deprecation', array: $headers);
	}//end testStandardProtocolPathIsUntouched()

	/**
	 * A protocol path without a foreign error format keeps the API contract, minus the deprecation.
	 *
	 * @return void
	 */
	public function testHealthPathKeepsVersionAndProblemDetails(): void {
		$response = $this->decorate(path: '/apps/integriq/api/health', response: new JSONResponse(data: ['error' => 'down'], statusCode: 503));
		$headers  = $this->headersOf(response: $response);
		$data     = $response->getData();

		$this->assertSame(expected: '1.2.3', actual: $headers['API-Version']);
		$this->assertSame(expected: 503, actual: $data['status']);
		$this->assertSame(expected: 'down', actual: $data['error']);
		$this->assertArrayNotHasKey(key: 'Deprecation', array: $headers);
	}//end testHealthPathKeepsVersionAndProblemDetails()

	/**
	 * The headers a response set, read without the server container that
	 * Response::getHeaders() needs.
	 *
	 * @param Response $response The response.
	 *
	 * @return array<string,string> The headers by name.
	 */
	private function headersOf(Response $response): array {
		$property = new ReflectionProperty(Response::class, 'headers');

		return $property->getValue($response);
	}//end headersOf()
}//end class

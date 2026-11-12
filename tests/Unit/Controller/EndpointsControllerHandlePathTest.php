<?php

/**
 * Characterisation tests for EndpointsController::handlePath().
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
use OCA\Integriq\Controller\XMLResponse;
use OCA\Integriq\Service\Consumer\OpenRegisterCredentialBridge;
use OCA\Integriq\Service\EndpointCacheService;
use OCA\Integriq\Service\EndpointCorsPolicy;
use OCA\Integriq\Service\EndpointService;
use OCA\Integriq\Service\ObjectService;
use OCA\OpenRegister\Db\ObjectEntity;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Http\Response;
use OCP\IL10N;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Pins handlePath(): which endpoint answers, the 404 and 409 answers, the
 * route through EndpointService, the XML conversion and the CORS headers.
 */
class EndpointsControllerHandlePathTest extends TestCase {

	/**
	 * Every call made, in order.
	 *
	 * @var list<string>
	 */
	private array $calls = [];

	/**
	 * Build the controller.
	 *
	 * @param mixed    $found  What the cache answers: an endpoint, null or an exception.
	 * @param string   $accept The Accept header.
	 * @param Response $served What EndpointService answers.
	 *
	 * @return EndpointsController
	 */
	private function controller(mixed $found, string $accept, Response $served): EndpointsController {
		$request = $this->createMock(IRequest::class);
		$request->method('getMethod')->willReturn('PATCH');
		$request->method('getHeader')->willReturnCallback(static fn (string $name): string => ($name === 'Accept' ? $accept : ''));
		$cache = $this->createMock(EndpointCacheService::class);
		$cache->method('findByPathRegex')->willReturnCallback(
			function (string $path, string $method) use ($found) {
				$this->calls[] = 'find ' . $path . ' ' . $method;
				if ($found instanceof \Exception) {
					throw $found;
				}

				return $found;
			}
		);
		$endpoints = $this->createMock(EndpointService::class);
		$endpoints->method('handleRequest')->willReturnCallback(
			function ($endpoint, $request, string $path) use ($served): Response {
				$this->calls[] = 'handleRequest ' . $path;
				return $served;
			}
		);
		$bridge = $this->createMock(OpenRegisterCredentialBridge::class);
		$bridge->method('corsAfterController')->willReturnCallback(
			function ($request, Response $response): Response {
				$this->calls[] = 'cors ' . get_class($response);
				$response->addHeader('Access-Control-Allow-Origin', 'echo');
				return $response;
			}
		);
		$policy = $this->createMock(EndpointCorsPolicy::class);
		$policy->method('headersFor')->willReturnCallback(
			static fn (ObjectEntity $endpoint): ?array => ($endpoint->getObject()['cors'] ?? null)
		);
		$l = $this->createMock(IL10N::class);
		$l->method('t')->willReturnCallback(static fn (string $text, array $params = []): string => vsprintf($text, $params));

		return new EndpointsController('integriq', $request, $endpoints, $bridge, $this->createMock(ObjectService::class), $cache, $this->createMock(LoggerInterface::class), $l, $policy);

	}//end controller()

	/**
	 * The headers a response set itself.
	 *
	 * @param Response $response The response.
	 *
	 * @return array<string, mixed>
	 */
	private function rawHeaders(Response $response): array {
		$property = new \ReflectionProperty(Response::class, 'headers');
		$property->setAccessible(true);

		return $property->getValue($response);

	}//end rawHeaders()

	/**
	 * An endpoint.
	 *
	 * @param array $data Its object data.
	 *
	 * @return ObjectEntity
	 */
	private function endpoint(array $data): ObjectEntity {
		$endpoint = new ObjectEntity();
		$endpoint->setObject($data);

		return $endpoint;

	}//end endpoint()

	/**
	 * No match answers 404 naming path and method; a cache failure 409.
	 *
	 * @return void
	 */
	public function testNoMatchAndAnAmbiguousMatchAreRefused(): void {
		$missing = $this->controller(null, '', new JSONResponse())->handlePath('a/b');
		$this->assertSame([404, ['error' => 'No matching endpoint found for path and method: a/b PATCH']], [$missing->getStatus(), $missing->getData()]);

		$ambiguous = $this->controller(new RuntimeException('two endpoints match'), '', new JSONResponse())->handlePath('a/b');
		$this->assertSame([409, ['error' => 'two endpoints match']], [$ambiguous->getStatus(), $ambiguous->getData()]);
		$this->assertSame(['find a/b PATCH', 'find a/b PATCH'], $this->calls);

	}//end testNoMatchAndAnAmbiguousMatchAreRefused()

	/**
	 * A non-simple endpoint is served by EndpointService; its JSON answer is
	 * converted to XML when XML is accepted; CORS then the endpoint's policy.
	 *
	 * @return void
	 */
	public function testTheServedAnswerIsConvertedAndGetsItsHeaders(): void {
		$served = new class(['a' => 1], 201) extends JSONResponse {
			/**
			 * Headers without a running server.
			 *
			 * @return array
			 */
			public function getHeaders(): array {
				return ['X-Served' => 'yes'];
			}
		};
		$endpoint = $this->endpoint(['cors' => ['Access-Control-Allow-Origin' => 'policy', 'X-Policy' => 'p']]);

		$response = $this->controller($endpoint, 'text/html, application/xml;q=0.9', $served)->handlePath('things');

		$this->assertInstanceOf(XMLResponse::class, $response);
		$this->assertSame(201, $response->getStatus());
		$headers = $this->rawHeaders($response);
		$this->assertSame('yes', $headers['X-Served']);
		$this->assertSame('application/xml; charset=utf-8', $headers['Content-Type']);
		$this->assertSame('policy', $headers['Access-Control-Allow-Origin']);
		$this->assertSame('p', $headers['X-Policy']);
		$this->assertSame(['find things PATCH', 'handleRequest things', 'cors ' . XMLResponse::class], $this->calls);

	}//end testTheServedAnswerIsConvertedAndGetsItsHeaders()

	/**
	 * A JSON answer stays JSON without an XML Accept, a non-JSON answer is
	 * never converted, and no policy leaves the echoed origin.
	 *
	 * @return void
	 */
	public function testOtherAnswersAreLeftAsServed(): void {
		$json = new JSONResponse(['a' => 1]);
		$response = $this->controller($this->endpoint([]), 'application/json', $json)->handlePath('p');
		$this->assertSame($json, $response);
		$this->assertSame('echo', $this->rawHeaders($response)['Access-Control-Allow-Origin']);

		$plain = new Response();
		$this->assertSame($plain, $this->controller($this->endpoint([]), 'application/xml', $plain)->handlePath('p'));

	}//end testOtherAnswersAreLeftAsServed()

}//end class

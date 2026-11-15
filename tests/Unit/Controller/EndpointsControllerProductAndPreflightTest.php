<?php
/**
 * EndpointsController: the simple path of an endpoint sold as a product, the
 * endpoint a preflight asks about, and the logs placeholder.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @spec openspec/specs/endpoint-runtime/spec.md#requirement-deprecated-product-version-dispatch-attaches-sunset-deprecation-headers-req-ep-008
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Controller;

use Exception;
use OCA\Integriq\Controller\EndpointsController;
use OCA\Integriq\Service\Consumer\OpenRegisterCredentialBridge;
use OCA\Integriq\Service\EndpointCacheService;
use OCA\Integriq\Service\EndpointCorsPolicy;
use OCA\Integriq\Service\EndpointService;
use OCA\Integriq\Service\ObjectService;
use OCA\Integriq\Service\SearchServiceInterface;
use OCA\OpenRegister\Db\ObjectEntity;
use OCP\IConfig;
use OCP\IL10N;
use OCP\IRequest;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * @covers \OCA\Integriq\Controller\EndpointsController
 * @uses \OCA\Integriq\Service\EndpointCorsPolicy
 */
class EndpointsControllerProductAndPreflightTest extends TestCase {

	/** @var IRequest&MockObject */
	private IRequest&MockObject $request;

	/** @var EndpointService&MockObject */
	private EndpointService&MockObject $endpointService;

	/** @var EndpointCacheService&MockObject */
	private EndpointCacheService&MockObject $cache;

	protected function setUp(): void {
		$this->request = $this->createMock(IRequest::class);
		$this->request->method('getServerProtocol')->willReturn('https');
		$this->request->method('getServerHost')->willReturn('nc.example');
		$this->endpointService = $this->createMock(EndpointService::class);
		$this->cache = $this->createMock(EndpointCacheService::class);
	}//end setUp()

	/**
	 * The controller under test.
	 *
	 * @return EndpointsController
	 */
	private function controller(): EndpointsController {
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $text, array $params = []): string => vsprintf($text, $params));

		return new EndpointsController(
			'integriq',
			$this->request,
			$this->endpointService,
			$this->createMock(OpenRegisterCredentialBridge::class),
			$this->createMock(ObjectService::class),
			$this->cache,
			$this->createMock(LoggerInterface::class),
			$l10n,
			new EndpointCorsPolicy($this->createMock(IConfig::class))
		);
	}//end controller()

	/**
	 * Call a private method of the controller.
	 *
	 * @param string $name The method.
	 * @param mixed  ...$args Its arguments.
	 *
	 * @return mixed
	 */
	private function call(string $name, mixed ...$args): mixed {
		$method = new \ReflectionMethod(EndpointsController::class, $name);
		$method->setAccessible(true);

		return $method->invoke($this->controller(), ...$args);
	}//end call()

	/**
	 * The headers a response was given. Response::getHeaders() needs a running
	 * server, so the stored headers are read directly.
	 *
	 * @param \OCP\AppFramework\Http\Response $response The response.
	 *
	 * @return array<string, string>
	 */
	private function headersOf(\OCP\AppFramework\Http\Response $response): array {
		return (new \ReflectionProperty(\OCP\AppFramework\Http\Response::class, 'headers'))->getValue($response);
	}//end headersOf()

	/**
	 * A simple endpoint sold as a product answers with the product's deprecation headers and is logged with its status.
	 *
	 * @return void
	 */
	public function testASimpleEndpointOfAProductCarriesItsDeprecationHeadersAndIsLogged(): void {
		$this->request->method('getMethod')->willReturn('GET');
		$endpoint = new ObjectEntity();
		// A target that cannot be read answers 500 without touching OpenRegister.
		$endpoint->setObject(['isPublic' => true, 'targetType' => 'register/schema', 'targetId' => '', 'endpoint' => 'things/v1/items']);
		$product = new ObjectEntity();
		$product->setObject(['name' => 'Things API v1']);

		$this->endpointService->method('resolveProductForEndpoint')->with($endpoint)->willReturn($product);
		$this->endpointService->method('buildDeprecationHeaders')->with($product)->willReturn([
			'Deprecation' => 'true',
			'Sunset' => 'Wed, 31 Dec 2026 23:59:59 GMT',
		]);
		$logged = [];
		$this->endpointService->expects($this->once())->method('recordInboundCallLog')->willReturnCallback(
			static function (ObjectEntity $endpoint, ObjectEntity $product, int $statusCode, float $durationMs) use (&$logged): void {
				$logged = [$product->getObject()['name'], $statusCode, $durationMs >= 0];
			}
		);

		$response = $this->call('handleSimpleSchemaRequest', $endpoint, 'things/v1/items');

		$this->assertSame(500, $response->getStatus());
		$this->assertSame('true', $this->headersOf($response)['Deprecation']);
		$this->assertSame('Wed, 31 Dec 2026 23:59:59 GMT', $this->headersOf($response)['Sunset']);
		$this->assertSame(['Things API v1', 500, true], $logged);
	}//end testASimpleEndpointOfAProductCarriesItsDeprecationHeadersAndIsLogged()

	/**
	 * A simple endpoint that is no product gets no deprecation headers and no call log.
	 *
	 * @return void
	 */
	public function testASimpleEndpointThatIsNoProductIsNotLogged(): void {
		$this->request->method('getMethod')->willReturn('GET');
		$endpoint = new ObjectEntity();
		$endpoint->setObject(['isPublic' => true, 'targetType' => 'register/schema', 'targetId' => '']);
		$this->endpointService->method('resolveProductForEndpoint')->willReturn(null);
		$this->endpointService->expects($this->never())->method('buildDeprecationHeaders');
		$this->endpointService->expects($this->never())->method('recordInboundCallLog');

		$response = $this->call('handleSimpleSchemaRequest', $endpoint, 'things/v1/items');

		$this->assertArrayNotHasKey('Sunset', $this->headersOf($response));
	}//end testASimpleEndpointThatIsNoProductIsNotLogged()

	/**
	 * A preflight asks for the endpoint by the method it announces, GET when it announces none.
	 *
	 * @return void
	 */
	public function testAPreflightLooksTheEndpointUpByItsAnnouncedMethod(): void {
		$methods = ['', ' post '];
		$this->request->method('getHeader')->with('Access-Control-Request-Method')->willReturnCallback(
			static function () use (&$methods): string {
				return array_shift($methods);
			}
		);
		$found = new ObjectEntity();
		$asked = [];
		$this->cache->method('findByPathRegex')->willReturnCallback(
			static function (string $path, string $method) use (&$asked, $found): ObjectEntity {
				$asked[] = [$path, $method];
				return $found;
			}
		);

		$this->assertSame($found, $this->call('preflightEndpoint', 'things/v1/items'));
		$this->assertSame($found, $this->call('preflightEndpoint', 'things/v1/items'));
		$this->assertSame([['things/v1/items', 'GET'], ['things/v1/items', 'POST']], $asked);
	}//end testAPreflightLooksTheEndpointUpByItsAnnouncedMethod()

	/**
	 * No path, or several matching endpoints, give the default preflight.
	 *
	 * @return void
	 */
	public function testNoPathOrSeveralMatchesGiveNoEndpoint(): void {
		$this->request->method('getHeader')->willReturn('GET');
		$this->cache->method('findByPathRegex')->willThrowException(new Exception('Multiple endpoints match'));

		$this->assertNull($this->call('preflightEndpoint', ''));
		$this->assertNull($this->call('preflightEndpoint', 'things/v1/items'));
	}//end testNoPathOrSeveralMatchesGiveNoEndpoint()

	/**
	 * The logs answer an empty page with the limit asked for.
	 *
	 * @return void
	 */
	public function testTheLogsAnswerAnEmptyPage(): void {
		$this->request->method('getParam')->willReturnCallback(static fn (string $key, mixed $default = null): mixed => $key === '_limit' ? '50' : $default);

		$data = $this->controller()->logs($this->createMock(SearchServiceInterface::class))->getData();

		$this->assertSame(['results' => [], 'total' => 0, 'page' => 1, 'pages' => 1, 'limit' => 50, 'offset' => 0], $data);
	}//end testTheLogsAnswerAnEmptyPage()
}//end class

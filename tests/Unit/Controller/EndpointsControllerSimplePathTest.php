<?php

/**
 * Characterisation tests for EndpointsController's simple GET fast path.
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

use Exception;
use OCA\Integriq\Controller\EndpointsController;
use OCA\Integriq\Service\Consumer\OpenRegisterCredentialBridge;
use OCA\Integriq\Service\EndpointCacheService;
use OCA\Integriq\Service\EndpointCorsPolicy;
use OCA\Integriq\Service\EndpointService;
use OCA\Integriq\Service\ObjectService;
use OCA\OpenRegister\Db\ObjectEntity;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IConfig;
use OCP\IL10N;
use OCP\IRequest;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Pins which endpoints take the fast path and what the fast path answers,
 * for every branch of isSimpleEndpoint() and dispatchSimpleSchemaRequest().
 */
class EndpointsControllerSimplePathTest extends TestCase {

	/**
	 * The mocked request.
	 *
	 * @var IRequest&MockObject
	 */
	private IRequest $request;

	/**
	 * The mocked object service.
	 *
	 * @var ObjectService&MockObject
	 */
	private ObjectService $objectService;

	/**
	 * The mocked logger.
	 *
	 * @var LoggerInterface&MockObject
	 */
	private LoggerInterface $logger;

	/**
	 * Build the mocks shared by every test.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->request = $this->createMock(IRequest::class);
		$this->request->method('getServerProtocol')->willReturn('https');
		$this->request->method('getServerHost')->willReturn('nc.example');
		$this->objectService = $this->createMock(ObjectService::class);
		$this->logger = $this->createMock(LoggerInterface::class);

	}//end setUp()

	/**
	 * Build the controller under test.
	 *
	 * @return EndpointsController
	 */
	private function controller(): EndpointsController {
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(
			static fn (string $text, array $params = []): string => vsprintf($text, $params)
		);

		return new EndpointsController(
			'integriq',
			$this->request,
			$this->createMock(EndpointService::class),
			$this->createMock(OpenRegisterCredentialBridge::class),
			$this->objectService,
			$this->createMock(EndpointCacheService::class),
			$this->logger,
			$l10n,
			new EndpointCorsPolicy($this->createMock(IConfig::class))
		);

	}//end controller()

	/**
	 * Invoke a private controller method on an endpoint built from data.
	 *
	 * @param string $name The private method.
	 * @param array  $data The endpoint object data.
	 *
	 * @return mixed The method's result.
	 */
	private function invoke(string $name, array $data): mixed {
		$endpoint = new ObjectEntity();
		$endpoint->setObject($data);
		$method = new \ReflectionMethod(EndpointsController::class, $name);
		$method->setAccessible(true);
		if ($name === 'isSimpleEndpoint') {
			return $method->invoke($this->controller(), $endpoint);
		}

		return $method->invoke($this->controller(), $endpoint, 'things/v1/items');

	}//end invoke()

	/**
	 * A stub mapper answering find() and findAllPaginated().
	 *
	 * @param array $page The findAllPaginated() result.
	 *
	 * @return object
	 */
	private function mapper(array $page): object {
		return new class($page) {
			/**
			 * Constructor.
			 *
			 * @param array $page The paginated result to answer.
			 */
			public function __construct(public array $page) {
			}

			/**
			 * Answer one object.
			 *
			 * @param string $id The id.
			 *
			 * @return ObjectEntity
			 */
			public function find(string $id): ObjectEntity {
				$object = new ObjectEntity();
				$object->setUuid($id);
				return $object;
			}

			/**
			 * Answer the stored page, echoing the request parameters.
			 *
			 * @param array $requestParams The query parameters.
			 *
			 * @return array
			 */
			public function findAllPaginated(array $requestParams): array {
				if (($requestParams['explode'] ?? null) === '1') {
					throw new Exception('query failed');
				}

				return $this->page;
			}
		};

	}//end mapper()

	/**
	 * A public GET endpoint that qualifies for the fast path.
	 *
	 * @return array
	 */
	private function simpleEndpoint(): array {
		return [
			'isPublic'   => true,
			'targetType' => 'register/schema',
			'targetId'   => '20/111',
			'endpoint'   => 'things/v1/items',
		];

	}//end simpleEndpoint()

	/**
	 * Only a public GET endpoint without processing takes the fast path; every
	 * other shape is refused, one property at a time.
	 *
	 * @return void
	 */
	public function testOnlyAPublicPlainGetEndpointTakesTheFastPath(): void {
		$this->request->method('getMethod')->willReturn('GET');
		$this->assertTrue($this->invoke('isSimpleEndpoint', $this->simpleEndpoint()));

		$keys = ['rules', 'fixedFilters', 'anonymousRateLimit', 'conditions', 'inputMapping', 'outputMapping', 'configurations'];
		foreach ($keys as $key) {
			$data = $this->simpleEndpoint();
			$data[$key] = ['x'];
			$this->assertFalse($this->invoke('isSimpleEndpoint', $data), $key);
		}

		$cases = [
			['isPublic' => null],
			['isPublic' => 'true'],
			['isPublic' => false],
			['targetType' => 'source'],
			['targetType' => null],
		];
		foreach ($cases as $override) {
			$data = array_filter(array_merge($this->simpleEndpoint(), $override), static fn ($v) => $v !== null);
			$this->assertFalse($this->invoke('isSimpleEndpoint', $data), json_encode($override));
		}

	}//end testOnlyAPublicPlainGetEndpointTakesTheFastPath()

	/**
	 * A write method never takes the fast path, even on a public endpoint.
	 *
	 * @return void
	 */
	public function testAWriteMethodNeverTakesTheFastPath(): void {
		$this->request->method('getMethod')->willReturn('POST');
		$this->assertFalse($this->invoke('isSimpleEndpoint', $this->simpleEndpoint()));

	}//end testAWriteMethodNeverTakesTheFastPath()

	/**
	 * An empty or malformed targetId answers 500 and logs an error.
	 *
	 * @return void
	 */
	public function testAMisconfiguredTargetAnswersFiveHundred(): void {
		$this->request->method('getMethod')->willReturn('GET');
		$this->logger->expects($this->exactly(4))->method('error');

		$data = $this->simpleEndpoint();
		$data['targetId'] = '';
		$response = $this->invoke('dispatchSimpleSchemaRequest', $data);
		$this->assertSame(500, $response->getStatus());
		$this->assertSame(['error' => 'Endpoint misconfigured: empty targetId'], $response->getData());

		foreach (['20', '20/x', 'a/1'] as $targetId) {
			$data['targetId'] = $targetId;
			$response = $this->invoke('dispatchSimpleSchemaRequest', $data);
			$this->assertSame(500, $response->getStatus(), $targetId);
			$this->assertStringStartsWith('Endpoint misconfigured: invalid targetId format', $response->getData()['error']);
		}

	}//end testAMisconfiguredTargetAnswersFiveHundred()

	/**
	 * A mapper that cannot be resolved answers 404 with the reason.
	 *
	 * @return void
	 */
	public function testAnUnknownSchemaAnswersFourOhFour(): void {
		$this->request->method('getMethod')->willReturn('GET');
		$this->objectService->method('getMapper')->willThrowException(new Exception('no schema'));
		$this->logger->expects($this->once())->method('error')->with('Failed to get ObjectService mapper');

		$response = $this->invoke('dispatchSimpleSchemaRequest', $this->simpleEndpoint());

		$this->assertSame(404, $response->getStatus());
		$this->assertSame(['error' => 'Schema or register not found: no schema'], $response->getData());

	}//end testAnUnknownSchemaAnswersFourOhFour()

	/**
	 * The dispatch refuses a write method with 405 after resolving the mapper.
	 *
	 * @return void
	 */
	public function testTheDispatchRefusesAWriteMethod(): void {
		$this->request->method('getMethod')->willReturn('PUT');
		$this->objectService->expects($this->once())->method('getMapper')
			->with(null, 111, 20)->willReturn($this->mapper([]));

		$response = $this->invoke('dispatchSimpleSchemaRequest', $this->simpleEndpoint());

		$this->assertSame(405, $response->getStatus());
		$this->assertSame(['error' => 'Method not supported on simple endpoint'], $response->getData());

	}//end testTheDispatchRefusesAWriteMethod()

	/**
	 * An id as the last path parameter answers that one object.
	 *
	 * @return void
	 */
	public function testAnIdInThePathAnswersTheObject(): void {
		$this->request->method('getMethod')->willReturn('GET');
		$this->objectService->method('getMapper')->willReturn($this->mapper([]));
		$data = $this->simpleEndpoint();
		$data['endpointArray'] = ['things', 'v1', '{{id}}'];

		$response = $this->invoke('dispatchSimpleSchemaRequest', $data);

		$this->assertSame(200, $response->getStatus());
		$this->assertSame(['uuid' => 'items'], $response->getData());

	}//end testAnIdInThePathAnswersTheObject()

	/**
	 * A collection answers count, serialised results and both page links,
	 * without the internal _path parameter.
	 *
	 * @return void
	 */
	public function testACollectionAnswersCountResultsAndPageLinks(): void {
		$this->request->method('getMethod')->willReturn('GET');
		$this->request->method('getParams')->willReturn(['_path' => 'things/v1/items', 'q' => 'x']);
		$object = new ObjectEntity();
		$object->setUuid('u-1');
		$page = ['results' => [$object], 'total' => 7, 'page' => 2, 'pages' => 3, 'limit' => 1];
		$this->objectService->method('getMapper')->willReturn($this->mapper($page));
		$this->logger->expects($this->once())->method('info')->with(
			'Simple endpoint query',
			$this->callback(static fn (array $context): bool => $context['result_total'] === 7 && $context['parameters'] === ['q' => 'x'])
		);

		$data = $this->invoke('dispatchSimpleSchemaRequest', $this->simpleEndpoint())->getData();

		$this->assertSame(7, $data['count']);
		$this->assertArrayNotHasKey('total', $data);
		$this->assertSame([$object->jsonSerialize()], $data['results']);
		$this->assertSame('https://nc.example/apps/integriq/api/endpoint/things/v1/items?q=x&page=3', $data['next']);
		$this->assertSame('https://nc.example/apps/integriq/api/endpoint/things/v1/items?q=x&page=1', $data['previous']);
		$this->assertSame(['results', 'page', 'pages', 'limit', 'count', 'next', 'previous'], array_keys($data));

	}//end testACollectionAnswersCountResultsAndPageLinks()

	/**
	 * A single page has no links; a failing query answers 500.
	 *
	 * @return void
	 */
	public function testASinglePageHasNoLinksAndAFailingQueryAnswersFiveHundred(): void {
		$this->request->method('getMethod')->willReturn('GET');
		$this->request->method('getParams')->willReturnOnConsecutiveCalls([], ['explode' => '1']);
		$page = ['results' => [], 'total' => 0, 'page' => 1, 'pages' => 1];
		$this->objectService->method('getMapper')->willReturn($this->mapper($page));

		$data = $this->invoke('dispatchSimpleSchemaRequest', $this->simpleEndpoint())->getData();
		$this->assertSame(['results' => [], 'page' => 1, 'pages' => 1, 'count' => 0], $data);

		$response = $this->invoke('dispatchSimpleSchemaRequest', $this->simpleEndpoint());
		$this->assertInstanceOf(JSONResponse::class, $response);
		$this->assertSame(500, $response->getStatus());
		$this->assertSame(['error' => 'Simple endpoint error: query failed'], $response->getData());

	}//end testASinglePageHasNoLinksAndAFailingQueryAnswersFiveHundred()

}//end class

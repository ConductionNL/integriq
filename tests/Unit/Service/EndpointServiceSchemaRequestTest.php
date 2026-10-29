<?php

/**
 * Characterization tests for EndpointService::handleSchemaRequest().
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://www.integriq.nl
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Service;

use Exception;
use OCA\Integriq\Rule\AvgBsnPolicyRule;
use OCA\Integriq\Rule\CompositeFanoutRule;
use OCA\Integriq\Rule\ReferenceNumberRule;
use OCA\Integriq\Service\ApprovalService;
use OCA\Integriq\Service\CallService;
use OCA\Integriq\Service\Consumer\OpenRegisterCredentialBridge;
use OCA\Integriq\Service\ConsumerScopeService;
use OCA\Integriq\Service\EndpointService;
use OCA\Integriq\Service\FlowRunnerService;
use OCA\Integriq\Service\Helper\FlowToken;
use OCA\Integriq\Service\MappingService;
use OCA\Integriq\Service\ObjectService;
use OCA\Integriq\Service\RateLimit\InboundRateLimitService;
use OCA\Integriq\Service\RuleService;
use OCA\Integriq\Service\StorageService;
use OCA\Integriq\Service\SynchronizationService;
use OCA\Integriq\Service\WebhookSignatureService;
use OCA\Integriq\Tests\Helpers\ObjectServiceMockBuilder;
use OCA\Integriq\Tests\Helpers\SchemaMapperAdapterDouble;
use OCA\Integriq\Tests\Helpers\UriObjectEntity;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\FileService;
use OCA\OpenRegister\Service\ObjectService as ORObjectService;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IConfig;
use OCP\IRequestId;
use OCP\IURLGenerator;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use ReflectionMethod;

/**
 * Pins what handleSchemaRequest() answers, and what it writes on the flow
 * token, for every HTTP method a schema endpoint serves.
 *
 * @covers \OCA\Integriq\Service\EndpointService
 */
class EndpointServiceSchemaRequestTest extends TestCase {

	/**
	 * @var EndpointService
	 */
	private EndpointService $service;

	/**
	 * @var ObjectService&MockObject
	 */
	private $objectService;

	/**
	 * @var MappingService&MockObject
	 */
	private $mappingService;

	/**
	 * @var SchemaMapper&MockObject
	 */
	private $schemaMapper;

	/**
	 * @var MockObject
	 */
	private $mapper;

	/**
	 * The flow token calls in order, as [method, status or null].
	 *
	 * @var array<int, array{0: string, 1: int|null}>
	 */
	private array $tokenCalls = [];

	/**
	 * Set up the service with doubles for every collaborator.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$logger = $this->createMock(LoggerInterface::class);
		$orObjectService = ObjectServiceMockBuilder::make($this);
		$orObjectService->method('findAll')->willReturn(['results' => [], 'total' => 0]);
		$this->objectService = $this->createMock(ObjectService::class);
		$this->mappingService = $this->createMock(MappingService::class);
		$this->schemaMapper = $this->createMock(SchemaMapper::class);
		$urlGenerator = $this->createMock(IURLGenerator::class);
		$urlGenerator->method('getBaseUrl')->willReturn('https://example.org');

		$openRegisters = $this->getMockBuilder(ORObjectService::class)->disableOriginalConstructor()->onlyMethods(['getMapper'])->addMethods(['clearCurrents'])->getMock();
		$this->objectService->method('getOpenRegisters')->willReturn($openRegisters);
		$objectEntities = $this->createMock(SchemaMapperAdapterDouble::class);
		$objectEntities->method('find')->willReturnCallback(fn ($id) => $this->entity(['id' => (string)$id]));
		$openRegisters->method('getMapper')->willReturn($objectEntities);

		$schema = $this->getMockBuilder(\stdClass::class)->addMethods(['getProperties', 'getTitle', 'getRequired'])->getMock();
		$schema->method('getProperties')->willReturn([]);
		$schema->method('getTitle')->willReturn('Thing');
		$schema->method('getRequired')->willReturn(['name', 'kind']);
		$this->schemaMapper->method('find')->willReturn($schema);

		$this->mapper = $this->createMock(SchemaMapperAdapterDouble::class);
		$this->mapper->method('getSchema')->willReturn(2);
		$this->mapper->method('find')->willReturnCallback(
			function ($id) {
				if ($id === 'missing') {
					throw new \OCP\AppFramework\Db\DoesNotExistException('gone');
				}

				return $this->entity(['id' => (string)$id, 'name' => 'n']);
			}
		);
		$this->objectService->method('getMapper')->willReturn($this->mapper);

		$this->service = new EndpointService(
			$this->objectService,
			$this->createMock(CallService::class),
			$logger,
			$urlGenerator,
			$this->mappingService,
			$orObjectService,
			$this->createMock(IConfig::class),
			$this->createMock(StorageService::class),
			$this->createMock(OpenRegisterCredentialBridge::class),
			$this->createMock(ContainerInterface::class),
			$this->createMock(SynchronizationService::class),
			$this->createMock(RuleService::class),
			new WebhookSignatureService($logger),
			$this->createMock(InboundRateLimitService::class),
			new CompositeFanoutRule($orObjectService, $logger),
			new ReferenceNumberRule(),
			new AvgBsnPolicyRule(),
			$this->createMock(ApprovalService::class),
			$this->createMock(IRequestId::class),
			$this->createMock(FlowRunnerService::class),
			$this->createMock(ConsumerScopeService::class),
			$this->schemaMapper,
			$this->createMock(FileService::class),
		);
	}//end setUp()

	/**
	 * An object entity the mapper answers.
	 *
	 * @param array<string, mixed> $body The object body.
	 *
	 * @return ObjectEntity The entity.
	 */
	private function entity(array $body): ObjectEntity {
		$entity = new UriObjectEntity();
		$entity->setUuid((string)($body['id'] ?? 'obj-1'));
		$entity->setObject($body);

		return $entity;
	}//end entity()

	/**
	 * Runs handleSchemaRequest() with a flow token double that records its writes.
	 *
	 * @param array<string, mixed> $endpointData The endpoint configuration.
	 * @param array<string, mixed> $request      The amended request.
	 *
	 * @return JSONResponse The response.
	 */
	private function handle(array $endpointData, array $request): JSONResponse {
		$endpointData += ['targetId' => '1/2', 'endpointArray' => ['things', '{{id}}']];
		$request += ['parameters' => [], 'headers' => []];
		$calls = &$this->tokenCalls;
		$flowToken = $this->createMock(FlowToken::class);
		$flowToken->method('getRequestAmended')->willReturn($request);
		$flowToken->method('getResponseOriginal')->willReturnCallback(
			function () use (&$calls) {
				return ['status' => end($calls)[1]];
			}
		);
		$flowToken->method('setResponseOriginal')->willReturnCallback(
			function ($response) use (&$calls) {
				$calls[] = ['original', $response->getStatus()];
				return [];
			}
		);
		$flowToken->method('setResponseAmended')->willReturnCallback(
			function (array $original) use (&$calls) {
				$calls[] = ['amended', $original['status']];
				return [];
			}
		);

		$handle = new ReflectionMethod(EndpointService::class, 'handleSchemaRequest');
		$path = $request['path'] ?? 'things';

		return $handle->invokeArgs(
			$this->service,
			[ObjectServiceMockBuilder::objectEntity($this, $endpointData, 'endpoint-1'), &$flowToken, $path]
		);
	}//end handle()

	/**
	 * The headers a response carries, read without booting the server.
	 *
	 * @param JSONResponse $response The response.
	 *
	 * @return array<string, string> The headers.
	 */
	private function headers(JSONResponse $response): array {
		return (new \ReflectionProperty(\OCP\AppFramework\Http\Response::class, 'headers'))->getValue($response);
	}//end headers()

	/**
	 * The flow token writes as [kind, status] pairs.
	 *
	 * @return array<int, array{0: string, 1: int}>
	 */
	private function writes(): array {
		return $this->tokenCalls;
	}//end writes()

	/**
	 * A list GET answers the paginated result, strips internal keys and sets both token responses.
	 *
	 * @return void
	 */
	public function testAListGetAnswersThePageAndStripsInternalParameters(): void {
		$requested = null;
		$this->mapper->method('findAllPaginated')->willReturnCallback(
			function (array $params) use (&$requested) {
				$requested = $params;
				return ['results' => [$this->entity(['id' => 'a'])], 'total' => 1, 'page' => 1, 'pages' => 1];
			}
		);

		$response = $this->handle([], ['method' => 'GET', 'parameters' => ['q' => 'x', '_route' => 'r', '_path' => 'p']]);

		$this->assertSame(200, $response->getStatus());
		$this->assertSame(['count' => 1, 'results' => [['uuid' => 'a', 'id' => 'a']]], $response->getData());
		$this->assertSame('x', $requested['q']);
		$this->assertArrayNotHasKey('_route', $requested);
		$this->assertArrayNotHasKey('_path', $requested);
		$this->assertSame([['original', 200], ['amended', 200]], $this->writes());
		$this->assertArrayNotHasKey('Content-Crs', $this->headers($response));
	}//end testAListGetAnswersThePageAndStripsInternalParameters()

	/**
	 * A GET echoes a non-empty Accept-Crs as Content-Crs; an empty one is not echoed.
	 *
	 * @return void
	 */
	public function testAGetEchoesAcceptCrsAsContentCrs(): void {
		$this->mapper->method('findAllPaginated')->willReturn(['results' => [], 'total' => 0, 'page' => 1, 'pages' => 1]);

		$response = $this->handle([], ['method' => 'GET', 'headers' => ['Accept-Crs' => 'EPSG:28992']]);
		$this->assertSame('EPSG:28992', $this->headers($response)['Content-Crs']);

		$empty = $this->handle([], ['method' => 'GET', 'headers' => ['Accept-Crs' => '']]);
		$this->assertArrayNotHasKey('Content-Crs', $this->headers($empty));
	}//end testAGetEchoesAcceptCrsAsContentCrs()

	/**
	 * The input mapping rewrites the parameters, and the path id wins over a mapped id.
	 *
	 * @return void
	 */
	public function testTheInputMappingRewritesParametersAndThePathIdIsAdded(): void {
		$mapping = $this->createMock(\OCA\OpenRegister\Db\Mapping::class);
		$this->mappingService->expects($this->once())->method('getMapping')->with('map-1')->willReturn($mapping);
		$this->mappingService->expects($this->once())->method('executeMapping')
			->with($mapping, ['raw' => 1])
			->willReturn(['mapped' => 2, 'id' => 'mapped-id']);
		$deleted = null;
		$this->mapper->method('delete')->willReturnCallback(
			function (array $where) use (&$deleted) {
				$deleted = $where;
				return true;
			}
		);

		$response = $this->handle(['inputMapping' => 'map-1'], ['method' => 'DELETE', 'parameters' => ['raw' => 1], 'path' => 'things/path-id']);

		$this->assertSame(204, $response->getStatus());
		$this->assertSame(['id' => 'path-id'], $deleted);
		$this->assertSame([['original', 204], ['amended', 204]], $this->writes());
	}//end testTheInputMappingRewritesParametersAndThePathIdIsAdded()

	/**
	 * VNG filter translation splits expand off, translates only non-system filters and expands each result.
	 *
	 * @return void
	 */
	public function testVngTranslationExpandsEveryResult(): void {
		$requested = null;
		$this->mapper->method('findAllPaginated')->willReturnCallback(
			function (array $params) use (&$requested) {
				$requested = $params;
				return ['results' => [$this->entity(['id' => 'a']), $this->entity(['id' => 'b'])], 'total' => 2, 'page' => 1, 'pages' => 1];
			}
		);
		$this->mappingService->expects($this->once())->method('translateVngFilterOperators')
			->with(['status__in' => 'x'])
			->willReturn(['status' => ['in' => 'x']]);
		$this->mappingService->method('expandRelations')->willReturnCallback(
			fn (array $data, array $expand) => $data + ['expanded' => $expand]
		);

		$response = $this->handle(
			['vngFilterTranslation' => true],
			['method' => 'GET', 'parameters' => ['expand' => 'a,b', '_limit' => 5, 'status__in' => 'x']]
		);

		$this->assertSame(['_limit' => 5, 'status' => ['in' => 'x']], array_intersect_key($requested, ['_limit' => 1, 'status' => 1, 'expand' => 1, 'status__in' => 1]));
		$this->assertSame(
			[['uuid' => 'a', 'id' => 'a', 'expanded' => ['a', 'b']], ['uuid' => 'b', 'id' => 'b', 'expanded' => ['a', 'b']]],
			$response->getData()['results']
		);
	}//end testVngTranslationExpandsEveryResult()

	/**
	 * VNG translation without expand leaves the results alone; expand on a single object expands it.
	 *
	 * @return void
	 */
	public function testVngTranslationWithoutExpandLeavesResultsAndExpandsASingleObject(): void {
		$this->mapper->method('findAllPaginated')->willReturn(['results' => [$this->entity(['id' => 'a'])], 'total' => 1, 'page' => 1, 'pages' => 1]);
		$this->mappingService->method('translateVngFilterOperators')->willReturnArgument(0);
		$this->mappingService->expects($this->never())->method('expandRelations');

		$response = $this->handle(['vngFilterTranslation' => true], ['method' => 'GET', 'parameters' => ['expand' => '']]);
		$this->assertSame(['count' => 1, 'results' => [['uuid' => 'a', 'id' => 'a']]], $response->getData());
	}//end testVngTranslationWithoutExpandLeavesResultsAndExpandsASingleObject()

	/**
	 * A single-object GET with expand expands the object; a 404 answer is not expanded.
	 *
	 * @return void
	 */
	public function testExpandOnASingleObjectAndNotOnAnError(): void {
		$this->mappingService->method('translateVngFilterOperators')->willReturnArgument(0);
		$this->mappingService->expects($this->once())->method('expandRelations')->willReturnCallback(
			fn (array $data, array $expand) => ['expanded' => $expand]
		);

		$response = $this->handle(['vngFilterTranslation' => true], ['method' => 'GET', 'parameters' => ['expand' => 'x'], 'path' => 'things/obj-1']);
		$this->assertSame(['expanded' => ['x']], $response->getData());

		$this->tokenCalls = [];
		$missing = $this->handle(['vngFilterTranslation' => true], ['method' => 'GET', 'parameters' => ['expand' => 'x'], 'path' => 'things/missing']);
		$this->assertSame(404, $missing->getStatus());
		$this->assertSame('not found', $missing->getData()['error']);
		$this->assertSame([['original', 404], ['amended', 404]], $this->writes());
	}//end testExpandOnASingleObjectAndNotOnAnError()

	/**
	 * Without VNG translation an expand parameter is passed on untouched and nothing is expanded.
	 *
	 * @return void
	 */
	public function testWithoutVngTranslationExpandIsAPlainParameter(): void {
		$requested = null;
		$this->mapper->method('findAllPaginated')->willReturnCallback(
			function (array $params) use (&$requested) {
				$requested = $params;
				return ['results' => [], 'total' => 0, 'page' => 1, 'pages' => 1];
			}
		);
		$this->mappingService->expects($this->never())->method('translateVngFilterOperators');
		$this->mappingService->expects($this->never())->method('expandRelations');

		$this->handle([], ['method' => 'GET', 'parameters' => ['expand' => 'a']]);
		$this->assertSame('a', $requested['expand']);
	}//end testWithoutVngTranslationExpandIsAPlainParameter()

	/**
	 * POST creates from the parameters and answers the object with references replaced.
	 *
	 * @return void
	 */
	public function testAPostCreatesTheObject(): void {
		$created = $this->entity(['id' => 'obj-1', 'name' => 'new']);
		$this->mapper->expects($this->once())->method('createFromArray')->with(['name' => 'new'])->willReturn($created);

		$response = $this->handle([], ['method' => 'POST', 'parameters' => ['name' => 'new', '_method' => 'POST']]);

		$this->assertSame(200, $response->getStatus());
		$this->assertSame('new', $response->getData()['name']);
		$this->assertSame([['original', 200], ['amended', 200]], $this->writes());
	}//end testAPostCreatesTheObject()

	/**
	 * PUT with putPatchSemantics and missing mandatory fields answers 400 naming them.
	 *
	 * @return void
	 */
	public function testAPutMissingMandatoryFieldsAnswersBadRequest(): void {
		$this->mapper->expects($this->never())->method('updateFromArray');

		$response = $this->handle(['putPatchSemantics' => true], ['method' => 'PUT', 'parameters' => ['name' => 'n', 'kind' => null], 'path' => 'things/obj-1']);

		$this->assertSame(400, $response->getStatus());
		$this->assertSame(['error' => 'PUT requires all mandatory fields', 'fields' => ['kind']], $response->getData());
		$this->assertSame([['original', 400], ['amended', 400]], $this->writes());
	}//end testAPutMissingMandatoryFieldsAnswersBadRequest()

	/**
	 * PUT updates with the unmapped request body, as a full (non-patch) update.
	 *
	 * @return void
	 */
	public function testAPutUpdatesWithTheRawBody(): void {
		$updated = $this->entity(['id' => 'obj-1', 'name' => 'put']);
		$this->mappingService->method('getMapping')->willReturn($this->createMock(\OCA\OpenRegister\Db\Mapping::class));
		$this->mappingService->method('executeMapping')->willReturn(['mapped' => true]);
		$this->mapper->expects($this->once())->method('updateFromArray')
			->with('obj-1', ['name' => 'put', 'kind' => 'k'], true, false)
			->willReturn($updated);

		$response = $this->handle(
			['putPatchSemantics' => true, 'inputMapping' => 'm'],
			['method' => 'PUT', 'parameters' => ['name' => 'put', 'kind' => 'k'], 'path' => 'things/obj-1']
		);

		$this->assertSame(200, $response->getStatus());
		$this->assertSame('put', $response->getData()['name']);
		$this->assertSame([['original', 200], ['amended', 200]], $this->writes());
	}//end testAPutUpdatesWithTheRawBody()

	/**
	 * PUT without putPatchSemantics does not check mandatory fields.
	 *
	 * @return void
	 */
	public function testAPutWithoutSemanticsSkipsTheMandatoryCheck(): void {
		$updated = $this->entity(['id' => 'obj-1']);
		$this->mapper->expects($this->once())->method('updateFromArray')->with('obj-1', [], true, false)->willReturn($updated);

		$this->assertSame(200, $this->handle([], ['method' => 'PUT', 'path' => 'things/obj-1'])->getStatus());
	}//end testAPutWithoutSemanticsSkipsTheMandatoryCheck()

	/**
	 * PATCH updates as a patch, even with putPatchSemantics on and fields missing.
	 *
	 * @return void
	 */
	public function testAPatchUpdatesAsAPatch(): void {
		$updated = $this->entity(['id' => 'obj-1', 'name' => 'p']);
		$this->mapper->expects($this->once())->method('updateFromArray')->with('obj-1', ['name' => 'p'], true, true)->willReturn($updated);

		$response = $this->handle(['putPatchSemantics' => true], ['method' => 'PATCH', 'parameters' => ['name' => 'p'], 'path' => 'things/obj-1']);

		$this->assertSame('p', $response->getData()['name']);
		$this->assertSame([['original', 200], ['amended', 200]], $this->writes());
	}//end testAPatchUpdatesAsAPatch()

	/**
	 * DELETE without an id answers 400 and sets only the original response.
	 *
	 * @return void
	 */
	public function testADeleteWithoutAnIdAnswersBadRequestAndLeavesTheAmendedResponse(): void {
		$this->mapper->expects($this->never())->method('delete');

		$response = $this->handle([], ['method' => 'DELETE']);

		$this->assertSame(400, $response->getStatus());
		$this->assertSame(['error' => 'No id given to delete'], $response->getData());
		$this->assertSame([['original', 400]], $this->writes());
	}//end testADeleteWithoutAnIdAnswersBadRequestAndLeavesTheAmendedResponse()

	/**
	 * DELETE that the mapper does not confirm answers 500.
	 *
	 * @return void
	 */
	public function testAFailedDeleteAnswersServerError(): void {
		$this->mapper->method('delete')->willReturn(false);

		$response = $this->handle([], ['method' => 'DELETE', 'path' => 'things/obj-9']);

		$this->assertSame(500, $response->getStatus());
		$this->assertSame(['error' => 'Something went wrong deleting object: obj-9'], $response->getData());
		$this->assertSame([['original', 500], ['amended', 500]], $this->writes());
	}//end testAFailedDeleteAnswersServerError()

	/**
	 * An unsupported method throws and writes nothing on the token.
	 *
	 * @return void
	 */
	public function testAnUnsupportedMethodThrows(): void {
		try {
			$this->handle([], ['method' => 'OPTIONS']);
			$this->fail('An unsupported method must throw.');
		} catch (Exception $exception) {
			$this->assertSame('Unsupported HTTP method', $exception->getMessage());
		}

		$this->assertSame([], $this->writes());
	}//end testAnUnsupportedMethodThrows()

	/**
	 * A validation exception is answered by the mapper's validate handler, without token writes.
	 *
	 * @return void
	 */
	public function testAValidationExceptionIsAnsweredByTheValidateHandler(): void {
		$exception = new \OCA\OpenRegister\Exception\ValidationException('invalid');
		$this->mapper->method('createFromArray')->willThrowException($exception);
		$answer = new JSONResponse(['invalid' => true], 422);
		$handler = new class($answer) {
			/**
			 * @var array<int, \Exception>
			 */
			public array $handled = [];

			/**
			 * @param JSONResponse $answer The answer to give.
			 */
			public function __construct(private JSONResponse $answer) {
			}

			/**
			 * @param \Exception $exception The validation failure.
			 *
			 * @return JSONResponse
			 */
			public function handleValidationException(\Exception $exception): JSONResponse {
				$this->handled[] = $exception;
				return $this->answer;
			}
		};
		$this->mapper->method('getValidateHandler')->willReturn($handler);

		$this->assertSame($answer, $this->handle([], ['method' => 'POST', 'parameters' => ['name' => 'x']]));
		$this->assertSame([$exception], $handler->handled);
		$this->assertSame([], $this->writes());
	}//end testAValidationExceptionIsAnsweredByTheValidateHandler()

	/**
	 * Any other exception from the mapper is rethrown.
	 *
	 * @return void
	 */
	public function testAnotherExceptionIsRethrown(): void {
		$this->mapper->method('createFromArray')->willThrowException(new \RuntimeException('db down'));
		$this->mapper->expects($this->never())->method('getValidateHandler');

		$this->expectException(\RuntimeException::class);
		$this->expectExceptionMessage('db down');
		$this->handle([], ['method' => 'POST']);
	}//end testAnotherExceptionIsRethrown()
}//end class

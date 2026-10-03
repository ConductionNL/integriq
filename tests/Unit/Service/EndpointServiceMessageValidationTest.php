<?php

/**
 * An endpoint validates its request and its proxied answer (REQ-MSV-002).
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 *
 * @spec openspec/changes/mapping-message-schema-validation/specs/message-schema-validation/spec.md#requirement-an-endpoint-validates-its-request-and-its-proxied-answer-req-msv-002
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Service;

use OCA\Integriq\Rule\AvgBsnPolicyRule;
use OCA\Integriq\Rule\CompositeFanoutRule;
use OCA\Integriq\Rule\ReferenceNumberRule;
use OCA\Integriq\Service\ApprovalService;
use OCA\Integriq\Service\AuthorizationService;
use OCA\Integriq\Service\CallService;
use OCA\Integriq\Service\ConsumerScopeService;
use OCA\Integriq\Service\EndpointService;
use OCA\Integriq\Service\FlowRunnerService;
use OCA\Integriq\Service\MappingService;
use OCA\Integriq\Service\MessageValidation\EndpointMessageGate;
use OCA\Integriq\Service\MessageValidation\JsonSchemaChecker;
use OCA\Integriq\Service\MessageValidation\OpenApiChecker;
use OCA\Integriq\Service\MessageValidation\XsdChecker;
use OCA\Integriq\Service\MessageValidationService;
use OCA\Integriq\Service\ObjectService;
use OCA\Integriq\Service\RateLimit\InboundRateLimitService;
use OCA\Integriq\Service\RuleService;
use OCA\Integriq\Service\StorageService;
use OCA\Integriq\Service\SynchronizationService;
use OCA\Integriq\Service\WebhookSignatureService;
use OCA\Integriq\Tests\Helpers\ObjectServiceMockBuilder;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\FileService as ORFileService;
use OCA\OpenRegister\Service\ObjectService as ORObjectService;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IConfig;
use OCP\IRequest;
use OCP\IRequestId;
use OCP\IURLGenerator;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\NullLogger;

/**
 * The whole request path of a proxying endpoint, with the real gate and the
 * real checkers; only the source call, the stored objects and the raw body
 * are stand-ins.
 */
class EndpointServiceMessageValidationTest extends TestCase {

	/**
	 * The message schema the endpoint references.
	 */
	private const SCHEMA_UUID = 'b7f1c2d3-4e5f-4a6b-8c7d-9e0f1a2b3c4d';

	/**
	 * The stored objects.
	 *
	 * @var ORObjectService&MockObject
	 */
	private ORObjectService $objects;

	/**
	 * The source call.
	 *
	 * @var CallService&MockObject
	 */
	private CallService $callService;

	/**
	 * Build the service; the raw body is the given text.
	 *
	 * @param string $rawBody The request body.
	 * @param bool   $withGate Whether the gate is injected.
	 *
	 * @return EndpointService
	 */
	private function service(string $rawBody, bool $withGate = true): EndpointService {
		$logger = new NullLogger();
		$this->objects = ObjectServiceMockBuilder::make($this);
		$this->objects->method('find')->willReturnCallback(
			fn ($id, ?string $register = null, ?string $schema = null): ?ObjectEntity => match ($schema) {
				'message_schema' => ObjectServiceMockBuilder::objectEntity($this, $this->personSchema(), self::SCHEMA_UUID),
				'source' => ObjectServiceMockBuilder::objectEntity($this, ['location' => 'https://bron.example.nl', 'isEnabled' => true], 'source-1'),
				default => null,
			}
		);

		$this->callService = $this->createMock(CallService::class);
		$this->callService->method('renderEndpointPath')->willReturn('/personen');

		$consumerScope = $this->createMock(ConsumerScopeService::class);
		$consumerScope->method('isAllowed')->willReturn(true);

		$json = new JsonSchemaChecker();
		$gate = null;
		if ($withGate === true) {
			$gate = new EndpointMessageGate(
				new MessageValidationService($json, new XsdChecker(), new OpenApiChecker($json)),
				$this->objects,
				$logger
			);
		}

		$service = $this->getMockBuilder(EndpointService::class)
			->setConstructorArgs(
				[
					$this->createMock(ObjectService::class),
					$this->callService,
					$logger,
					$this->createMock(IURLGenerator::class),
					$this->createMock(MappingService::class),
					$this->objects,
					$this->createMock(IConfig::class),
					$this->createMock(StorageService::class),
					$this->createMock(AuthorizationService::class),
					$this->createMock(ContainerInterface::class),
					$this->createMock(SynchronizationService::class),
					$this->createMock(RuleService::class),
					new WebhookSignatureService($logger),
					$this->createMock(InboundRateLimitService::class),
					new CompositeFanoutRule($this->objects, $logger),
					new ReferenceNumberRule(),
					new AvgBsnPolicyRule(),
					$this->createMock(ApprovalService::class),
					$this->createMock(IRequestId::class),
					$this->createMock(FlowRunnerService::class),
					$consumerScope,
					$this->createMock(SchemaMapper::class),
					$this->createMock(ORFileService::class),
					null,
					null,
					$gate,
				]
			)
			->onlyMethods(['getRawContent'])
			->getMock();
		$service->method('getRawContent')->willReturn($rawBody);

		return $service;
	}//end service()

	/**
	 * A JSON Schema message schema that requires a bsn.
	 *
	 * @return array<string, mixed>
	 */
	private function personSchema(): array {
		return [
			'name' => 'Person',
			'kind' => 'json-schema',
			'version' => '1',
			'document' => '{"type":"object","required":["bsn"],"properties":{"bsn":{"type":"string"}}}',
		];
	}//end personSchema()

	/**
	 * A proxying endpoint with the given validation block.
	 *
	 * @param array<string, mixed> $validation The validation block.
	 *
	 * @return ObjectEntity
	 */
	private function endpoint(array $validation): ObjectEntity {
		return ObjectServiceMockBuilder::objectEntity(
			$this,
			[
				'name' => 'Personen',
				'method' => 'POST',
				'endpoint' => '/personen',
				'targetType' => 'api',
				'targetId' => 'source-1',
				'validation' => $validation,
			],
			'endpoint-1'
		);
	}//end endpoint()

	/**
	 * A POST request.
	 *
	 * @return IRequest
	 */
	private function request(): IRequest {
		$request = $this->createMock(IRequest::class);
		$request->method('getMethod')->willReturn('POST');
		$request->method('getParams')->willReturn([]);
		$request->method('getHeader')->willReturn('');

		return $request;
	}//end request()

	/**
	 * The call log the source call answers with.
	 *
	 * @param string $body The answer body.
	 *
	 * @return ObjectEntity
	 */
	private function callLog(string $body): ObjectEntity {
		return ObjectServiceMockBuilder::objectEntity(
			$this,
			['statusCode' => 200, 'direction' => 'outbound', 'response' => ['statusCode' => 200, 'body' => $body]],
			'call-log-1'
		);
	}//end callLog()

	/**
	 * Mode refuse: a request without bsn is answered 400, names /bsn, and the source is not called.
	 *
	 * @return void
	 */
	public function testARequestMissingARequiredFieldIsRefusedAndNotDispatched(): void {
		$service = $this->service(rawBody: '{"naam":"Jansen"}');
		$this->callService->expects($this->never())->method('call');

		$response = $service->handleRequest(
			$this->endpoint(['mode' => 'refuse', 'request' => ['messageSchema' => self::SCHEMA_UUID]]),
			$this->request(),
			'/personen'
		);

		$this->assertInstanceOf(JSONResponse::class, $response);
		$this->assertSame(400, $response->getStatus());
		$this->assertSame('application/problem+json', $response->getHeaders()['Content-Type']);
		$this->assertContains('/bsn', array_column($response->getData()['errors'], 'path'));
	}//end testARequestMissingARequiredFieldIsRefusedAndNotDispatched()

	/**
	 * Mode record: the request is dispatched and the call log carries the error.
	 *
	 * @return void
	 */
	public function testRecordModeDispatchesAndWritesTheErrorToTheCallLog(): void {
		$service = $this->service(rawBody: '{"naam":"Jansen"}');
		$this->callService->expects($this->once())->method('call')->willReturn($this->callLog('{"ok":true}'));

		$saved = [];
		$this->objects->method('saveObject')->willReturnCallback(
			function ($object, ?string $register = null, ?string $schema = null, ?string $uuid = null) use (&$saved): ObjectEntity {
				$saved[] = ['object' => $object, 'schema' => $schema, 'uuid' => $uuid];
				return new ObjectEntity();
			}
		);

		$response = $service->handleRequest(
			$this->endpoint(['mode' => 'record', 'request' => ['messageSchema' => self::SCHEMA_UUID]]),
			$this->request(),
			'/personen'
		);

		$this->assertSame(200, $response->getStatus());
		$this->assertCount(1, $saved);
		$this->assertSame('call_log', $saved[0]['schema']);
		$this->assertSame('call-log-1', $saved[0]['uuid']);
		$this->assertSame('request', $saved[0]['object']['validation'][0]['direction']);
		$this->assertContains('/bsn', array_column($saved[0]['object']['validation'][0]['errors'], 'path'));
	}//end testRecordModeDispatchesAndWritesTheErrorToTheCallLog()

	/**
	 * Mode refuse: a proxied answer that fails is answered 502.
	 *
	 * @return void
	 */
	public function testAFailingProxiedAnswerIsRefusedWith502(): void {
		$service = $this->service(rawBody: '{"bsn":"999993653"}');
		$this->callService->expects($this->once())->method('call')->willReturn($this->callLog('{"naam":"Jansen"}'));

		$response = $service->handleRequest(
			$this->endpoint(['mode' => 'refuse', 'request' => ['messageSchema' => self::SCHEMA_UUID], 'response' => ['messageSchema' => self::SCHEMA_UUID]]),
			$this->request(),
			'/personen'
		);

		$this->assertSame(502, $response->getStatus());
		$this->assertSame('application/problem+json', $response->getHeaders()['Content-Type']);
		$this->assertContains('/bsn', array_column($response->getData()['errors'], 'path'));
	}//end testAFailingProxiedAnswerIsRefusedWith502()

	/**
	 * A valid request and answer pass untouched, and nothing is written.
	 *
	 * @return void
	 */
	public function testAValidRequestAndAnswerPass(): void {
		$service = $this->service(rawBody: '{"bsn":"999993653"}');
		$this->callService->expects($this->once())->method('call')->willReturn($this->callLog('{"bsn":"999993653"}'));
		$this->objects->expects($this->never())->method('saveObject');

		$response = $service->handleRequest(
			$this->endpoint(['mode' => 'refuse', 'request' => ['messageSchema' => self::SCHEMA_UUID], 'response' => ['messageSchema' => self::SCHEMA_UUID]]),
			$this->request(),
			'/personen'
		);

		$this->assertSame(200, $response->getStatus());
	}//end testAValidRequestAndAnswerPass()

	/**
	 * A declared validation is never skipped: without the gate, mode refuse refuses.
	 *
	 * @return void
	 */
	public function testADeclaredValidationWithoutTheValidatorIsRefusedNotSkipped(): void {
		$service = $this->service(rawBody: '{"bsn":"999993653"}', withGate: false);
		$this->callService->expects($this->never())->method('call');

		$response = $service->handleRequest(
			$this->endpoint(['mode' => 'refuse', 'request' => ['messageSchema' => self::SCHEMA_UUID]]),
			$this->request(),
			'/personen'
		);

		$this->assertSame(500, $response->getStatus());
	}//end testADeclaredValidationWithoutTheValidatorIsRefusedNotSkipped()

	/**
	 * An endpoint without a validation block behaves as before.
	 *
	 * @return void
	 */
	public function testAnEndpointWithoutValidationIsUnchanged(): void {
		$service = $this->service(rawBody: '{"naam":"Jansen"}');
		$this->callService->expects($this->once())->method('call')->willReturn($this->callLog('{"ok":true}'));
		$this->objects->expects($this->never())->method('saveObject');

		$response = $service->handleRequest($this->endpoint([]), $this->request(), '/personen');

		$this->assertSame(200, $response->getStatus());
	}//end testAnEndpointWithoutValidationIsUnchanged()
}//end class

<?php

/**
 * A live gateway call to a paced source whose turn is too far away is
 * answered 429 with Retry-After (REQ-RLPC-002).
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @spec openspec/changes/sources-outbound-rate-limit-pacing/specs/http-call-engine/spec.md#requirement-a-live-call-waits-briefly-background-work-waits-for-the-window-req-rlpc-002
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
use OCA\Integriq\Service\Consumer\OpenRegisterCredentialBridge;
use OCA\Integriq\Service\CallService;
use OCA\Integriq\Service\ConsumerScopeService;
use OCA\Integriq\Service\EndpointService;
use OCA\Integriq\Service\FlowRunnerService;
use OCA\Integriq\Service\MappingService;
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
use OCP\AppFramework\Http\Response;
use OCP\IConfig;
use OCP\IRequest;
use OCP\IRequestId;
use OCP\IURLGenerator;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\NullLogger;
use ReflectionProperty;

/**
 * The proxy path of an endpoint; only the source call and the stored objects are stand-ins.
 *
 * @covers \OCA\Integriq\Service\EndpointService
 */
class EndpointServicePacingTest extends TestCase {

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
	 *
	 * @return EndpointService
	 */
	private function service(string $rawBody): EndpointService {
		$logger = new NullLogger();
		$this->objects = ObjectServiceMockBuilder::make($this);
		$this->objects->method('find')->willReturnCallback(
			fn ($id, ?string $register = null, ?string $schema = null): ?ObjectEntity => match ($schema) {
				'source' => ObjectServiceMockBuilder::objectEntity($this, ['location' => 'https://bron.example.nl', 'isEnabled' => true], 'source-1'),
				'call_log' => ObjectServiceMockBuilder::objectEntity($this, ['statusCode' => 200, 'direction' => 'outbound', 'response' => ['statusCode' => 200]], 'call-log-1'),
				default => null,
			}
		);

		$this->callService = $this->createMock(CallService::class);
		$this->callService->method('renderEndpointPath')->willReturn('/personen');

		$consumerScope = $this->createMock(ConsumerScopeService::class);
		$consumerScope->method('isAllowed')->willReturn(true);

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
					$this->createMock(OpenRegisterCredentialBridge::class),
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
				]
			)
			->onlyMethods(['getRawContent'])
			->getMock();
		$service->method('getRawContent')->willReturn($rawBody);

		return $service;
	}//end service()

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
	 * A header the response set itself; getHeaders() needs a running server.
	 *
	 * @param Response $response The response.
	 * @param string   $name     The header name.
	 *
	 * @return string|null
	 */
	private function header(Response $response, string $name): ?string {
		$headers = (new ReflectionProperty(Response::class, 'headers'))->getValue($response);
		return $headers[$name] ?? null;
	}//end header()

	/**
	 * The source's paced turn was too far away: 429 with Retry-After, and the
	 * call was made as a live one.
	 *
	 * @return void
	 */
	public function testABusyPacedSourceIsAnswered429WithRetryAfter(): void {
		$service = $this->service(rawBody: '{"naam":"Jansen"}');
		$this->callService->expects($this->once())->method('call')
			->with($this->anything(), '/personen', 'POST', $this->callback(static fn (array $config): bool => ($config['pacing'] ?? null) === 'live'))
			->willReturn(
				ObjectServiceMockBuilder::objectEntity(
					$this,
					['statusCode' => 429, 'direction' => 'outbound', 'retryAfter' => 5, 'statusMessage' => 'This source is paced: the next call is due in 5 seconds, longer than this call may wait.'],
					'call-log-1'
				)
			);

		$response = $service->handleRequest($this->endpoint([]), $this->request(), '/personen');

		$this->assertSame(429, $response->getStatus());
		$this->assertSame('5', $this->header($response, 'Retry-After'));
		$this->assertSame(['error' => 'The source behind this endpoint is busy. Try again later.'], $response->getData());
	}//end testABusyPacedSourceIsAnswered429WithRetryAfter()

	/**
	 * A 429 the source itself answered is passed on as before, without a Retry-After of integriq's own.
	 *
	 * @return void
	 */
	public function testASourcesOwn429IsPassedOn(): void {
		$service = $this->service(rawBody: '{"naam":"Jansen"}');
		$this->callService->method('call')->willReturn(
			ObjectServiceMockBuilder::objectEntity(
				$this,
				['statusCode' => 429, 'direction' => 'outbound', 'response' => ['statusCode' => 429, 'body' => '{"fout":"te veel"}']],
				'call-log-1'
			)
		);

		$response = $service->handleRequest($this->endpoint([]), $this->request(), '/personen');

		$this->assertSame(429, $response->getStatus());
		$this->assertNull($this->header($response, 'Retry-After'));
		$this->assertSame('{"fout":"te veel"}', $response->getData()['body']);
	}//end testASourcesOwn429IsPassedOn()
}//end class

<?php

/**
 * EndpointServiceEntryPointProblemTest.
 *
 * The entry points that do not pass through handleRequest() — the approval
 * resume and the trace replay — answer an error as a problem document too.
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
 * @spec openspec/changes/archive/2026-11-25-gateway-response-cache-and-problem-errors/specs/endpoint-runtime/spec.md#requirement-every-gateway-error-is-a-problem-document-req-rcpe-002
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Service;

use Exception;
use OCA\Integriq\Http\ProblemResponse;
use OCA\Integriq\Rule\AvgBsnPolicyRule;
use OCA\Integriq\Rule\CompositeFanoutRule;
use OCA\Integriq\Rule\ReferenceNumberRule;
use OCA\Integriq\Service\CallService;
use OCA\Integriq\Service\Consumer\OpenRegisterCredentialBridge;
use OCA\Integriq\Service\EndpointService;
use OCA\Integriq\Service\FlowRunnerService;
use OCA\Integriq\Service\Helper\ExecutionTraceContext;
use OCA\Integriq\Service\Helper\FlowToken;
use OCA\Integriq\Service\MappingService;
use OCA\Integriq\Service\ObjectService;
use OCA\Integriq\Service\RuleService;
use OCA\Integriq\Service\StorageService;
use OCA\Integriq\Service\SynchronizationService;
use OCA\Integriq\Tests\Helpers\ObjectServiceMockBuilder;
use OCA\OpenRegister\Db\ObjectEntity;
use OCP\IConfig;
use OCP\IRequest;
use OCP\IRequestId;
use OCP\IURLGenerator;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Errors from resumeFromApproval() and replay() are problem documents.
 */
class EndpointServiceEntryPointProblemTest extends TestCase {

	/**
	 * The OpenRegister object service double the subject reads rules through.
	 *
	 * @var MockObject
	 */
	private MockObject $orObjectService;

	/**
	 * The subject under test.
	 *
	 * @var EndpointService
	 */
	private EndpointService $service;

	/**
	 * Build the subject with inert collaborators.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->orObjectService = ObjectServiceMockBuilder::make(test: $this);
		$logger = $this->createMock(originalClassName: LoggerInterface::class);
		$urlGenerator = $this->createMock(originalClassName: IURLGenerator::class);
		$urlGenerator->method('getBaseUrl')->willReturn('https://example.org');
		$consumerScope = $this->createMock(originalClassName: \OCA\Integriq\Service\ConsumerScopeService::class);
		$consumerScope->method('isAllowed')->willReturn(true);
		$requestId = $this->createMock(originalClassName: IRequestId::class);
		$requestId->method('getId')->willReturn('req-replay-1');

		$this->service = new EndpointService(
			objectService: $this->createMock(originalClassName: ObjectService::class),
			callService: $this->createMock(originalClassName: CallService::class),
			logger: $logger,
			urlGenerator: $urlGenerator,
			mappingService: $this->createMock(originalClassName: MappingService::class),
			orObjectService: $this->orObjectService,
			config: $this->createMock(originalClassName: IConfig::class),
			storageService: $this->createMock(originalClassName: StorageService::class),
			authorizationService: $this->createMock(originalClassName: OpenRegisterCredentialBridge::class),
			containerInterface: $this->createMock(originalClassName: ContainerInterface::class),
			synchronizationService: $this->createMock(originalClassName: SynchronizationService::class),
			ruleService: $this->createMock(originalClassName: RuleService::class),
			webhookSignatureService: new \OCA\Integriq\Service\WebhookSignatureService(logger: $logger),
			rateLimitService: $this->createMock(originalClassName: \OCA\Integriq\Service\RateLimit\InboundRateLimitService::class),
			compositeFanoutRule: new CompositeFanoutRule(orObjectService: $this->orObjectService, logger: $logger),
			referenceNumberRule: new ReferenceNumberRule(),
			avgBsnPolicyRule: new AvgBsnPolicyRule(),
			approvalService: $this->createMock(originalClassName: \OCA\Integriq\Service\ApprovalService::class),
			requestId: $requestId,
			flowRunnerService: $this->createMock(originalClassName: FlowRunnerService::class),
			consumerScopeService: $consumerScope,
			schemaMapper: $this->createMock(originalClassName: \OCA\OpenRegister\Db\SchemaMapper::class),
			orFileService: $this->createMock(originalClassName: \OCA\OpenRegister\Service\FileService::class),
		);
	}//end setUp()

	/**
	 * An endpoint whose configuration cannot be read.
	 *
	 * @return ObjectEntity
	 */
	private function brokenEndpoint(): ObjectEntity {
		$endpoint = $this->createMock(originalClassName: ObjectEntity::class);
		$endpoint->method('getObject')->willThrowException(new Exception(message: 'storage gone'));
		$endpoint->method('getUuid')->willReturn('endpoint-broken');

		return $endpoint;
	}//end brokenEndpoint()

	/**
	 * An endpoint with one rule that fails while the pipeline runs.
	 *
	 * The rule's own configuration throws, so the pipeline answers its plain
	 * 500 `Rule processing failed`, which the entry point must not pass on as is.
	 *
	 * @return ObjectEntity
	 */
	private function endpointWithFailingRule(): ObjectEntity {
		$rule = $this->createMock(originalClassName: ObjectEntity::class);
		$rule->method('getObject')->willThrowException(new Exception(message: 'rule unreadable'));
		$this->orObjectService->method('find')->willReturn($rule);

		return ObjectServiceMockBuilder::objectEntity(
			test: $this,
			body: ['method' => 'GET', 'rules' => ['rule-1', 'rule-2'], 'targetType' => 'api'],
			uuid: 'endpoint-with-rule'
		);
	}//end endpointWithFailingRule()

	/**
	 * Assert a response is a 500 problem document that still carries `error`.
	 *
	 * @param mixed  $response The answer.
	 * @param string $error    The expected deprecated `error` member.
	 *
	 * @return array<string,mixed> The document.
	 */
	private function assertInternalProblem(mixed $response, string $error): array {
		$this->assertInstanceOf(expected: ProblemResponse::class, actual: $response);
		$this->assertSame(expected: 500, actual: $response->getStatus());
		$headers = (new \ReflectionProperty(class: \OCP\AppFramework\Http\Response::class, property: 'headers'))->getValue(object: $response);
		$this->assertSame(expected: ProblemResponse::CONTENT_TYPE, actual: $headers['Content-Type']);
		$document = $response->getData();
		$this->assertSame(expected: ProblemResponse::TYPE_BASE . 'upstream-unavailable', actual: $document['type']);
		$this->assertSame(expected: $error, actual: $document['error']);
		$this->assertSame(expected: $error, actual: $document['detail']);

		return $document;
	}//end assertInternalProblem()

	/**
	 * A resume that throws answers a problem document naming the request.
	 *
	 * @return void
	 */
	public function testAFailedResumeIsAProblemDocument(): void {
		$request = $this->createMock(originalClassName: IRequest::class);
		$request->method('getId')->willReturn('req-resume-1');
		$request->method('getMethod')->willReturn('POST');

		$response = $this->service->resumeFromApproval(
			endpoint: $this->brokenEndpoint(),
			request: $request,
			flowToken: new FlowToken(),
			resumeAfterOrder: 1,
			path: ''
		);

		$document = $this->assertInternalProblem(response: $response, error: 'Internal server error');
		$this->assertSame(expected: 'req-resume-1', actual: $document['instance']);
	}//end testAFailedResumeIsAProblemDocument()

	/**
	 * A resume whose pipeline answers a plain error passes it on as a problem document.
	 *
	 * @return void
	 */
	public function testAResumedPipelineErrorIsAProblemDocument(): void {
		$request = $this->createMock(originalClassName: IRequest::class);
		$request->method('getId')->willReturn('req-resume-2');
		$request->method('getMethod')->willReturn('POST');

		$response = $this->service->resumeFromApproval(
			endpoint: $this->endpointWithFailingRule(),
			request: $request,
			flowToken: new FlowToken(),
			resumeAfterOrder: 0,
			path: ''
		);

		$document = $this->assertInternalProblem(response: $response, error: 'Rule processing failed');
		$this->assertSame(expected: 'req-resume-2', actual: $document['instance']);
	}//end testAResumedPipelineErrorIsAProblemDocument()

	/**
	 * A replay that throws answers a problem document.
	 *
	 * @return void
	 */
	public function testAFailedReplayIsAProblemDocument(): void {
		$response = $this->service->replay(
			endpoint: $this->brokenEndpoint(),
			requestSnapshot: ['method' => 'GET', 'parameters' => [], 'path' => ''],
			trace: new ExecutionTraceContext(entryPoint: 'replay')
		);

		$document = $this->assertInternalProblem(response: $response, error: 'Internal server error');
		$this->assertArrayNotHasKey(key: 'instance', array: $document);
	}//end testAFailedReplayIsAProblemDocument()

	/**
	 * A replay whose pipeline answers a plain error passes it on as a problem document.
	 *
	 * @return void
	 */
	public function testAReplayedPipelineErrorIsAProblemDocument(): void {
		$response = $this->service->replay(
			endpoint: $this->endpointWithFailingRule(),
			requestSnapshot: ['method' => 'GET', 'parameters' => [], 'path' => ''],
			trace: new ExecutionTraceContext(entryPoint: 'replay')
		);

		$document = $this->assertInternalProblem(response: $response, error: 'Rule processing failed');
		$this->assertSame(expected: 'req-replay-1', actual: $document['instance']);
	}//end testAReplayedPipelineErrorIsAProblemDocument()
}//end class

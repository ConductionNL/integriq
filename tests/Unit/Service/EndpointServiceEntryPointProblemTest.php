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
 * @spec openspec/changes/gateway-response-cache-and-problem-errors/specs/endpoint-runtime/spec.md#requirement-every-gateway-error-is-a-problem-document-req-rcpe-002
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

		$this->orObjectService = ObjectServiceMockBuilder::make($this);
		$logger = $this->createMock(LoggerInterface::class);
		$urlGenerator = $this->createMock(IURLGenerator::class);
		$urlGenerator->method('getBaseUrl')->willReturn('https://example.org');
		$consumerScope = $this->createMock(\OCA\Integriq\Service\ConsumerScopeService::class);
		$consumerScope->method('isAllowed')->willReturn(true);
		$requestId = $this->createMock(IRequestId::class);
		$requestId->method('getId')->willReturn('req-replay-1');

		$this->service = new EndpointService(
			$this->createMock(ObjectService::class),
			$this->createMock(CallService::class),
			$logger,
			$urlGenerator,
			$this->createMock(MappingService::class),
			$this->orObjectService,
			$this->createMock(IConfig::class),
			$this->createMock(StorageService::class),
			$this->createMock(OpenRegisterCredentialBridge::class),
			$this->createMock(ContainerInterface::class),
			$this->createMock(SynchronizationService::class),
			$this->createMock(RuleService::class),
			new \OCA\Integriq\Service\WebhookSignatureService($logger),
			$this->createMock(\OCA\Integriq\Service\RateLimit\InboundRateLimitService::class),
			new CompositeFanoutRule($this->orObjectService, $logger),
			new ReferenceNumberRule(),
			new AvgBsnPolicyRule(),
			$this->createMock(\OCA\Integriq\Service\ApprovalService::class),
			$requestId,
			$this->createMock(FlowRunnerService::class),
			$consumerScope,
			$this->createMock(\OCA\OpenRegister\Db\SchemaMapper::class),
			$this->createMock(\OCA\OpenRegister\Service\FileService::class),
		);
	}//end setUp()

	/**
	 * An endpoint whose configuration cannot be read.
	 *
	 * @return ObjectEntity
	 */
	private function brokenEndpoint(): ObjectEntity {
		$endpoint = $this->createMock(ObjectEntity::class);
		$endpoint->method('getObject')->willThrowException(new Exception('storage gone'));
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
		$rule = $this->createMock(ObjectEntity::class);
		$rule->method('getObject')->willThrowException(new Exception('rule unreadable'));
		$this->orObjectService->method('find')->willReturn($rule);

		return ObjectServiceMockBuilder::objectEntity(
			$this,
			['method' => 'GET', 'rules' => ['rule-1', 'rule-2'], 'targetType' => 'api'],
			'endpoint-with-rule'
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
		$this->assertInstanceOf(ProblemResponse::class, $response);
		$this->assertSame(500, $response->getStatus());
		$headers = (new \ReflectionProperty(\OCP\AppFramework\Http\Response::class, 'headers'))->getValue($response);
		$this->assertSame(ProblemResponse::CONTENT_TYPE, $headers['Content-Type']);
		$document = $response->getData();
		$this->assertSame(ProblemResponse::TYPE_BASE . 'upstream-unavailable', $document['type']);
		$this->assertSame($error, $document['error']);
		$this->assertSame($error, $document['detail']);

		return $document;
	}//end assertInternalProblem()

	/**
	 * A resume that throws answers a problem document naming the request.
	 *
	 * @return void
	 */
	public function testAFailedResumeIsAProblemDocument(): void {
		$request = $this->createMock(IRequest::class);
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
		$this->assertSame('req-resume-1', $document['instance']);
	}//end testAFailedResumeIsAProblemDocument()

	/**
	 * A resume whose pipeline answers a plain error passes it on as a problem document.
	 *
	 * @return void
	 */
	public function testAResumedPipelineErrorIsAProblemDocument(): void {
		$request = $this->createMock(IRequest::class);
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
		$this->assertSame('req-resume-2', $document['instance']);
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
			trace: new ExecutionTraceContext('replay')
		);

		$document = $this->assertInternalProblem(response: $response, error: 'Internal server error');
		$this->assertArrayNotHasKey('instance', $document);
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
			trace: new ExecutionTraceContext('replay')
		);

		$document = $this->assertInternalProblem(response: $response, error: 'Rule processing failed');
		$this->assertSame('req-replay-1', $document['instance']);
	}//end testAReplayedPipelineErrorIsAProblemDocument()
}//end class

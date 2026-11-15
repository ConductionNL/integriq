<?php

/**
 * Integration test: an endpoint rule chain suspends on an `approval` rule,
 * and the approver's decision resumes or ends it, through the real
 * EndpointService, ApprovalService, ApprovalDecisionService and
 * ApprovalsController.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Integration
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * As the other tests in this directory: "integration" means the real
 * services wired together, with only the edges faked: OpenRegister's
 * ObjectService is an in-memory store, the upstream source call
 * (CallService) and the custom rule's RuleService record what reaches
 * them, and the Nextcloud session, groups and notifications are doubles.
 *
 * @spec openspec/changes/approvals-verification-pack/tasks.md#task-1-suspend--approve--resume-through-a-real-rule-chain
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Integration;

use OCA\Integriq\Controller\ApprovalsController;
use OCA\Integriq\Rule\AvgBsnPolicyRule;
use OCA\Integriq\Rule\CompositeFanoutRule;
use OCA\Integriq\Rule\ReferenceNumberRule;
use OCA\Integriq\Service\ActionAuthServiceInterface;
use OCA\Integriq\Service\ApprovalDecisionService;
use OCA\Integriq\Service\ApprovalService;
use OCA\Integriq\Service\CallService;
use OCA\Integriq\Service\ConsumerScopeService;
use OCA\Integriq\Service\EndpointService;
use OCA\Integriq\Service\FlowRunnerService;
use OCA\Integriq\Service\MappingService;
use OCA\Integriq\Service\Consumer\OpenRegisterCredentialBridge;
use OCA\Integriq\Service\RateLimit\InboundRateLimitService;
use OCA\Integriq\Service\RuleService;
use OCA\Integriq\Service\StorageService;
use OCA\Integriq\Service\SynchronizationService;
use OCA\Integriq\Service\WebhookSignatureService;
use OCA\Integriq\Tests\Helpers\ObjectServiceMockBuilder;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\FileService;
use OCA\OpenRegister\Service\ObjectService as OrObjectService;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IConfig;
use OCP\IGroupManager;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IRequestId;
use OCP\IURLGenerator;
use OCP\IUser;
use OCP\IUserSession;
use OCP\Notification\IManager as INotificationManager;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Suspend, then approve and resume, or reject, one endpoint run.
 *
 * @spec openspec/changes/approvals-verification-pack/tasks.md#task-1-suspend--approve--resume-through-a-real-rule-chain
 */
class ApprovalRuleChainTest extends TestCase {
	/**
	 * The in-memory OpenRegister objects, by uuid.
	 *
	 * @var array<string, array<string, mixed>>
	 */
	private array $store = [];

	/**
	 * The custom rules that ran, in order.
	 *
	 * @var list<string>
	 */
	private array $rulesRun = [];

	/**
	 * How many times the upstream source was called.
	 */
	private int $upstreamCalls = 0;

	private EndpointService $endpoints;

	private ApprovalsController $controller;

	/**
	 * The approver's request parameters (the decision comment).
	 *
	 * @var array<string, mixed>
	 */
	private array $approverParams = [];

	/**
	 * @var IUserSession&MockObject
	 */
	private IUserSession $session;

	/**
	 * The user the session holds.
	 */
	private ?IUser $currentUser = null;

	/**
	 * Wire the real services over the faked edges.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->store = [
			'endpoint-1' => [
				'name' => 'Publish decision',
				'targetType' => 'api',
				'targetId' => 'source-1',
				'endpoint' => '/decisions',
				'method' => 'POST',
				'rules' => ['rule-approval', 'rule-after-approval'],
			],
			'source-1' => ['name' => 'Upstream', 'location' => 'https://upstream.example.org'],
			'rule-approval' => [
				'name' => 'Needs sign-off',
				'type' => 'approval',
				'order' => 10,
				'timing' => 'before',
				'configuration' => ['approval' => ['approverGroup' => 'approvers', 'ttlSeconds' => 3600, 'onReject' => 'dead_letter']],
			],
			'rule-after-approval' => ['name' => 'Stamp', 'type' => 'custom', 'order' => 20, 'timing' => 'before'],
		];

		$objects = $this->createMock(OrObjectService::class);
		$objects->method('find')->willReturnCallback(fn (mixed $id): ObjectEntity => $this->entity(uuid: (string)$id));
		$objects->method('saveObject')->willReturnCallback(
			function (mixed $object, ?string $register = null, ?string $schema = null, ?string $uuid = null): ObjectEntity {
				$uuid = ($uuid ?? ('approval-' . (count($this->store) + 1)));
				$this->store[$uuid] = (array)$object;
				return $this->entity(uuid: $uuid);
			}
		);

		$this->session = $this->createMock(IUserSession::class);
		$this->session->method('getUser')->willReturnCallback(fn (): ?IUser => $this->currentUser);
		$groups = $this->createMock(IGroupManager::class);
		$groups->method('isAdmin')->willReturn(false);
		$groups->method('isInGroup')->willReturnCallback(static fn (string $uid, string $group): bool => ($uid === 'alice' && $group === 'approvers'));
		$groups->method('get')->willReturn(null);
		$urls = $this->createMock(IURLGenerator::class);
		$urls->method('linkToRouteAbsolute')->willReturnCallback(static fn (string $route, array $params = []): string => 'https://nc.example.org/' . $route . '/' . ($params['id'] ?? ''));
		$logger = $this->createMock(LoggerInterface::class);

		$approvals = new ApprovalService(
			objectService: $objects,
			userSession: $this->session,
			groupManager: $groups,
			notificationManager: $this->createMock(INotificationManager::class),
			urlGenerator: $urls,
			logger: $logger,
		);

		$rules = $this->createMock(RuleService::class);
		$rules->method('processCustomRule')->willReturnCallback(
			function (...$args): array {
				$this->rulesRun[] = (string)($args['rule'] ?? $args[0])->getUuid();
				return ($args['data'] ?? $args[1]);
			}
		);

		$calls = $this->createMock(CallService::class);
		$calls->method('renderEndpointPath')->willReturnCallback(static fn (...$args): string => (string)($args['endpoint'] ?? $args[0]));
		$calls->method('call')->willReturnCallback(
			function (): ObjectEntity {
				$this->upstreamCalls++;
				return ObjectServiceMockBuilder::objectEntity($this, ['statusCode' => 201, 'response' => ['body' => '{"published":true}']], 'call-log-1');
			}
		);

		$scope = $this->createMock(ConsumerScopeService::class);
		$scope->method('isAllowed')->willReturn(true);

		$this->endpoints = new EndpointService(
			$this->createMock(\OCA\Integriq\Service\ObjectService::class),
			$calls,
			$logger,
			$urls,
			$this->createMock(MappingService::class),
			$objects,
			$this->createMock(IConfig::class),
			$this->createMock(StorageService::class),
			$this->createMock(OpenRegisterCredentialBridge::class),
			$this->createMock(ContainerInterface::class),
			$this->createMock(SynchronizationService::class),
			$rules,
			new WebhookSignatureService($logger),
			$this->createMock(InboundRateLimitService::class),
			new CompositeFanoutRule($objects, $logger),
			new ReferenceNumberRule(),
			new AvgBsnPolicyRule(),
			$approvals,
			$this->createMock(IRequestId::class),
			$this->createMock(FlowRunnerService::class),
			$scope,
			$this->createMock(SchemaMapper::class),
			$this->createMock(FileService::class),
		);

		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnArgument(0);
		$approverRequest = $this->createMock(IRequest::class);
		$approverRequest->method('getMethod')->willReturn('POST');
		$approverRequest->method('getParam')->willReturnCallback(fn (string $key, mixed $default = null): mixed => ($this->approverParams[$key] ?? $default));

		$decisions = new ApprovalDecisionService(
			request: $approverRequest,
			approvalService: $approvals,
			endpointService: $this->endpoints,
			synchronizationService: $this->createMock(SynchronizationService::class),
			flowRunnerService: $this->createMock(FlowRunnerService::class),
			orObjectService: $objects,
			l: $l10n,
			logger: $logger,
		);

		$this->controller = new ApprovalsController(
			'integriq',
			$approverRequest,
			$approvals,
			$decisions,
			$this->createMock(ActionAuthServiceInterface::class),
			$this->session,
			$l10n,
		);
	}//end setUp()

	/**
	 * The stored object as an entity.
	 *
	 * @param string $uuid The object's uuid.
	 *
	 * @return ObjectEntity
	 */
	private function entity(string $uuid): ObjectEntity {
		if (isset($this->store[$uuid]) === false) {
			throw new \OCP\AppFramework\Db\DoesNotExistException('No object ' . $uuid);
		}

		return ObjectServiceMockBuilder::objectEntity($this, $this->store[$uuid], $uuid);
	}//end entity()

	/**
	 * Act as this user from now on.
	 *
	 * @param string $uid The user id.
	 *
	 * @return void
	 */
	private function actAs(string $uid): void {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($uid);
		$this->currentUser = $user;
	}//end actAs()

	/**
	 * The caller's call of the endpoint, which suspends on the approval rule.
	 *
	 * @return array{0: JSONResponse, 1: string} The 202 answer and the approval request id.
	 */
	private function callTheEndpoint(): array {
		$request = $this->createMock(IRequest::class);
		$request->method('getMethod')->willReturn('POST');
		$request->method('getParams')->willReturn(['title' => 'Besluit 42']);
		$request->method('getHeader')->willReturn('');

		$response = $this->endpoints->handleRequest(endpoint: $this->entity(uuid: 'endpoint-1'), request: $request, path: '/decisions');
		$this->assertInstanceOf(JSONResponse::class, $response);

		return [$response, (string)($response->getData()['approvalRequestId'] ?? '')];
	}//end callTheEndpoint()

	/**
	 * The endpoint answers 202 with a polling URL and persists the snapshot;
	 * nothing after the approval rule has run and nothing went upstream.
	 *
	 * @return void
	 */
	public function testTheEndpointSuspendsOnTheApprovalRule(): void {
		$this->actAs(uid: 'requester');
		[$response, $id] = $this->callTheEndpoint();

		$this->assertSame(202, $response->getStatus());
		$this->assertSame('pending_approval', $response->getData()['status']);
		$this->assertSame('https://nc.example.org/integriq.approvals.show/' . $id, $response->getData()['statusUrl']);

		$stored = $this->store[$id];
		$this->assertSame('pending', $stored['status']);
		$this->assertSame('endpoint-1', $stored['endpointId']);
		$this->assertSame('rule-approval', $stored['ruleId']);
		$this->assertSame(10, $stored['resumeOrder']);
		$this->assertSame('requester', $stored['requesterUserId']);
		$this->assertSame('approvers', $stored['approverGroup']);
		$this->assertArrayHasKey('requestAmended', $stored['snapshot']);
		$this->assertSame([], $this->rulesRun);
		$this->assertSame(0, $this->upstreamCalls);
	}//end testTheEndpointSuspendsOnTheApprovalRule()

	/**
	 * Approving resumes at the rule after the approval rule and completes the chain upstream.
	 *
	 * @return void
	 */
	public function testApprovingResumesAfterTheApprovalRuleAndCompletes(): void {
		$this->actAs(uid: 'alice');
		[, $id] = $this->callTheEndpoint();

		$this->approverParams = ['comment' => 'Looks right'];
		$response = $this->controller->approve(id: $id);

		$this->assertSame(201, $response->getStatus());
		$this->assertSame(['rule-after-approval'], $this->rulesRun);
		$this->assertSame(1, $this->upstreamCalls);
		$this->assertSame('approved', $this->store[$id]['status']);
		$this->assertSame('alice', $this->store[$id]['approverUserId']);
		$this->assertSame('success', $this->store[$id]['resumeResult']);
		$this->assertSame('Looks right', $this->store[$id]['comment']);
	}//end testApprovingResumesAfterTheApprovalRuleAndCompletes()

	/**
	 * Rejecting applies the rule's onReject outcome and nothing resumes.
	 *
	 * @return void
	 */
	public function testRejectingAppliesTheConfiguredOnRejectOutcome(): void {
		$this->actAs(uid: 'alice');
		[, $id] = $this->callTheEndpoint();

		$this->approverParams = ['comment' => 'Wrong recipient'];
		$response = $this->controller->reject(id: $id);

		$this->assertSame(200, $response->getStatus());
		$this->assertSame('dead_letter', $response->getData()['status']);
		$this->assertSame('dead_letter', $this->store[$id]['status']);
		$this->assertSame('Wrong recipient', $this->store[$id]['comment']);
		$this->assertSame([], $this->rulesRun);
		$this->assertSame(0, $this->upstreamCalls);
	}//end testRejectingAppliesTheConfiguredOnRejectOutcome()

	/**
	 * A second decision on a decided request is refused with 409 and resumes nothing again.
	 *
	 * @return void
	 */
	public function testASecondApprovalIsRefusedAndDoesNotResumeAgain(): void {
		$this->actAs(uid: 'alice');
		[, $id] = $this->callTheEndpoint();
		$this->controller->approve(id: $id);

		$again = $this->controller->approve(id: $id);

		$this->assertSame(409, $again->getStatus());
		$this->assertSame(1, $this->upstreamCalls);
		$this->assertSame(['rule-after-approval'], $this->rulesRun);
	}//end testASecondApprovalIsRefusedAndDoesNotResumeAgain()

	/**
	 * Someone outside the approver group cannot decide, and nothing resumes.
	 *
	 * @return void
	 */
	public function testANonApproverIsRefusedAndNothingResumes(): void {
		$this->actAs(uid: 'requester');
		[, $id] = $this->callTheEndpoint();

		$this->actAs(uid: 'bob');
		$response = $this->controller->approve(id: $id);

		$this->assertSame(403, $response->getStatus());
		$this->assertSame('pending', $this->store[$id]['status']);
		$this->assertSame([], $this->rulesRun);
		$this->assertSame(0, $this->upstreamCalls);
	}//end testANonApproverIsRefusedAndNothingResumes()
}//end class

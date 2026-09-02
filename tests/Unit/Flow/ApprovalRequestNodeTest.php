<?php

/**
 * Unit tests for ApprovalRequestNode (`openconnector.approval-request`).
 *
 * The core semantics under test: the first pass persists a pending
 * approval_request and suspends; an approved decision resumes the run with
 * the decision on every item; a rejected one either routes (default) or fails
 * the run (`failOnReject`); an expired request fails closed, always. The
 * heartbeat treats the approval_request record as the authority when the
 * signal delivery was lost.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Flow
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Flow;

use OCA\Integriq\Exception\FlowNodeException;
use OCA\Integriq\Flow\ApprovalRequestNode;
use OCA\Integriq\Service\ApprovalService;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\Flow\FlowNodeResumeState;
use OCA\OpenRegister\Service\Flow\FlowResumeState;
use OCA\OpenRegister\Service\Flow\FlowStop;
use OCA\OpenRegister\Service\Flow\FlowSuspension;
use OCP\IL10N;
use OCP\IURLGenerator;
use OCP\WorkflowEngine\IManager;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use UnexpectedValueException;

/**
 * Tests for the approval-request flow node.
 */
class ApprovalRequestNodeTest extends TestCase {

	/**
	 * The HITL state machine double.
	 *
	 * @var ApprovalService&MockObject
	 */
	private $approvalService;

	/**
	 * The node under test.
	 *
	 * @var ApprovalRequestNode
	 */
	private ApprovalRequestNode $node;

	/**
	 * Build the node with doubles for everything it delegates to.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->approvalService = $this->createMock(ApprovalService::class);

		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(
			static function (string $text, $parameters = []): string {
				if (is_array($parameters) === false || $parameters === []) {
					return $text;
				}

				return vsprintf($text, $parameters);
			}
		);

		$urlGenerator = $this->createMock(IURLGenerator::class);
		$urlGenerator->method('imagePath')->willReturn('/core/img/actions/confirm.svg');

		$this->node = new ApprovalRequestNode(
			approvalService: $this->approvalService,
			l10n: $l10n,
			urlGenerator: $urlGenerator,
			logger: $this->createMock(LoggerInterface::class)
		);

	}//end setUp()

	/**
	 * A minimal valid step config.
	 *
	 * @param array $overrides Keys to override.
	 *
	 * @return array The config.
	 */
	private function config(array $overrides = []): array {
		return array_merge(
			[
				'question' => 'Publish this dataset?',
				'approverGroup' => 'data-stewards',
			],
			$overrides
		);

	}//end config()

	/**
	 * A run context carrying a resume slot, run uuid and owner.
	 *
	 * @param FlowResumeState $state The run-wide resume state.
	 * @param array $overrides Context keys to override or add.
	 *
	 * @return array The context.
	 */
	private function context(FlowResumeState $state, array $overrides = []): array {
		return array_merge(
			[
				'x-openregister-attribution-run' => 'run-uuid-1',
				'triggeredBy' => 'alice',
				FlowNodeResumeState::CONTEXT_KEY => $state->forNode(nodeId: 'approve-1'),
			],
			$overrides
		);

	}//end context()

	/**
	 * The palette metadata is present and app-namespaced.
	 *
	 * @return void
	 */
	public function testPaletteMetadata(): void {
		$this->assertSame('openconnector.approval-request', $this->node->getId());
		$this->assertNotSame('', $this->node->getDisplayName());
		$this->assertNotSame('', $this->node->getDescription());
		$this->assertNotSame('', $this->node->getIcon());
		$this->assertTrue($this->node->isAvailableForScope(IManager::SCOPE_ADMIN));
		$this->assertTrue($this->node->isAvailableForScope(IManager::SCOPE_USER));
		$this->assertFalse($this->node->isAvailableForScope(-1));

	}//end testPaletteMetadata()

	/**
	 * A step without a question is rejected at save.
	 *
	 * @return void
	 */
	public function testValidateConfigRequiresQuestion(): void {
		$this->expectException(UnexpectedValueException::class);
		$this->expectExceptionMessageMatches('/question/');

		$this->node->validateConfig(config: $this->config(overrides: ['question' => '  ']));

	}//end testValidateConfigRequiresQuestion()

	/**
	 * A step without an approver group is rejected at save.
	 *
	 * @return void
	 */
	public function testValidateConfigRequiresApproverGroup(): void {
		$this->expectException(UnexpectedValueException::class);
		$this->expectExceptionMessageMatches('/approverGroup/');

		$this->node->validateConfig(config: $this->config(overrides: ['approverGroup' => '']));

	}//end testValidateConfigRequiresApproverGroup()

	/**
	 * A credential-bearing field is rejected at save.
	 *
	 * @return void
	 */
	public function testValidateConfigRejectsCredentialFields(): void {
		$this->expectException(UnexpectedValueException::class);

		$this->node->validateConfig(config: $this->config(overrides: ['token' => 'secret']));

	}//end testValidateConfigRejectsCredentialFields()

	/**
	 * The first pass persists a pending approval_request and suspends.
	 *
	 * @return void
	 */
	public function testFirstPassOpensRequestAndSuspends(): void {
		$record = new ObjectEntity();
		$record->setUuid('req-1');
		$record->setObject(['status' => 'pending', 'expiresAt' => '2999-01-01T00:00:00+00:00']);

		$this->approvalService->expects($this->once())
			->method('suspendForEngineRun')
			->with('run-uuid-1', 'approve-1', $this->config(), 'alice')
			->willReturn($record);

		$state = new FlowResumeState();
		$context = $this->context(state: $state);

		try {
			$this->node->execute(items: [['json' => []]], config: $this->config(), context: $context);
			$this->fail('Expected a FlowSuspension');
		} catch (FlowSuspension $suspension) {
			$this->assertNotNull($suspension->getResumeAt(), 'The suspension must carry a heartbeat, not wait forever on a signal');
			$this->assertStringContainsString('Publish this dataset?', $suspension->getMessage());
		}

		$slot = $state->forNode(nodeId: 'approve-1');
		$this->assertSame('req-1', $slot->get(key: 'approvalRequestId'));
		$this->assertSame('data-stewards', $slot->get(key: 'assignee'), 'The engine-side assignee guard must name the approver group');
		$this->assertTrue($slot->has(key: 'askedAt'));

	}//end testFirstPassOpensRequestAndSuspends()

	/**
	 * A run that cannot name itself opens no request and raises.
	 *
	 * @return void
	 */
	public function testUnaddressableRunRefuses(): void {
		$this->approvalService->expects($this->never())->method('suspendForEngineRun');

		$state = new FlowResumeState();
		$context = $this->context(state: $state, overrides: ['x-openregister-attribution-run' => '']);

		$this->expectException(FlowNodeException::class);

		$this->node->execute(items: [['json' => []]], config: $this->config(), context: $context);

	}//end testUnaddressableRunRefuses()

	/**
	 * An approved signal writes the decision onto every item and continues.
	 *
	 * @return void
	 */
	public function testApprovedSignalResumesWithDecisionOnItems(): void {
		$state = new FlowResumeState();
		$context = $this->context(
			state: $state,
			overrides: [
				'signal' => [
					'decision' => 'approved',
					'decidedBy' => 'bob',
					'comment' => 'fine by me',
				],
			]
		);

		$out = $this->node->execute(
			items: [['json' => ['a' => 1]], ['json' => ['a' => 2]]],
			config: $this->config(),
			context: $context
		);

		$this->assertCount(2, $out);
		$this->assertSame('approved', $out[0]['json']['approval']['decision']);
		$this->assertSame('bob', $out[1]['json']['approval']['decidedBy']);
		$this->assertSame(1, $out[0]['json']['a'], 'The rest of the record is untouched');

	}//end testApprovedSignalResumesWithDecisionOnItems()

	/**
	 * A rejected signal without failOnReject routes: the decision lands on
	 * the items so the author's reject edge can read it.
	 *
	 * @return void
	 */
	public function testRejectedSignalRoutesByDefault(): void {
		$state = new FlowResumeState();
		$context = $this->context(
			state: $state,
			overrides: ['signal' => ['decision' => 'rejected', 'comment' => 'not like this']]
		);

		$out = $this->node->execute(items: [['json' => []]], config: $this->config(), context: $context);

		$this->assertSame('rejected', $out[0]['json']['approval']['decision']);

	}//end testRejectedSignalRoutesByDefault()

	/**
	 * A rejected signal under failOnReject fails the run.
	 *
	 * @return void
	 */
	public function testRejectedSignalFailsUnderFailOnReject(): void {
		$state = new FlowResumeState();
		$context = $this->context(
			state: $state,
			overrides: ['signal' => ['decision' => 'rejected', 'comment' => 'nope']]
		);

		try {
			$this->node->execute(
				items: [['json' => []]],
				config: $this->config(overrides: ['failOnReject' => true]),
				context: $context
			);
			$this->fail('Expected a FlowStop');
		} catch (FlowStop $stop) {
			$this->assertTrue($stop->isError(), 'A rejection under failOnReject is a failure, not a clean stop');
			$this->assertStringContainsString('nope', $stop->getMessage());
		}

	}//end testRejectedSignalFailsUnderFailOnReject()

	/**
	 * A signal with no decision is a nudge: the pending request re-suspends.
	 *
	 * @return void
	 */
	public function testDecisionlessSignalKeepsWaiting(): void {
		$record = new ObjectEntity();
		$record->setUuid('req-1');
		$record->setObject(['status' => 'pending', 'expiresAt' => '2999-01-01T00:00:00+00:00']);
		$this->approvalService->method('find')->willReturn($record);

		$state = new FlowResumeState();
		$slot = $state->forNode(nodeId: 'approve-1');
		$slot->merge(values: ['approvalRequestId' => 'req-1', 'askedAt' => '2026-01-01T00:00:00+00:00', 'question' => 'Publish this dataset?']);

		$context = $this->context(state: $state, overrides: ['signal' => ['ping' => true]]);

		$this->expectException(FlowSuspension::class);

		$this->node->execute(items: [['json' => []]], config: $this->config(), context: $context);

	}//end testDecisionlessSignalKeepsWaiting()

	/**
	 * The heartbeat resolves from the RECORD when the signal was lost: an
	 * approved approval_request resumes the run without any delivered signal.
	 *
	 * @return void
	 */
	public function testHeartbeatResolvesFromApprovedRecord(): void {
		$record = new ObjectEntity();
		$record->setUuid('req-1');
		$record->setObject(
			[
				'status' => 'approved',
				'approverUserId' => 'bob',
				'comment' => 'looks good',
				'expiresAt' => '2999-01-01T00:00:00+00:00',
			]
		);
		$this->approvalService->method('find')->with('req-1')->willReturn($record);

		$state = new FlowResumeState();
		$state->forNode(nodeId: 'approve-1')->merge(
			values: ['approvalRequestId' => 'req-1', 'askedAt' => '2026-01-01T00:00:00+00:00']
		);

		$out = $this->node->execute(items: [['json' => []]], config: $this->config(), context: $this->context(state: $state));

		$this->assertSame('approved', $out[0]['json']['approval']['decision']);
		$this->assertSame('bob', $out[0]['json']['approval']['decidedBy']);

	}//end testHeartbeatResolvesFromApprovedRecord()

	/**
	 * An expired request fails closed — never a silent resume, never more waiting.
	 *
	 * @return void
	 */
	public function testExpiredRequestFailsClosed(): void {
		$record = new ObjectEntity();
		$record->setUuid('req-1');
		$record->setObject(['status' => 'expired']);
		$this->approvalService->method('find')->willReturn($record);

		$state = new FlowResumeState();
		$state->forNode(nodeId: 'approve-1')->merge(
			values: ['approvalRequestId' => 'req-1', 'askedAt' => '2026-01-01T00:00:00+00:00']
		);

		try {
			$this->node->execute(items: [['json' => []]], config: $this->config(), context: $this->context(state: $state));
			$this->fail('Expected a FlowStop');
		} catch (FlowStop $stop) {
			$this->assertTrue($stop->isError(), 'An expired approval is a failure, not a clean stop');
			$this->assertStringContainsString('req-1', $stop->getMessage());
		}

	}//end testExpiredRequestFailsClosed()

	/**
	 * A pending request whose deadline has passed fails closed even before
	 * the expiry sweep has marked it.
	 *
	 * @return void
	 */
	public function testPendingPastDeadlineFailsClosed(): void {
		$record = new ObjectEntity();
		$record->setUuid('req-1');
		$record->setObject(['status' => 'pending', 'expiresAt' => '2020-01-01T00:00:00+00:00']);
		$this->approvalService->method('find')->willReturn($record);

		$state = new FlowResumeState();
		$state->forNode(nodeId: 'approve-1')->merge(
			values: ['approvalRequestId' => 'req-1', 'askedAt' => '2026-01-01T00:00:00+00:00']
		);

		$this->expectException(FlowStop::class);

		$this->node->execute(items: [['json' => []]], config: $this->config(), context: $this->context(state: $state));

	}//end testPendingPastDeadlineFailsClosed()

	/**
	 * A rejected record found on heartbeat routes exactly like a rejected
	 * signal would — the reject edge reads the decision from the items.
	 *
	 * @return void
	 */
	public function testHeartbeatResolvesFromRejectedRecord(): void {
		$record = new ObjectEntity();
		$record->setUuid('req-1');
		$record->setObject(
			['status' => 'rejected', 'approverUserId' => 'bob', 'comment' => 'no', 'expiresAt' => '2999-01-01T00:00:00+00:00']
		);
		$this->approvalService->method('find')->willReturn($record);

		$state = new FlowResumeState();
		$state->forNode(nodeId: 'approve-1')->merge(
			values: ['approvalRequestId' => 'req-1', 'askedAt' => '2026-01-01T00:00:00+00:00']
		);

		$out = $this->node->execute(items: [['json' => []]], config: $this->config(), context: $this->context(state: $state));

		$this->assertSame('rejected', $out[0]['json']['approval']['decision']);

	}//end testHeartbeatResolvesFromRejectedRecord()
}//end class

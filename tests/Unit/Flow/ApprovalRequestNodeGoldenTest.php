<?php

/**
 * Golden characterization of ApprovalRequestNode::execute(): what every
 * signal, record state and configuration answers with.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Flow
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/retire-integriq-flow-schema/tasks.md#1-the-missing-node
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Flow;

use OCA\Integriq\Flow\ApprovalRequestNode;
use OCA\Integriq\Flow\FlowNodeException;
use OCA\Integriq\Service\ApprovalService;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\Flow\FlowNodeCall;
use OCA\OpenRegister\Service\Flow\FlowNodeResumeState;
use OCA\OpenRegister\Service\Flow\FlowResumeState;
use OCA\OpenRegister\Service\Flow\FlowStop;
use OCA\OpenRegister\Service\Flow\FlowSuspension;
use OCP\IL10N;
use OCP\IURLGenerator;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Every branch of the approval step, pinned by its outcome.
 */
class ApprovalRequestNodeGoldenTest extends TestCase {

	/**
	 * Calls to suspendForEngineRun.
	 *
	 * @var list<array<int, mixed>>
	 */
	private array $suspended = [];

	/**
	 * Run the node once.
	 *
	 * @param array<string,mixed>      $case The case: record, slot, signal, config, items, context.
	 * @param FlowNodeResumeState|null $slot The resume slot handed back for inspection.
	 *
	 * @return array<string,mixed> The outcome: items, or the exception's class, message and flags.
	 */
	private function runNode(array $case, ?FlowNodeResumeState &$slot = null): array {
		$approvals = $this->createMock(ApprovalService::class);
		$approvals->method('find')->willReturnCallback(
			function (string $id) use ($case) {
				if (($case['record'] ?? null) === 'gone') {
					throw new RuntimeException('not found');
				}

				$record = new ObjectEntity();
				$record->setUuid($id);
				$record->setObject((array)($case['record'] ?? []));
				return $record;
			}
		);
		$approvals->method('suspendForEngineRun')->willReturnCallback(
			function (string $engineRunUuid, string $signalNodeId, array $config, string $requesterUid) {
				$this->suspended[] = [$engineRunUuid, $signalNodeId, $config['question'], $requesterUid];
				$record = new ObjectEntity();
				$record->setUuid('req-new');
				$record->setObject(['expiresAt' => '2999-01-01T00:00:00+00:00']);
				return $record;
			}
		);

		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnArgument(0);
		$node = new ApprovalRequestNode(
			approvalService: $approvals,
			l10n: $l10n,
			urlGenerator: $this->createMock(IURLGenerator::class),
			logger: $this->createMock(LoggerInterface::class)
		);

		$state = new FlowResumeState();
		$slot  = $state->forNode(nodeId: 'approve-1');
		if (isset($case['slot']) === true) {
			$slot->merge(values: $case['slot']);
		}

		$context = ['x-openregister-attribution-run' => 'run-1', 'triggeredBy' => ' alice ', FlowNodeResumeState::CONTEXT_KEY => $slot];
		if (array_key_exists('signal', $case) === true) {
			$context['signal'] = $case['signal'];
		}

		$context = array_merge($context, (array)($case['context'] ?? []));
		$config  = array_merge(['question' => ' Publish? ', 'approverGroup' => ' stewards '], (array)($case['config'] ?? []));
		$items   = ($case['items'] ?? [['json' => ['a' => 1]], 'not-an-item', ['json' => 'scalar']]);

		try {
			return ['items' => $node->execute(new FlowNodeCall(items: $items, config: $config, context: $context))];
		} catch (FlowSuspension $suspension) {
			return ['suspension' => $suspension->getMessage(), 'resumes' => ($suspension->getResumeAt() !== null)];
		} catch (FlowStop $stop) {
			return ['stop' => $stop->getMessage(), 'error' => $stop->isError()];
		} catch (FlowNodeException $exception) {
			return ['nodeException' => $exception->getMessage()];
		}

	}//end runNode()

	/**
	 * The cases and their outcomes.
	 *
	 * @return array<string, array{array<string,mixed>, array<string,mixed>}> The cases.
	 */
	public static function cases(): array {
		$pending = ['approvalRequestId' => 'req-1', 'question' => 'Publish?'];
		$decided = ['decision' => 'approved', 'decidedBy' => 'bob', 'comment' => 'ok', 'approvalRequestId' => 'req-1'];
		return [
			'signal-approved' => [
				['signal' => ['decision' => 'approved', 'decidedBy' => 'bob']],
				['items' => [['json' => ['a' => 1, 'approval' => ['decision' => 'approved', 'decidedBy' => 'bob']]], 'not-an-item', ['json' => [0 => 'scalar', 'approval' => ['decision' => 'approved', 'decidedBy' => 'bob']]]]],
			],
			'signal-custom-key' => [
				['signal' => ['decision' => 'approved'], 'config' => ['signalKey' => ' gate '], 'items' => [['json' => []]]],
				['items' => [['json' => ['gate' => ['decision' => 'approved']]]]],
			],
			'signal-blank-key' => [
				['signal' => ['decision' => 'approved'], 'config' => ['signalKey' => '  '], 'items' => [[]]],
				['items' => [['json' => ['approval' => ['decision' => 'approved']]]]],
			],
			'signal-reject-routes' => [
				['signal' => ['decision' => ' Reject ', 'comment' => 'no'], 'items' => [['json' => []]]],
				['items' => [['json' => ['approval' => ['decision' => ' Reject ', 'comment' => 'no']]]]],
			],
			'signal-reject-fails' => [
				['signal' => ['decision' => 'REJECT', 'comment' => ' bad '], 'config' => ['failOnReject' => true]],
				['stop' => 'Rejected: bad', 'error' => true],
			],
			'signal-rejected-fails-no-comment' => [
				['signal' => ['decision' => 'rejected'], 'config' => ['failOnReject' => true]],
				['stop' => 'Rejected: Publish?', 'error' => true],
			],
			'signal-rejected-fail-flag-not-true' => [
				['signal' => ['decision' => 'rejected'], 'config' => ['failOnReject' => 'yes'], 'items' => [['json' => []]]],
				['items' => [['json' => ['approval' => ['decision' => 'rejected']]]]],
			],
			'signal-decisionless-opens' => [
				['signal' => ['decision' => ' ']],
				['suspension' => 'waiting for approval: Publish?', 'resumes' => true],
			],
			'no-resume-slot' => [
				['context' => [FlowNodeResumeState::CONTEXT_KEY => 'nope']],
				['nodeException' => 'The approval step has no resume slot; the engine did not dispatch it as a resumable node.'],
			],
			'first-pass-opens' => [
				[],
				['suspension' => 'waiting for approval: Publish?', 'resumes' => true],
			],
			'first-pass-unaddressable' => [
				['context' => ['x-openregister-attribution-run' => '  ']],
				['nodeException' => 'This run carries no uuid, so an approval could never answer it. The approval step is only usable in a persisted flow run.'],
			],
			'record-approved' => [
				['slot' => $pending, 'record' => ['status' => 'approved', 'approverUserId' => 'bob', 'comment' => 'ok'], 'items' => [['json' => []]]],
				['items' => [['json' => ['approval' => $decided]]]],
			],
			'record-rejected-fails' => [
				['slot' => $pending, 'record' => ['status' => 'rejected'], 'config' => ['failOnReject' => true]],
				['stop' => 'Rejected: ', 'error' => true],
			],
			'record-rejected-routes' => [
				['slot' => $pending, 'record' => ['status' => 'rejected'], 'items' => [['json' => []]]],
				['items' => [['json' => ['approval' => ['decision' => 'rejected', 'decidedBy' => '', 'comment' => '', 'approvalRequestId' => 'req-1']]]]],
			],
			'record-gone' => [
				['slot' => $pending, 'record' => 'gone'],
				['stop' => 'Approval request req-1 no longer exists; failing closed.', 'error' => true],
			],
			'record-dead-letter' => [
				['slot' => $pending, 'record' => ['status' => 'dead_letter']],
				['stop' => 'Approval request req-1 was dead-lettered.', 'error' => true],
			],
			'record-unknown-status' => [
				['slot' => $pending, 'record' => ['status' => 'weird']],
				['stop' => 'Approval request req-1 was not answered in time (status: weird); failing closed.', 'error' => true],
			],
			'record-pending-expired' => [
				['slot' => $pending, 'record' => ['expiresAt' => '2020-01-01T00:00:00+00:00']],
				['stop' => 'Approval request req-1 was not answered in time (status: pending); failing closed.', 'error' => true],
			],
			'record-pending-unreadable-deadline' => [
				['slot' => $pending + ['expiresAt' => 'not a date'], 'record' => ['status' => 'pending']],
				['stop' => 'Approval request req-1 was not answered in time (status: pending); failing closed.', 'error' => true],
			],
			'record-pending-waits' => [
				['slot' => $pending, 'record' => ['status' => 'pending', 'expiresAt' => '2999-01-01T00:00:00+00:00']],
				['suspension' => 'still waiting for approval: Publish?', 'resumes' => true],
			],
			'record-pending-waits-no-question' => [
				['slot' => ['approvalRequestId' => 'req-1'], 'record' => ['status' => 'pending']],
				['suspension' => 'still waiting for approval: approval', 'resumes' => true],
			],
		];

	}//end cases()

	/**
	 * Every case answers as pinned.
	 *
	 * @param array<string,mixed> $case     The case.
	 * @param array<string,mixed> $expected The outcome.
	 *
	 * @return void
	 *
	 * @dataProvider cases
	 */
	public function testEveryCaseAnswersAsPinned(array $case, array $expected): void {
		$this->assertSame($expected, $this->runNode(case: $case));

	}//end testEveryCaseAnswersAsPinned()

	/**
	 * Opening a request suspends for the run and stamps the slot.
	 *
	 * @return void
	 */
	public function testOpeningARequestStampsTheSlot(): void {
		$slot = null;
		$this->runNode(case: [], slot: $slot);

		$this->assertSame([['run-1', 'approve-1', ' Publish? ', 'alice']], $this->suspended);
		$values = $slot->all();
		$this->assertNotFalse(strtotime((string)$values['askedAt']));
		unset($values['askedAt']);
		$this->assertSame(
			['approvalRequestId' => 'req-new', 'question' => 'Publish?', 'assignee' => 'stewards', 'expiresAt' => '2999-01-01T00:00:00+00:00'],
			$values
		);

	}//end testOpeningARequestStampsTheSlot()
}//end class

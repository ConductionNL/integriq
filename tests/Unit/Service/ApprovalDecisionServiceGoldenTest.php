<?php

/**
 * Golden characterization of ApprovalDecisionService: every resume and
 * rejection path, with each collaborator call and the answer.
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
 * @spec openspec/specs/hitl-on-shared-tasks/spec.md#requirement-a-decision-taken-on-the-shared-task-resumes-the-run
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Service;

use OCA\Integriq\Service\ApprovalDecisionService;
use OCA\Integriq\Service\ApprovalService;
use OCA\Integriq\Service\EndpointService;
use OCA\Integriq\Service\EngineSignalService;
use OCA\Integriq\Service\FlowRunnerService;
use OCA\Integriq\Service\Helper\FlowToken;
use OCA\Integriq\Service\SynchronizationService;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService as OrObjectService;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IUser;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Every decision path of ApprovalDecisionService.
 */
class ApprovalDecisionServiceGoldenTest extends TestCase {

	/**
	 * Every collaborator call, in order.
	 *
	 * @var list<array<int, mixed>>
	 */
	private array $log = [];

	/**
	 * Build an entity.
	 *
	 * @param array<string,mixed> $body The data.
	 * @param string              $uuid The uuid.
	 *
	 * @return ObjectEntity The entity.
	 */
	private function entity(array $body, string $uuid): ObjectEntity {
		$entity = new ObjectEntity();
		$entity->setUuid($uuid);
		$entity->setObject($body);
		return $entity;

	}//end entity()

	/**
	 * Build the service over recording doubles.
	 *
	 * @param array<string,mixed> $answers What the collaborators answer.
	 *
	 * @return ApprovalDecisionService The service.
	 */
	private function service(array $answers): ApprovalDecisionService {
		$approvals = $this->createMock(ApprovalService::class);
		$approvals->method('rehydrateFlowToken')->willReturnCallback(
			function (array $snapshot) {
				$this->log[] = ['approvals.rehydrateFlowToken', $snapshot];
				$token = new FlowToken();
				$token->setRequestAmended(['path' => 'zaken/1']);
				return $token;
			}
		);
		$approvals->method('rehydrateTraceContext')->willReturnCallback(
			function (array $snapshot) {
				$this->log[] = ['approvals.rehydrateTraceContext', $snapshot];
				return null;
			}
		);
		$approvals->method('completeApproval')->willReturnCallback(
			function (ObjectEntity $approvalRequest, IUser $approver, string $resumeResult, ?string $comment = null) {
				$this->log[] = ['approvals.completeApproval', $approvalRequest->getUuid(), $approver->getUID(), $resumeResult, $comment];
				return $this->entity(['status' => 'approved', 'approvedAt' => 'at-1', 'resumeResult' => $resumeResult], 'done-1');
			}
		);
		$approvals->method('reject')->willReturnCallback(
			function (ObjectEntity $approvalRequest, IUser $approver, string $comment) {
				$this->log[] = ['approvals.reject', $approvalRequest->getUuid(), $approver->getUID(), $comment];
				return $this->entity($approvalRequest->getObject() + ['status' => 'rejected'], 'rejected-1');
			}
		);

		$endpoints = $this->createMock(EndpointService::class);
		$endpoints->method('getEndpointById')->willReturnCallback(
			function ($id) use ($answers) {
				$this->log[] = ['endpoints.getEndpointById', $id];
				return ($answers['endpoint'] ?? true) === true ? $this->entity(['name' => 'E'], (string)$id) : null;
			}
		);
		$endpoints->method('resumeFromApproval')->willReturnCallback(
			function ($endpoint, $request, $flowToken, $resumeAfterOrder, $path, $trace) use ($answers) {
				$this->log[] = ['endpoints.resumeFromApproval', $endpoint->getUuid(), $request instanceof IRequest, $resumeAfterOrder, $path, $trace];
				return new JSONResponse(($answers['resumedBody'] ?? ['id' => 'obj-1']), ($answers['resumedStatus'] ?? 201));
			}
		);

		$flows = $this->createMock(FlowRunnerService::class);
		$flows->method('resumeFromApproval')->willReturnCallback(
			function (ObjectEntity $approvalRequest) use ($answers) {
				$this->log[] = ['flows.resumeFromApproval', $approvalRequest->getUuid()];
				if (($answers['flow'] ?? '') === 'throws') {
					throw new RuntimeException('flow broke');
				}

				return $this->entity(['status' => ($answers['flow'] ?? 'completed')], 'flow-run-1');
			}
		);
		$flows->method('stopFromApprovalOutcome')->willReturnCallback(
			function (ObjectEntity $approvalRequest) {
				$this->log[] = ['flows.stopFromApprovalOutcome', $approvalRequest->getUuid()];
				return null;
			}
		);

		$signal = null;
		if (($answers['signal'] ?? 'yes') !== 'unwired') {
			$signal = $this->createMock(EngineSignalService::class);
			$signal->method('deliver')->willReturnCallback(
				function (array $data, string $decision, IUser $user, ?string $comment) use ($answers) {
					$this->log[] = ['signal.deliver', $data['engineRunUuid'] ?? null, $decision, $user->getUID(), $comment];
					return ($answers['signal'] ?? 'yes') === 'yes';
				}
			);
		}

		$logger = $this->createMock(LoggerInterface::class);
		foreach (['error', 'warning'] as $level) {
			$logger->method($level)->willReturnCallback(
				function ($message, array $context = []) use ($level) {
					$this->log[] = ['logger.' . $level, $message, array_keys($context)];
				}
			);
		}

		$l = $this->createMock(IL10N::class);
		$l->method('t')->willReturnArgument(0);

		return new ApprovalDecisionService(
			$this->createMock(IRequest::class),
			$approvals,
			$endpoints,
			$this->createMock(SynchronizationService::class),
			$flows,
			$this->createMock(OrObjectService::class),
			$l,
			$logger,
			$signal
		);

	}//end service()

	/**
	 * A user.
	 *
	 * @return IUser The user.
	 */
	private function user(): IUser {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('alice');
		return $user;

	}//end user()

	/**
	 * The approve cases: request data, answers, expected log and answer.
	 *
	 * @return array<string, array<int, mixed>> The cases.
	 */
	public static function approveCases(): array {
		$snapshot = ['traceId' => ''];
		return [
			'endpoint-gone' => [
				['endpointId' => 'ep-1', 'snapshot' => $snapshot],
				['endpoint' => false],
				[['endpoints.getEndpointById', 'ep-1']],
				[404, ['error' => 'The suspended endpoint no longer exists']],
			],
			'endpoint-resumed' => [
				['endpointId' => 'ep-1', 'snapshot' => $snapshot, 'resumeOrder' => '20'],
				[],
				[
					['endpoints.getEndpointById', 'ep-1'],
					['approvals.rehydrateFlowToken', $snapshot],
					['approvals.rehydrateTraceContext', $snapshot],
					['endpoints.resumeFromApproval', 'ep-1', true, 20, 'zaken/1', null],
					['approvals.completeApproval', 'req-1', 'alice', 'success', 'ok'],
				],
				[201, ['id' => 'obj-1', '_approval' => ['id' => 'done-1', 'status' => 'approved', 'resumedAt' => 'at-1']]],
			],
			'endpoint-resume-failed-no-snapshot' => [
				['endpointId' => 'ep-2'],
				['resumedStatus' => 422, 'resumedBody' => 'not-an-array'],
				[
					['endpoints.getEndpointById', 'ep-2'],
					['approvals.rehydrateFlowToken', []],
					['approvals.rehydrateTraceContext', []],
					['endpoints.resumeFromApproval', 'ep-2', true, 0, 'zaken/1', null],
					['approvals.completeApproval', 'req-1', 'alice', 'error', 'ok'],
				],
				[422, ['data' => 'not-an-array', '_approval' => ['id' => 'done-1', 'status' => 'approved', 'resumedAt' => 'at-1']]],
			],
			'endpoint-resumed-299' => [
				['endpointId' => 'ep-3'],
				['resumedStatus' => 299],
				null,
				[299, ['id' => 'obj-1', '_approval' => ['id' => 'done-1', 'status' => 'approved', 'resumedAt' => 'at-1']]],
			],
			'endpoint-resumed-300' => [
				['endpointId' => 'ep-4'],
				['resumedStatus' => 300],
				null,
				[300, ['id' => 'obj-1', '_approval' => ['id' => 'done-1', 'status' => 'approved', 'resumedAt' => 'at-1']]],
			],
			'flow-completed' => [
				['flowRunId' => 'fr-1'],
				[],
				[
					['flows.resumeFromApproval', 'req-1'],
					['approvals.completeApproval', 'req-1', 'alice', 'success', 'ok'],
				],
				[200, ['status' => 'completed', '_approval' => ['id' => 'done-1', 'status' => 'approved', 'resumedAt' => 'at-1']]],
			],
			'flow-dead-letter' => [
				['flowRunId' => 'fr-1'],
				['flow' => 'dead_letter'],
				[
					['flows.resumeFromApproval', 'req-1'],
					['approvals.completeApproval', 'req-1', 'alice', 'error', 'ok'],
				],
				[200, ['status' => 'dead_letter', '_approval' => ['id' => 'done-1', 'status' => 'approved', 'resumedAt' => 'at-1']]],
			],
			'flow-throws' => [
				['flowRunId' => 'fr-1'],
				['flow' => 'throws'],
				[
					['flows.resumeFromApproval', 'req-1'],
					['logger.error', 'ApprovalDecisionService: resumed flow run failed: flow broke', ['exception']],
					['approvals.completeApproval', 'req-1', 'alice', 'error', 'ok'],
				],
				[500, ['error' => 'flow broke', '_approval' => ['id' => 'done-1', 'status' => 'approved', 'resumedAt' => 'at-1']]],
			],
			'engine-signalled' => [
				['engineRunUuid' => 'run-1'],
				[],
				[
					['signal.deliver', 'run-1', 'approved', 'alice', 'ok'],
					['approvals.completeApproval', 'req-1', 'alice', 'success', 'ok'],
				],
				[200, ['engineRunUuid' => 'run-1', 'signalled' => true, '_approval' => ['id' => 'done-1', 'status' => 'approved', 'resumedAt' => 'at-1']]],
			],
			'engine-undelivered' => [
				['engineRunUuid' => 'run-1'],
				['signal' => 'no'],
				[
					['signal.deliver', 'run-1', 'approved', 'alice', 'ok'],
					['approvals.completeApproval', 'req-1', 'alice', 'error', 'ok'],
				],
				[200, ['engineRunUuid' => 'run-1', 'signalled' => false, '_approval' => ['id' => 'done-1', 'status' => 'approved', 'resumedAt' => 'at-1']]],
			],
			'engine-unwired' => [
				['engineRunUuid' => 'run-1'],
				['signal' => 'unwired'],
				[
					['logger.warning', 'ApprovalDecisionService: no EngineSignalService wired; the engine run resumes on its next heartbeat instead', ['engineRunUuid']],
					['approvals.completeApproval', 'req-1', 'alice', 'error', 'ok'],
				],
				[200, ['engineRunUuid' => 'run-1', 'signalled' => false, '_approval' => ['id' => 'done-1', 'status' => 'approved', 'resumedAt' => 'at-1']]],
			],
			'endpoint-wins-over-flow' => [
				['endpointId' => 'ep-5', 'flowRunId' => 'fr-1', 'engineRunUuid' => 'run-1'],
				['endpoint' => false],
				[['endpoints.getEndpointById', 'ep-5']],
				[404, ['error' => 'The suspended endpoint no longer exists']],
			],
			'flow-wins-over-engine' => [
				['flowRunId' => 'fr-1', 'engineRunUuid' => 'run-1', 'endpointId' => ''],
				[],
				[
					['flows.resumeFromApproval', 'req-1'],
					['approvals.completeApproval', 'req-1', 'alice', 'success', 'ok'],
				],
				[200, ['status' => 'completed', '_approval' => ['id' => 'done-1', 'status' => 'approved', 'resumedAt' => 'at-1']]],
			],
			'malformed' => [
				['endpointId' => '', 'flowRunId' => null],
				[],
				[['logger.error', 'ApprovalDecisionService: approval_request has neither endpointId, synchronizationId, flowRunId nor engineRunUuid', ['id']]],
				[500, ['error' => 'Malformed approval request']],
			],
		];

	}//end approveCases()

	/**
	 * Every approve path calls its collaborators and answers as pinned.
	 *
	 * @param array<string,mixed>     $data     The approval_request data.
	 * @param array<string,mixed>     $answers  What the collaborators answer.
	 * @param list<array<int,mixed>>|null $expectedLog The expected calls, or null to skip.
	 * @param array{int,mixed}        $expected The expected status and body.
	 *
	 * @return void
	 *
	 * @dataProvider approveCases
	 */
	public function testApprove(array $data, array $answers, ?array $expectedLog, array $expected): void {
		$response = $this->service($answers)->approve($this->entity($data, 'req-1'), $this->user(), 'ok');

		if ($expectedLog !== null) {
			$this->assertSame($expectedLog, $this->log);
		}

		$this->assertSame($expected, [$response->getStatus(), $response->getData()]);

	}//end testApprove()

	/**
	 * A rejection stops the flow run and wakes the engine run, in that order.
	 *
	 * @return void
	 */
	public function testARejectionStopsTheFlowAndSignalsTheEngine(): void {
		$rejected = $this->service([])->reject($this->entity(['flowRunId' => 'fr-1', 'engineRunUuid' => 'run-1'], 'req-1'), $this->user(), 'no');

		$this->assertSame('rejected-1', $rejected->getUuid());
		$this->assertSame(
			[
				['approvals.reject', 'req-1', 'alice', 'no'],
				['flows.stopFromApprovalOutcome', 'rejected-1'],
				['signal.deliver', 'run-1', 'rejected', 'alice', 'no'],
			],
			$this->log
		);

	}//end testARejectionStopsTheFlowAndSignalsTheEngine()

	/**
	 * A rejection of an endpoint request only records the rejection.
	 *
	 * @return void
	 */
	public function testARejectionWithoutARunOnlyRecordsIt(): void {
		$this->service([])->reject($this->entity(['endpointId' => 'ep-1'], 'req-1'), $this->user(), 'no');

		$this->assertSame([['approvals.reject', 'req-1', 'alice', 'no']], $this->log);

	}//end testARejectionWithoutARunOnlyRecordsIt()

	/**
	 * A rejection of an engine run without a signal service only warns.
	 *
	 * @return void
	 */
	public function testARejectionWithoutASignalServiceWarns(): void {
		$this->service(['signal' => 'unwired'])->reject($this->entity(['engineRunUuid' => 'run-1'], 'req-1'), $this->user(), 'no');

		$this->assertSame(
			[
				['approvals.reject', 'req-1', 'alice', 'no'],
				['logger.warning', 'ApprovalDecisionService: no EngineSignalService wired; the engine run resumes on its next heartbeat instead', ['engineRunUuid']],
			],
			$this->log
		);

	}//end testARejectionWithoutASignalServiceWarns()
}//end class

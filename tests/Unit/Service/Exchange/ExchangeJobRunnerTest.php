<?php

/**
 * ExchangeJobRunner: the order of a run and its outcomes.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Service\Exchange
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://www.Integriq.nl
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Service\Exchange;

use OCA\Integriq\Action\ExchangeJobAction;
use OCA\Integriq\Event\ExchangeJobConcludedEvent;
use OCA\Integriq\Service\Exchange\ExchangeGateClient;
use OCA\Integriq\Service\Exchange\ExchangeJobRunner;
use OCA\Integriq\Service\Exchange\ExchangeJobService;
use OCA\Integriq\Service\Exchange\ExchangeRejectionService;
use OCA\Integriq\Service\Exchange\ExchangeTargetDispatcher;
use OCA\Integriq\Service\MappingService;
use OCA\OpenRegister\Db\ObjectEntity;
use OCP\EventDispatcher\IEventDispatcher;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * REQ-003, REQ-004, REQ-005, REQ-006 and REQ-009 scenarios.
 */
class ExchangeJobRunnerTest extends TestCase {

	/**
	 * @var ExchangeJobService&MockObject
	 */
	private $jobs;

	/**
	 * @var ExchangeGateClient&MockObject
	 */
	private $gate;

	/**
	 * @var ExchangeTargetDispatcher&MockObject
	 */
	private $dispatcher;

	/**
	 * @var ExchangeRejectionService&MockObject
	 */
	private $rejections;

	/**
	 * @var MappingService&MockObject
	 */
	private $mappings;

	/**
	 * Every job state saved, in order.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $saves = [];

	/**
	 * Every concluded event raised.
	 *
	 * @var array<int, ExchangeJobConcludedEvent>
	 */
	private array $concluded = [];

	/**
	 * The runner under test.
	 *
	 * @var ExchangeJobRunner
	 */
	private ExchangeJobRunner $runner;

	/**
	 * Set up doubles.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->jobs = $this->createMock(ExchangeJobService::class);
		$this->jobs->method('saveJob')->willReturnCallback(
			function (array $data, ?string $uuid = null): ObjectEntity {
				$this->saves[] = $data;
				return $this->job(data: $data, uuid: (string)$uuid);
			}
		);
		$this->gate = $this->createMock(ExchangeGateClient::class);
		$this->dispatcher = $this->createMock(ExchangeTargetDispatcher::class);
		$this->rejections = $this->createMock(ExchangeRejectionService::class);
		$this->mappings = $this->createMock(MappingService::class);

		$events = $this->createMock(IEventDispatcher::class);
		$events->method('dispatchTyped')->willReturnCallback(
			function ($event): void {
				if ($event instanceof ExchangeJobConcludedEvent) {
					$this->concluded[] = $event;
				}
			}
		);

		$this->runner = new ExchangeJobRunner(
			$this->jobs,
			$this->gate,
			$this->dispatcher,
			$this->rejections,
			$this->mappings,
			$events,
			$this->createMock(LoggerInterface::class)
		);

	}//end setUp()

	/**
	 * A job entity.
	 *
	 * @param array<string, mixed> $data The data.
	 * @param string               $uuid The uuid.
	 *
	 * @return ObjectEntity The job.
	 */
	private function job(array $data, string $uuid = 'job-1'): ObjectEntity {
		$entity = new ObjectEntity();
		$entity->setUuid($uuid);
		$entity->setObject($data);
		return $entity;

	}//end job()

	/**
	 * A queued ROD export owned by learniq.
	 *
	 * @param array<string, mixed> $overrides Field overrides.
	 *
	 * @return void
	 */
	private function givenJob(array $overrides = []): void {
		$data = array_merge(
			[
				'name' => 'ROD',
				'jobClass' => ExchangeJobAction::class,
				'ownerApp' => 'learniq',
				'ownerRef' => 'school-advies/1',
				'exchangeTarget' => 'bron-rod',
				'exchangeDirection' => 'export',
				'exchangeScope' => ['berichtsoort' => 'inschrijving'],
				'exchangeStatus' => 'queued',
			],
			$overrides
		);
		$this->jobs->method('findJob')->willReturn($this->job(data: $data));

	}//end givenJob()

	/**
	 * A gate decision.
	 *
	 * @param string                           $decision allow or refuse.
	 * @param array<int, array<string, mixed>> $records  The records on allow.
	 *
	 * @return array<string, mixed> The decision.
	 */
	private function decision(string $decision, array $records = []): array {
		return [
			'decision' => $decision,
			'code' => ($decision === 'refuse') ? 'teldatum-unconfirmed' : '',
			'reason' => ($decision === 'refuse') ? 'Not confirmed.' : '',
			'checkedAt' => '2026-10-01T08:00:00+02:00',
			'records' => $records,
		];

	}//end decision()

	/**
	 * The last saved job state.
	 *
	 * @return array<string, mixed> The data.
	 */
	private function lastSave(): array {
		return $this->saves[(count($this->saves) - 1)];

	}//end lastSave()

	/**
	 * The owning app allows: records are mapped, dispatched, and the job succeeds.
	 *
	 * @return void
	 */
	public function testTheOwningAppAllowsTheJob(): void {
		$this->givenJob(['exchangeMapping' => 'learniq-bron-rod-export-learner']);
		$this->dispatcher->method('supports')->willReturn(true);
		$this->jobs->method('findMapping')->willReturn($this->job(data: ['mapping' => ['voornamen' => 'givenName']], uuid: 'm-1'));
		$this->gate->method('ask')->willReturn(
			$this->decision('allow', [['recordId' => 'a', 'sourceKind' => 'learner-profile', 'data' => ['givenName' => 'Sanne']]])
		);
		$this->mappings->method('executeMapping')->willReturn(['voornamen' => 'Sanne']);
		$this->dispatcher->expects($this->once())->method('dispatch')->willReturnCallback(
			function (string $jobId, string $target, string $direction, array $scope, array $records): array {
				$this->assertSame('job-1', $jobId);
				$this->assertSame(['voornamen' => 'Sanne'], $records[0]['data'], 'The handler receives the mapped record.');
				return ['accepted' => ['a'], 'rejected' => [], 'refusal' => null];
			}
		);

		$log = $this->runner->run('job-1');

		$this->assertSame('SUCCESS', $log['level']);
		$this->assertSame('running', $this->saves[0]['exchangeStatus']);
		$final = $this->lastSave();
		$this->assertSame('succeeded', $final['exchangeStatus']);
		$this->assertSame('allow', $final['gateDecision']['decision']);
		$this->assertArrayNotHasKey('records', $final['gateDecision'], 'Records are never stored on the job.');
		$this->assertSame(1, $final['exchangeResult']['recordsAccepted']);
		$this->assertCount(1, $this->concluded);
		$this->assertSame('succeeded', $this->concluded[0]->getStatus());

	}//end testTheOwningAppAllowsTheJob()

	/**
	 * The owning app refuses: nothing reaches the handler, the job is refused.
	 *
	 * @return void
	 */
	public function testTheOwningAppRefuses(): void {
		$this->givenJob();
		$this->dispatcher->method('supports')->willReturn(true);
		$this->gate->method('ask')->willReturn($this->decision('refuse'));
		$this->dispatcher->expects($this->never())->method('dispatch');

		$log = $this->runner->run('job-1');

		$this->assertSame('WARNING', $log['level']);
		$this->assertSame('refused', $this->lastSave()['exchangeStatus']);
		$this->assertSame('teldatum-unconfirmed', $this->lastSave()['gateDecision']['code']);
		$this->assertSame('refused', $this->concluded[0]->getStatus());
		$this->assertSame('teldatum-unconfirmed', $this->concluded[0]->getGateDecision()['code']);

	}//end testTheOwningAppRefuses()

	/**
	 * A target without an adapter fails before the gate is asked.
	 *
	 * @return void
	 */
	public function testATargetWithoutAnAdapter(): void {
		$this->givenJob(['exchangeTarget' => 'surfconext', 'exchangeDirection' => 'sync']);
		$this->dispatcher->method('supports')->willReturn(false);
		$this->gate->expects($this->never())->method('ask');

		$log = $this->runner->run('job-1');

		$this->assertSame('ERROR', $log['level']);
		$this->assertSame('failed', $this->lastSave()['exchangeStatus']);
		$this->assertStringStartsWith('no-handler', $this->lastSave()['exchangeError']);

	}//end testATargetWithoutAnAdapter()

	/**
	 * The mapping slug does not exist: failed before the gate.
	 *
	 * @return void
	 */
	public function testTheMappingSlugDoesNotExist(): void {
		$this->givenJob(['exchangeMapping' => 'learniq-nonexistent']);
		$this->dispatcher->method('supports')->willReturn(true);
		$this->jobs->method('findMapping')->willReturn(null);
		$this->gate->expects($this->never())->method('ask');

		$this->runner->run('job-1');

		$this->assertSame('failed', $this->lastSave()['exchangeStatus']);
		$this->assertStringStartsWith('mapping-missing', $this->lastSave()['exchangeError']);

	}//end testTheMappingSlugDoesNotExist()

	/**
	 * One record fails mapping and one fails at the adapter: partial, two rejections.
	 *
	 * @return void
	 */
	public function testAPartialRunRecordsItsRejections(): void {
		$this->givenJob(['exchangeMapping' => 'learniq-leerplicht-export-melding', 'exchangeTarget' => 'leerplicht']);
		$this->dispatcher->method('supports')->willReturn(true);
		$this->jobs->method('findMapping')->willReturn($this->job(data: ['mapping' => []], uuid: 'm-2'));
		$this->gate->method('ask')->willReturn(
			$this->decision(
				'allow',
				[
					['recordId' => 'a', 'sourceKind' => 'attendance-flag', 'data' => ['x' => 1]],
					['recordId' => 'b', 'sourceKind' => 'attendance-flag', 'data' => ['x' => 2]],
					['recordId' => 'c', 'sourceKind' => 'attendance-flag', 'data' => ['x' => 3]],
				]
			)
		);
		$this->mappings->method('executeMapping')->willReturnCallback(
			static function ($mapping, array $input): array {
				if ($input['x'] === 2) {
					throw new RuntimeException('twig');
				}

				return $input;
			}
		);
		$this->dispatcher->method('dispatch')->willReturn(
			['accepted' => ['a'], 'rejected' => [['recordId' => 'c', 'sourceKind' => 'attendance-flag', 'errorCode' => 'translation-failed', 'offendingFields' => ['bsn']]], 'refusal' => null]
		);
		$codes = [];
		$this->rejections->expects($this->exactly(2))->method('record')->willReturnCallback(
			function (string $jobId, string $target, array $rejection, string $ownerApp) use (&$codes): ObjectEntity {
				$codes[] = $rejection['errorCode'];
				$this->assertSame('learniq', $ownerApp);
				return new ObjectEntity();
			}
		);

		$log = $this->runner->run('job-1');

		$this->assertSame('WARNING', $log['level']);
		$this->assertSame('partial', $this->lastSave()['exchangeStatus']);
		$this->assertSame(['mapping-failed', 'translation-failed'], $codes);
		$this->assertSame(3, $this->lastSave()['exchangeResult']['recordsProcessed']);
		$this->assertSame(2, $this->lastSave()['exchangeResult']['recordsRejected']);

	}//end testAPartialRunRecordsItsRejections()

	/**
	 * A resubmission sends only its record and reopens its rejection on failure.
	 *
	 * @return void
	 */
	public function testAResubmissionReopensItsRejection(): void {
		$this->givenJob(['exchangeScope' => ['recordIds' => ['b']], 'resubmissionOf' => 'rej-1']);
		$this->dispatcher->method('supports')->willReturn(true);
		$this->gate->method('ask')->willReturn(
			$this->decision('allow', [['recordId' => 'a', 'data' => []], ['recordId' => 'b', 'data' => []]])
		);
		$this->dispatcher->method('dispatch')->willReturnCallback(
			function (string $jobId, string $target, string $direction, array $scope, array $records): array {
				$this->assertSame(['b'], array_column($records, 'recordId'));
				return ['accepted' => [], 'rejected' => [['recordId' => 'b', 'errorCode' => 'BRON-101']], 'refusal' => null];
			}
		);
		$this->rejections->expects($this->never())->method('record');
		$this->rejections->expects($this->once())->method('reopen')->with('rej-1', $this->anything());

		$this->runner->run('job-1');

		$this->assertSame('failed', $this->lastSave()['exchangeStatus']);

	}//end testAResubmissionReopensItsRejection()

	/**
	 * A job that is not queued, not found, or not an exchange job does not run.
	 *
	 * @return void
	 */
	public function testOnlyAQueuedExchangeJobRuns(): void {
		$this->givenJob(['exchangeStatus' => 'running']);
		$this->gate->expects($this->never())->method('ask');

		$this->assertSame('WARNING', $this->runner->run('job-1')['level']);
		$this->assertSame([], $this->saves);
		$this->assertSame([], $this->concluded);

	}//end testOnlyAQueuedExchangeJobRuns()

	/**
	 * The action passes the job id from JobService's `_jobId`.
	 *
	 * @return void
	 */
	public function testTheActionReadsTheJobId(): void {
		$runner = $this->createMock(ExchangeJobRunner::class);
		$runner->expects($this->once())->method('run')->with('job-7')->willReturn(['level' => 'SUCCESS', 'message' => 'ok']);

		$action = new ExchangeJobAction($runner);

		$this->assertSame('SUCCESS', $action->run(['_jobId' => 'job-7', '_executionTrace' => null])['level']);
		$this->assertSame('ERROR', (new ExchangeJobAction($runner))->run([])['level']);

	}//end testTheActionReadsTheJobId()
}//end class

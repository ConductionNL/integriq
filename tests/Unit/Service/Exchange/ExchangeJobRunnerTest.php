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

use OCA\Integriq\Event\ExchangeJobConcludedEvent;
use OCA\Integriq\Service\Exchange\ExchangeGateClient;
use OCA\Integriq\Service\Exchange\ExchangeHandOff;
use OCA\Integriq\Service\Exchange\ExchangeJobRunner;
use OCA\Integriq\Service\Exchange\ExchangeJobService;
use OCA\Integriq\Service\Exchange\ExchangeRejectionService;
use OCA\Integriq\Service\Exchange\ExchangeTargetDispatcher;
use OCA\Integriq\Service\Job\ExchangeJobAction;
use OCA\Integriq\Service\MappingService;
use OCA\OpenRegister\Db\ObjectEntity;
use OCP\EventDispatcher\IEventDispatcher;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * REQ-003, REQ-004, REQ-005, REQ-006 and REQ-009 scenarios.
 */
class ExchangeJobRunnerTest extends TestCase {

	/**
	 * The golden snapshots, by scenario, taken before the runner was split into steps.
	 *
	 * @var array<string, string>
	 */
	private const GOLDEN = [
		'not-found' => '{"log":{"level":"ERROR","message":"Exchange job \"job-1\" was not found."},"saves":[],"logs":[],"rejections":[],"events":[]}',
		'not-an-exchange-job' => '{"log":{"level":"WARNING","message":"This job is not an exchange job; nothing to do."},"saves":[],"logs":[],"rejections":[],"events":[]}',
		'not-queued' => '{"log":{"level":"WARNING","message":"Exchange job is succeeded, not queued; it did not run again."},"saves":[],"logs":[],"rejections":[],"events":[]}',
		'no-handler' => '{"log":{"level":"ERROR","message":"no-handler: No handler for bron-rod export."},"saves":[{"ownerApp":"learniq","ownerRef":"r\/1","exchangeTarget":"bron-rod","exchangeDirection":"export","exchangeScope":{"berichtsoort":"inschrijving"},"exchangeStatus":"failed","exchangeError":"no-handler: No handler for bron-rod export.","exchangeResult":{"recordsProcessed":0,"recordsAccepted":0,"recordsRejected":0,"runId":"","artefactRef":null},"finishedAt":true,"savedAs":"job-1"}],"logs":[],"rejections":[],"events":[["learniq","job-1","bron-rod","export","r\/1","failed",{"recordsProcessed":0,"recordsAccepted":0,"recordsRejected":0,"runId":"","artefactRef":null},null,"no-handler: No handler for bron-rod export."]]}',
		'mapping-missing' => '{"log":{"level":"ERROR","message":"mapping-missing: No mapping \"missing\"."},"saves":[{"ownerApp":"learniq","ownerRef":"r\/1","exchangeTarget":"bron-rod","exchangeDirection":"export","exchangeScope":{"berichtsoort":"inschrijving"},"exchangeStatus":"failed","exchangeMapping":"missing","exchangeError":"mapping-missing: No mapping \"missing\".","exchangeResult":{"recordsProcessed":0,"recordsAccepted":0,"recordsRejected":0,"runId":"","artefactRef":null},"finishedAt":true,"savedAs":"job-1"}],"logs":[],"rejections":[],"events":[["learniq","job-1","bron-rod","export","r\/1","failed",{"recordsProcessed":0,"recordsAccepted":0,"recordsRejected":0,"runId":"","artefactRef":null},null,"mapping-missing: No mapping \"missing\"."]]}',
		'refused' => '{"log":{"level":"WARNING","message":"Refused by learniq: teldatum-unconfirmed Not confirmed."},"saves":[{"ownerApp":"learniq","ownerRef":"r\/1","exchangeTarget":"bron-rod","exchangeDirection":"export","exchangeScope":{"berichtsoort":"inschrijving"},"exchangeStatus":"running","startedAt":true,"savedAs":"job-1"},{"ownerApp":"learniq","ownerRef":"r\/1","exchangeTarget":"bron-rod","exchangeDirection":"export","exchangeScope":{"berichtsoort":"inschrijving"},"exchangeStatus":"refused","startedAt":true,"gateDecision":{"decision":"refuse","code":"teldatum-unconfirmed","reason":"Not confirmed.","checkedAt":"2026-10-01T08:00:00+02:00"},"exchangeResult":{"recordsProcessed":0,"recordsAccepted":0,"recordsRejected":0,"runId":"","artefactRef":null},"finishedAt":true,"savedAs":"job-1"}],"logs":[],"rejections":[],"events":[["learniq","job-1","bron-rod","export","r\/1","refused",{"recordsProcessed":0,"recordsAccepted":0,"recordsRejected":0,"runId":"","artefactRef":null},{"decision":"refuse","code":"teldatum-unconfirmed","reason":"Not confirmed.","checkedAt":"2026-10-01T08:00:00+02:00"},null]]}',
		'mapped-partial' => '{"log":{"level":"WARNING","message":"1 of 3 records accepted by bron-rod."},"saves":[{"ownerApp":"learniq","ownerRef":"r\/1","exchangeTarget":"bron-rod","exchangeDirection":"export","exchangeScope":{"berichtsoort":"inschrijving"},"exchangeStatus":"running","exchangeMapping":"present","startedAt":true,"savedAs":"job-1"},{"ownerApp":"learniq","ownerRef":"r\/1","exchangeTarget":"bron-rod","exchangeDirection":"export","exchangeScope":{"berichtsoort":"inschrijving"},"exchangeStatus":"partial","exchangeMapping":"present","startedAt":true,"gateDecision":{"decision":"allow","code":"","reason":"","checkedAt":"2026-10-01T08:00:00+02:00"},"exchangeResult":{"recordsProcessed":3,"recordsAccepted":1,"recordsRejected":2,"runId":"","artefactRef":null},"finishedAt":true,"savedAs":"job-1"}],"logs":[["info","[ExchangeJobRunner] mapping failed for a record of job job-1: RuntimeException",[]]],"rejections":[["record","job-1","bron-rod",{"recordId":"b","sourceKind":"k","errorCode":"mapping-failed","offendingFields":[]},"learniq"],["record","job-1","bron-rod",{"recordId":"c","errorCode":"adapter-said-no"},"learniq"]],"events":[["learniq","job-1","bron-rod","export","r\/1","partial",{"recordsProcessed":3,"recordsAccepted":1,"recordsRejected":2,"runId":"","artefactRef":null},{"decision":"allow","code":"","reason":"","checkedAt":"2026-10-01T08:00:00+02:00"},null]],"handOff":{"jobId":"job-1","target":"bron-rod","direction":"export","scope":{"berichtsoort":"inschrijving"},"records":[{"recordId":"a","sourceKind":"k","data":{"mapped":{"x":1}}},{"recordId":"c","sourceKind":"k","data":{"mapped":[]}}],"ownerApp":"learniq","ownerRef":"r\/1"}}',
		'no-owner-answer' => '{"log":{"level":"ERROR","message":"no-owner-answer: The owning app did not take the received records."},"saves":[{"ownerApp":"learniq","ownerRef":"r\/1","exchangeTarget":"bron-rod","exchangeDirection":"export","exchangeScope":{"berichtsoort":"inschrijving"},"exchangeStatus":"running","startedAt":true,"savedAs":"job-1"},{"ownerApp":"learniq","ownerRef":"r\/1","exchangeTarget":"bron-rod","exchangeDirection":"export","exchangeScope":{"berichtsoort":"inschrijving"},"exchangeStatus":"failed","startedAt":true,"gateDecision":{"decision":"allow","code":"","reason":"","checkedAt":"2026-10-01T08:00:00+02:00"},"exchangeError":"no-owner-answer: The owning app did not take the received records.","exchangeResult":{"recordsProcessed":0,"recordsAccepted":0,"recordsRejected":0,"runId":"","artefactRef":null},"finishedAt":true,"savedAs":"job-1"}],"logs":[],"rejections":[],"events":[["learniq","job-1","bron-rod","export","r\/1","failed",{"recordsProcessed":0,"recordsAccepted":0,"recordsRejected":0,"runId":"","artefactRef":null},{"decision":"allow","code":"","reason":"","checkedAt":"2026-10-01T08:00:00+02:00"},"no-owner-answer: The owning app did not take the received records."]],"handOff":{"jobId":"job-1","target":"bron-rod","direction":"export","scope":{"berichtsoort":"inschrijving"},"records":[{"recordId":"a","sourceKind":"k","data":{"x":1}},{"recordId":"b","sourceKind":"k","data":{"x":2}},{"recordId":"c","sourceKind":"k","data":[]}],"ownerApp":"learniq","ownerRef":"r\/1"}}',
		'target-refusal' => '{"log":{"level":"ERROR","message":"unsupported: The target cannot run this job."},"saves":[{"ownerApp":"learniq","ownerRef":"r\/1","exchangeTarget":"bron-rod","exchangeDirection":"export","exchangeScope":{"berichtsoort":"inschrijving"},"exchangeStatus":"running","startedAt":true,"savedAs":"job-1"},{"ownerApp":"learniq","ownerRef":"r\/1","exchangeTarget":"bron-rod","exchangeDirection":"export","exchangeScope":{"berichtsoort":"inschrijving"},"exchangeStatus":"failed","startedAt":true,"gateDecision":{"decision":"allow","code":"","reason":"","checkedAt":"2026-10-01T08:00:00+02:00"},"exchangeError":"unsupported: The target cannot run this job.","exchangeResult":{"recordsProcessed":0,"recordsAccepted":0,"recordsRejected":0,"runId":"","artefactRef":null},"finishedAt":true,"savedAs":"job-1"}],"logs":[],"rejections":[],"events":[["learniq","job-1","bron-rod","export","r\/1","failed",{"recordsProcessed":0,"recordsAccepted":0,"recordsRejected":0,"runId":"","artefactRef":null},{"decision":"allow","code":"","reason":"","checkedAt":"2026-10-01T08:00:00+02:00"},"unsupported: The target cannot run this job."]],"handOff":{"jobId":"job-1","target":"bron-rod","direction":"export","scope":{"berichtsoort":"inschrijving"},"records":[{"recordId":"a","sourceKind":"k","data":{"x":1}},{"recordId":"b","sourceKind":"k","data":{"x":2}},{"recordId":"c","sourceKind":"k","data":[]}],"ownerApp":"learniq","ownerRef":"r\/1"}}',
		'counted-success' => '{"log":{"level":"SUCCESS","message":"3 of 3 records accepted by bron-rod."},"saves":[{"ownerApp":"learniq","ownerRef":"r\/1","exchangeTarget":"bron-rod","exchangeDirection":"export","exchangeScope":{"berichtsoort":"inschrijving"},"exchangeStatus":"running","startedAt":true,"savedAs":"job-1"},{"ownerApp":"learniq","ownerRef":"r\/1","exchangeTarget":"bron-rod","exchangeDirection":"export","exchangeScope":{"berichtsoort":"inschrijving"},"exchangeStatus":"succeeded","startedAt":true,"gateDecision":{"decision":"allow","code":"","reason":"","checkedAt":"2026-10-01T08:00:00+02:00"},"exchangeResult":{"recordsProcessed":3,"recordsAccepted":3,"recordsRejected":0,"runId":"","artefactRef":null},"finishedAt":true,"savedAs":"job-1"}],"logs":[],"rejections":[],"events":[["learniq","job-1","bron-rod","export","r\/1","succeeded",{"recordsProcessed":3,"recordsAccepted":3,"recordsRejected":0,"runId":"","artefactRef":null},{"decision":"allow","code":"","reason":"","checkedAt":"2026-10-01T08:00:00+02:00"},null]],"handOff":{"jobId":"job-1","target":"bron-rod","direction":"export","scope":{"berichtsoort":"inschrijving"},"records":[{"recordId":"a","sourceKind":"k","data":{"x":1}},{"recordId":"b","sourceKind":"k","data":{"x":2}},{"recordId":"c","sourceKind":"k","data":[]}],"ownerApp":"learniq","ownerRef":"r\/1"}}',
		'none-accepted' => '{"log":{"level":"ERROR","message":"0 of 3 records accepted by bron-rod."},"saves":[{"ownerApp":"learniq","ownerRef":"r\/1","exchangeTarget":"bron-rod","exchangeDirection":"export","exchangeScope":"not-an-array","exchangeStatus":"running","startedAt":true,"savedAs":"job-1"},{"ownerApp":"learniq","ownerRef":"r\/1","exchangeTarget":"bron-rod","exchangeDirection":"export","exchangeScope":"not-an-array","exchangeStatus":"failed","startedAt":true,"gateDecision":{"decision":"allow","code":"","reason":"","checkedAt":"2026-10-01T08:00:00+02:00"},"exchangeResult":{"recordsProcessed":3,"recordsAccepted":0,"recordsRejected":3,"runId":"","artefactRef":null},"finishedAt":true,"savedAs":"job-1"}],"logs":[],"rejections":[],"events":[["learniq","job-1","bron-rod","export","r\/1","failed",{"recordsProcessed":3,"recordsAccepted":0,"recordsRejected":3,"runId":"","artefactRef":null},{"decision":"allow","code":"","reason":"","checkedAt":"2026-10-01T08:00:00+02:00"},null]],"handOff":{"jobId":"job-1","target":"bron-rod","direction":"export","scope":[],"records":[{"recordId":"a","sourceKind":"k","data":{"x":1}},{"recordId":"b","sourceKind":"k","data":{"x":2}},{"recordId":"c","sourceKind":"k","data":[]}],"ownerApp":"learniq","ownerRef":"r\/1"}}',
		'resubmission' => '{"log":{"level":"SUCCESS","message":"1 of 1 records accepted by bron-rod."},"saves":[{"ownerApp":"learniq","ownerRef":"r\/1","exchangeTarget":"bron-rod","exchangeDirection":"export","exchangeScope":{"recordIds":["c"]},"exchangeStatus":"running","resubmissionOf":"rej-9","startedAt":true,"savedAs":"job-1"},{"ownerApp":"learniq","ownerRef":"r\/1","exchangeTarget":"bron-rod","exchangeDirection":"export","exchangeScope":{"recordIds":["c"]},"exchangeStatus":"succeeded","resubmissionOf":"rej-9","startedAt":true,"gateDecision":{"decision":"allow","code":"","reason":"","checkedAt":"2026-10-01T08:00:00+02:00"},"exchangeResult":{"recordsProcessed":1,"recordsAccepted":1,"recordsRejected":0,"runId":"","artefactRef":null},"finishedAt":true,"savedAs":"job-1"}],"logs":[],"rejections":[["reopen","rej-9",{"recordId":"c","errorCode":"adapter-said-no"}]],"events":[["learniq","job-1","bron-rod","export","r\/1","succeeded",{"recordsProcessed":1,"recordsAccepted":1,"recordsRejected":0,"runId":"","artefactRef":null},{"decision":"allow","code":"","reason":"","checkedAt":"2026-10-01T08:00:00+02:00"},null]],"handOff":{"jobId":"job-1","target":"bron-rod","direction":"export","scope":{"recordIds":["c"]},"records":[{"recordId":"c","sourceKind":"k","data":[]}],"ownerApp":"learniq","ownerRef":"r\/1"}}',
		'listener-throws' => '{"log":{"level":"WARNING","message":"1 of 3 records accepted by bron-rod."},"saves":[{"ownerApp":"learniq","ownerRef":"r\/1","exchangeTarget":"bron-rod","exchangeDirection":"export","exchangeScope":{"berichtsoort":"inschrijving"},"exchangeStatus":"running","startedAt":true,"savedAs":"job-1"},{"ownerApp":"learniq","ownerRef":"r\/1","exchangeTarget":"bron-rod","exchangeDirection":"export","exchangeScope":{"berichtsoort":"inschrijving"},"exchangeStatus":"partial","startedAt":true,"gateDecision":{"decision":"allow","code":"","reason":"","checkedAt":"2026-10-01T08:00:00+02:00"},"exchangeResult":{"recordsProcessed":3,"recordsAccepted":1,"recordsRejected":2,"runId":"","artefactRef":null},"finishedAt":true,"savedAs":"job-1"}],"logs":[["warning","[ExchangeJobRunner] a concluded listener failed: listener broke",[]]],"rejections":[["record","job-1","bron-rod",{"recordId":"c","errorCode":"adapter-said-no"},"learniq"]],"events":[["learniq","job-1","bron-rod","export","r\/1","partial",{"recordsProcessed":3,"recordsAccepted":1,"recordsRejected":2,"runId":"","artefactRef":null},{"decision":"allow","code":"","reason":"","checkedAt":"2026-10-01T08:00:00+02:00"},null]],"handOff":{"jobId":"job-1","target":"bron-rod","direction":"export","scope":{"berichtsoort":"inschrijving"},"records":[{"recordId":"a","sourceKind":"k","data":{"x":1}},{"recordId":"b","sourceKind":"k","data":{"x":2}},{"recordId":"c","sourceKind":"k","data":[]}],"ownerApp":"learniq","ownerRef":"r\/1"}}',
		'rejection-not-stored' => '{"log":{"level":"WARNING","message":"1 of 3 records accepted by bron-rod."},"saves":[{"ownerApp":"learniq","ownerRef":"r\/1","exchangeTarget":"bron-rod","exchangeDirection":"export","exchangeScope":{"berichtsoort":"inschrijving"},"exchangeStatus":"running","startedAt":true,"savedAs":"job-1"},{"ownerApp":"learniq","ownerRef":"r\/1","exchangeTarget":"bron-rod","exchangeDirection":"export","exchangeScope":{"berichtsoort":"inschrijving"},"exchangeStatus":"partial","startedAt":true,"gateDecision":{"decision":"allow","code":"","reason":"","checkedAt":"2026-10-01T08:00:00+02:00"},"exchangeResult":{"recordsProcessed":3,"recordsAccepted":1,"recordsRejected":2,"runId":"","artefactRef":null},"finishedAt":true,"savedAs":"job-1"}],"logs":[["warning","[ExchangeJobRunner] a rejection of job job-1 was not stored: store down",[]]],"rejections":[],"events":[["learniq","job-1","bron-rod","export","r\/1","partial",{"recordsProcessed":3,"recordsAccepted":1,"recordsRejected":2,"runId":"","artefactRef":null},{"decision":"allow","code":"","reason":"","checkedAt":"2026-10-01T08:00:00+02:00"},null]],"handOff":{"jobId":"job-1","target":"bron-rod","direction":"export","scope":{"berichtsoort":"inschrijving"},"records":[{"recordId":"a","sourceKind":"k","data":{"x":1}},{"recordId":"b","sourceKind":"k","data":{"x":2}},{"recordId":"c","sourceKind":"k","data":[]}],"ownerApp":"learniq","ownerRef":"r\/1"}}',
	];

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
			function (ExchangeHandOff $handOff): array {
				$this->assertSame('job-1', $handOff->jobId);
				$this->assertSame(['voornamen' => 'Sanne'], $handOff->records[0]['data'], 'The handler receives the mapped record.');
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
			function (ExchangeHandOff $handOff): array {
				$this->assertSame(['b'], array_column($handOff->records, 'recordId'));
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

	/**
	 * GOLDEN: every path through one run, pinned as the returned log entry, every
	 * saved job state (start and finish times only as present or absent), every
	 * concluded event, every stored or reopened rejection and every log line.
	 *
	 * @param string $scenario The scenario name.
	 * @param string $expected The JSON snapshot taken on the code before the split.
	 *
	 * @return void
	 */
	#[DataProvider('goldenScenarios')]
	public function testGoldenRunPinsEveryPath(string $scenario, string $expected): void {
		$this->assertSame($expected, json_encode($this->runScenario(scenario: $scenario)));

	}//end testGoldenRunPinsEveryPath()

	/**
	 * The golden scenarios and their snapshots.
	 *
	 * @return array<string, array{0: string, 1: string}>
	 */
	public static function goldenScenarios(): array {
		$snapshots = [];
		foreach (self::GOLDEN as $scenario => $expected) {
			$snapshots[$scenario] = [$scenario, $expected];
		}

		return $snapshots;

	}//end goldenScenarios()

	/**
	 * Run one golden scenario against a runner whose logger, rejection store and
	 * event dispatcher record what they receive.
	 *
	 * @param string $scenario The scenario name.
	 *
	 * @return array<string, mixed> What the run returned and caused.
	 */
	private function runScenario(string $scenario): array {
		$seen = ['logs' => [], 'rejections' => [], 'events' => []];
		$data = [
			'ownerApp' => 'learniq',
			'ownerRef' => 'r/1',
			'exchangeTarget' => 'bron-rod',
			'exchangeDirection' => 'export',
			'exchangeScope' => ['berichtsoort' => 'inschrijving'],
			'exchangeStatus' => 'queued',
		];
		$records = [
			['recordId' => 'a', 'sourceKind' => 'k', 'data' => ['x' => 1]],
			['recordId' => 'b', 'sourceKind' => 'k', 'data' => ['x' => 2]],
			['recordId' => 'c', 'sourceKind' => 'k', 'data' => 'not-an-array'],
		];
		$outcome = ['accepted' => ['a'], 'rejected' => [['recordId' => 'c', 'errorCode' => 'adapter-said-no']], 'refusal' => null];
		$supports = true;
		$gate = 'allow';
		$listenerThrows = false;
		$recordThrows = false;

		switch ($scenario) {
			case 'not-found':
				$data = null;
				break;
			case 'not-an-exchange-job':
				$data['exchangeTarget'] = '';
				break;
			case 'not-queued':
				$data['exchangeStatus'] = 'succeeded';
				break;
			case 'no-handler':
				$supports = false;
				break;
			case 'mapping-missing':
				$data['exchangeMapping'] = 'missing';
				break;
			case 'refused':
				$gate = 'refuse';
				break;
			case 'mapped-partial':
				$data['exchangeMapping'] = 'present';
				break;
			case 'no-owner-answer':
				$outcome['refusal'] = ExchangeTargetDispatcher::CODE_NO_OWNER_ANSWER;
				break;
			case 'target-refusal':
				$outcome['refusal'] = 'unsupported';
				break;
			case 'counted-success':
				$outcome = ['accepted' => [], 'acceptedCount' => 3, 'rejected' => [], 'refusal' => null];
				break;
			case 'none-accepted':
				$outcome = ['accepted' => [], 'rejected' => [], 'refusal' => null];
				$data['exchangeScope'] = 'not-an-array';
				break;
			case 'resubmission':
				$data['exchangeScope'] = ['recordIds' => ['c']];
				$data['resubmissionOf'] = 'rej-9';
				break;
			case 'listener-throws':
				$listenerThrows = true;
				break;
			case 'rejection-not-stored':
				$recordThrows = true;
				break;
		}//end switch

		$jobs = $this->createMock(ExchangeJobService::class);
		$jobs->method('findJob')->willReturn($data === null ? null : $this->job(data: $data));
		$jobs->method('saveJob')->willReturnCallback(
			function (array $saved, ?string $uuid = null): ObjectEntity {
				foreach (['startedAt', 'finishedAt'] as $time) {
					if (array_key_exists($time, $saved) === true) {
						$saved[$time] = is_string($saved[$time]) && $saved[$time] !== '';
					}
				}

				$this->saves[] = $saved + ['savedAs' => $uuid];
				return $this->job(data: $saved, uuid: (string)$uuid);
			}
		);
		$jobs->method('findMapping')->willReturnCallback(
			fn (string $slug): ?ObjectEntity => $slug === 'present' ? $this->job(data: ['mapping' => []], uuid: 'm-1') : null
		);

		$gateClient = $this->createMock(ExchangeGateClient::class);
		$gateClient->method('ask')->willReturn($this->decision($gate, $records));

		$dispatcher = $this->createMock(ExchangeTargetDispatcher::class);
		$dispatcher->method('supports')->willReturn($supports);
		$dispatcher->method('dispatch')->willReturnCallback(
			function (ExchangeHandOff $handOff) use ($outcome, &$seen): array {
				$seen['handOff'] = get_object_vars($handOff);
				return $outcome;
			}
		);

		$rejections = $this->createMock(ExchangeRejectionService::class);
		$rejections->method('record')->willReturnCallback(
			function (string $jobId, string $target, array $rejection, string $ownerApp) use (&$seen, $recordThrows): ObjectEntity {
				if ($recordThrows === true) {
					throw new RuntimeException('store down');
				}

				$seen['rejections'][] = ['record', $jobId, $target, $rejection, $ownerApp];
				return new ObjectEntity();
			}
		);
		$rejections->method('reopen')->willReturnCallback(
			function (string $rejectionId, array $rejection) use (&$seen): ObjectEntity {
				$seen['rejections'][] = ['reopen', $rejectionId, $rejection];
				return new ObjectEntity();
			}
		);

		$mappings = $this->createMock(MappingService::class);
		$mappings->method('executeMapping')->willReturnCallback(
			static function ($mapping, array $input): array {
				if (($input['x'] ?? 0) === 2) {
					throw new RuntimeException('bsn 123456782 in the message');
				}

				return ['mapped' => $input];
			}
		);

		$events = $this->createMock(IEventDispatcher::class);
		$events->method('dispatchTyped')->willReturnCallback(
			function ($event) use (&$seen, $listenerThrows): void {
				$seen['events'][] = [
					$event->getOwnerApp(),
					$event->getJobId(),
					$event->getTarget(),
					$event->getDirection(),
					$event->getOwnerRef(),
					$event->getStatus(),
					$event->getResult(),
					$event->getGateDecision(),
					$event->getErrorMessage(),
				];
				if ($listenerThrows === true) {
					throw new RuntimeException('listener broke');
				}
			}
		);

		$logger = $this->createMock(LoggerInterface::class);
		foreach (['info', 'warning', 'error'] as $level) {
			$logger->method($level)->willReturnCallback(
				function (string $message, array $context = []) use (&$seen, $level): void {
					$seen['logs'][] = [$level, $message, $context];
				}
			);
		}

		$runner = new ExchangeJobRunner($jobs, $gateClient, $dispatcher, $rejections, $mappings, $events, $logger);
		$log = $runner->run('job-1');

		return ['log' => $log, 'saves' => $this->saves] + $seen;

	}//end runScenario()

}//end class

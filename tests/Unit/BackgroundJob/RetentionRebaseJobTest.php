<?php

/**
 * Unit tests for the retention rebase over existing log objects.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\BackgroundJob
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://www.integriq.nl
 *
 * @spec openspec/changes/platform-admin-defaults/specs/logs-and-statistics/spec.md#requirement-changed-retention-can-be-applied-to-existing-logs-req-adef-003
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\BackgroundJob;

use DateTime;
use OCA\Integriq\BackgroundJob\RetentionRebaseJob;
use OCA\Integriq\Tests\Helpers\ObjectServiceMockBuilder;
use OCA\OpenRegister\Service\ObjectService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\IJobList;
use OCP\IAppConfig;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use ReflectionMethod;
use RuntimeException;

/**
 * Pages, expiries, the success rule, the next run and the progress.
 *
 * @covers \OCA\Integriq\BackgroundJob\RetentionRebaseJob
 * @uses   \OCA\Integriq\Service\RetentionSettings
 */
class RetentionRebaseJobTest extends TestCase {

	/**
	 * The in-memory `integriq` app config.
	 *
	 * @var array<string,string>
	 */
	private array $stored = [];

	/**
	 * Every save: schema, uuid and the object.
	 *
	 * @var array<int,array{schema: string, uuid: string, object: array<string,mixed>}>
	 */
	private array $saved = [];

	/**
	 * The pages findAll was asked for: schema and offset.
	 *
	 * @var array<int,array{0: string, 1: int}>
	 */
	private array $pages = [];

	/**
	 * Existing call logs follow a new 90-day retention: each error log
	 * expires 90 days after its creation, a successful one after the success
	 * retention, and the run ends as done.
	 *
	 * @return void
	 */
	public function testExistingLogsFollowTheNewRetention(): void {
		$this->stored['retention'] = '{"callLogRetention":7776000000,"successLogRetention":3600000}';
		$rows = [
			ObjectServiceMockBuilder::objectEntity($this, ['created' => '2026-01-01T00:00:00+00:00', 'statusCode' => 500, 'expires' => '2026-01-31T00:00:00+00:00'], 'err-1'),
			ObjectServiceMockBuilder::objectEntity($this, ['created' => '2026-01-01T00:00:00+00:00', 'statusCode' => 200], 'ok-1'),
			ObjectServiceMockBuilder::objectEntity($this, ['statusCode' => 500], 'no-created'),
		];
		$jobList = $this->createMock(IJobList::class);
		$jobList->expects($this->never())->method('add');

		$this->runJob(job: $this->job(pages: ['call_log' => [$rows]], jobList: $jobList), argument: ['kinds' => ['callLog'], 'kind' => 0, 'offset' => 0]);

		$this->assertSame([['call_log', 0]], $this->pages);
		$this->assertSame(
			[
				['schema' => 'call_log', 'uuid' => 'err-1', 'expires' => '2026-04-01T00:00:00+00:00'],
				['schema' => 'call_log', 'uuid' => 'ok-1', 'expires' => '2026-01-01T01:00:00+00:00'],
			],
			array_map(static fn (array $save): array => ['schema' => $save['schema'], 'uuid' => $save['uuid'], 'expires' => $save['object']['expires']], $this->saved)
		);
		$progress = json_decode($this->stored[RetentionRebaseJob::PROGRESS_KEY], true);
		$this->assertSame('done', $progress['status']);
		$this->assertSame(3, $progress['processed']);
		$this->assertSame(2, $progress['updated']);
		$this->assertSame('2026-11-14T10:00:00+00:00', $progress['finishedAt']);
	}//end testExistingLogsFollowTheNewRetention()

	/**
	 * A log whose expiry already matches is not saved again.
	 *
	 * @return void
	 */
	public function testALogAlreadyOnTheRetentionIsLeftAlone(): void {
		$rows = [ObjectServiceMockBuilder::objectEntity($this, ['created' => '2026-01-01T00:00:00+00:00', 'level' => 'error', 'expires' => '2026-01-31T00:00:00+00:00'], 'job-1')];

		$this->runJob(job: $this->job(pages: ['job_log' => [$rows]], jobList: $this->createMock(IJobList::class)), argument: ['kinds' => ['jobLog']]);

		$this->assertSame([], $this->saved);
		$this->assertSame(0, json_decode($this->stored[RetentionRebaseJob::PROGRESS_KEY], true)['updated']);
	}//end testALogAlreadyOnTheRetentionIsLeftAlone()

	/**
	 * A run stops after its bounded number of pages and queues the next run
	 * at the page it reached.
	 *
	 * @return void
	 */
	public function testARunIsBoundedAndQueuesTheRest(): void {
		$full = array_fill(0, RetentionRebaseJob::PAGE_SIZE, ObjectServiceMockBuilder::objectEntity($this, ['level' => 'error'], 'x'));
		$jobList = $this->createMock(IJobList::class);
		$jobList->expects($this->once())
			->method('add')
			->with(RetentionRebaseJob::class, ['kinds' => ['jobLog'], 'kind' => 0, 'offset' => RetentionRebaseJob::PAGE_SIZE * RetentionRebaseJob::PAGES_PER_RUN]);

		$this->runJob(job: $this->job(pages: ['job_log' => array_fill(0, RetentionRebaseJob::PAGES_PER_RUN, $full)], jobList: $jobList), argument: ['kinds' => ['jobLog']]);

		$this->assertCount(RetentionRebaseJob::PAGES_PER_RUN, $this->pages);
		$progress = json_decode($this->stored[RetentionRebaseJob::PROGRESS_KEY], true);
		$this->assertSame('running', $progress['status']);
		$this->assertSame(RetentionRebaseJob::PAGE_SIZE * RetentionRebaseJob::PAGES_PER_RUN, $progress['processed']);
	}//end testARunIsBoundedAndQueuesTheRest()

	/**
	 * A short page ends a kind and the run moves to the next kind from its start.
	 *
	 * @return void
	 */
	public function testAShortPageMovesToTheNextKind(): void {
		$this->runJob(
			job: $this->job(pages: ['synchronization_log' => [[]], 'synchronization_contract_log' => [[]]], jobList: $this->createMock(IJobList::class)),
			argument: ['kinds' => ['syncLog', 'syncContractLog']]
		);

		$this->assertSame([['synchronization_log', 0], ['synchronization_contract_log', 0]], $this->pages);
		$this->assertSame('done', json_decode($this->stored[RetentionRebaseJob::PROGRESS_KEY], true)['status']);
	}//end testAShortPageMovesToTheNextKind()

	/**
	 * A save that fails is logged and the rest of the page still runs.
	 *
	 * @return void
	 */
	public function testAFailedSaveDoesNotStopThePage(): void {
		$rows = [
			ObjectServiceMockBuilder::objectEntity($this, ['created' => '2026-01-01T00:00:00+00:00', 'statusCode' => 500], 'fails'),
			ObjectServiceMockBuilder::objectEntity($this, ['created' => '2026-01-02T00:00:00+00:00', 'statusCode' => 500], 'saves'),
		];

		$this->runJob(job: $this->job(pages: ['call_log' => [$rows]], jobList: $this->createMock(IJobList::class), failOn: 'fails'), argument: ['kinds' => ['callLog']]);

		$this->assertSame(['saves'], array_column($this->saved, 'uuid'));
		$this->assertSame(1, json_decode($this->stored[RetentionRebaseJob::PROGRESS_KEY], true)['updated']);
	}//end testAFailedSaveDoesNotStopThePage()

	/**
	 * An argument without a kind it knows does nothing at all.
	 *
	 * @return void
	 */
	public function testAnArgumentWithoutAKnownKindDoesNothing(): void {
		$objectService = ObjectServiceMockBuilder::make($this);
		$objectService->expects($this->never())->method('findAll');
		$job = new RetentionRebaseJob($this->clock(), $objectService, $this->config(), $this->createMock(IJobList::class), new NullLogger());

		$this->runJob(job: $job, argument: ['kinds' => ['eventMessage', 7]]);
		$this->runJob(job: $job, argument: 'not an array');

		$this->assertSame([], $this->stored);
	}//end testAnArgumentWithoutAKnownKindDoesNothing()

	/**
	 * The expiry: created plus the retention, null for 0 (keep), false when
	 * created cannot be read.
	 *
	 * @return void
	 */
	public function testTheExpiryOfOneLog(): void {
		$this->assertSame('2026-01-31T00:00:00+00:00', RetentionRebaseJob::expiresFor(created: '2026-01-01T00:00:00+00:00', retentionMs: 2592000000));
		$this->assertNull(RetentionRebaseJob::expiresFor(created: '2026-01-01T00:00:00+00:00', retentionMs: 0));
		$this->assertFalse(RetentionRebaseJob::expiresFor(created: '', retentionMs: 1000));
		$this->assertFalse(RetentionRebaseJob::expiresFor(created: 'not a date', retentionMs: 1000));
	}//end testTheExpiryOfOneLog()

	/**
	 * Which logs count as a success, by each schema's own archival rule.
	 *
	 * @return void
	 */
	public function testTheSuccessRuleOfEachKind(): void {
		$this->assertTrue(RetentionRebaseJob::isSuccess(kind: 'callLog', object: ['statusCode' => 399]));
		$this->assertFalse(RetentionRebaseJob::isSuccess(kind: 'callLog', object: ['statusCode' => 400]));
		$this->assertFalse(RetentionRebaseJob::isSuccess(kind: 'callLog', object: []));
		$this->assertTrue(RetentionRebaseJob::isSuccess(kind: 'jobLog', object: ['level' => 'info']));
		$this->assertFalse(RetentionRebaseJob::isSuccess(kind: 'jobLog', object: ['level' => 'error']));
		$this->assertTrue(RetentionRebaseJob::isSuccess(kind: 'syncLog', object: ['result' => ['errors' => []]]));
		$this->assertFalse(RetentionRebaseJob::isSuccess(kind: 'syncLog', object: ['result' => ['errors' => ['boom']]]));
		$this->assertTrue(RetentionRebaseJob::isSuccess(kind: 'syncContractLog', object: ['targetResult' => 'skipped']));
		$this->assertFalse(RetentionRebaseJob::isSuccess(kind: 'syncContractLog', object: ['targetResult' => 'failed']));
		$this->assertFalse(RetentionRebaseJob::isSuccess(kind: 'eventMessage', object: []));
	}//end testTheSuccessRuleOfEachKind()

	/**
	 * A job over pages of rows per schema.
	 *
	 * @param array<string,array<int,array<int,mixed>>> $pages   Per schema slug, its pages in order.
	 * @param IJobList                                  $jobList The job list double.
	 * @param string                                    $failOn  A uuid whose save throws.
	 *
	 * @return RetentionRebaseJob
	 */
	private function job(array $pages, IJobList $jobList, string $failOn=''): RetentionRebaseJob {
		$objectService = ObjectServiceMockBuilder::make($this);
		$objectService->method('findAll')->willReturnCallback(
			function (array $config) use (&$pages): array {
				$schema = $config['filters']['schema'];
				$this->assertSame('integriq', $config['filters']['register']);
				$this->assertSame(RetentionRebaseJob::PAGE_SIZE, $config['limit']);
				$this->pages[] = [$schema, $config['offset']];
				return ['results' => (array)array_shift($pages[$schema])];
			}
		);
		$objectService->method('saveObject')->willReturnCallback(
			function (array $object, ?string $register=null, ?string $schema=null, ?string $uuid=null) use ($failOn) {
				if ($uuid === $failOn) {
					throw new RuntimeException('locked');
				}

				$this->saved[] = ['schema' => (string)$schema, 'uuid' => (string)$uuid, 'object' => $object];
				return ObjectServiceMockBuilder::objectEntity($this, $object, $uuid);
			}
		);

		return new RetentionRebaseJob($this->clock(), $objectService, $this->config(), $jobList, new NullLogger());
	}//end job()

	/**
	 * Run the protected run() with an argument.
	 *
	 * @param RetentionRebaseJob $job      The job.
	 * @param mixed              $argument The argument.
	 *
	 * @return void
	 */
	private function runJob(RetentionRebaseJob $job, mixed $argument): void {
		(new ReflectionMethod(RetentionRebaseJob::class, 'run'))->invoke($job, $argument);
	}//end runJob()

	/**
	 * A clock fixed at 2026-11-14 10:00 UTC.
	 *
	 * @return ITimeFactory
	 */
	private function clock(): ITimeFactory {
		$clock = $this->createMock(ITimeFactory::class);
		$clock->method('getDateTime')->willReturn(new DateTime('2026-11-14T10:00:00+00:00'));

		return $clock;
	}//end clock()

	/**
	 * The in-memory app config.
	 *
	 * @return IAppConfig&MockObject
	 */
	private function config(): IAppConfig {
		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueString')->willReturnCallback(
			fn (string $app, string $key, string $default=''): string => ($this->stored[$key] ?? $default)
		);
		$config->method('setValueString')->willReturnCallback(
			function (string $app, string $key, string $value): bool {
				$this->stored[$key] = $value;
				return true;
			}
		);

		return $config;
	}//end config()
}//end class
